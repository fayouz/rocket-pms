<?php

namespace App\Cleaning;

use App\Code\AccessCodePlanner;
use App\Lodgify\Booking;
use App\Place\PlaceClient;

/**
 * Cleaning after each departure, delegated to Rocket Place (its cleaning tasks, idempotent by externalRef
 * "booking:<id>:checkout"). Called by AccessCodePlanner::plan for a property linked to a place: for each active stay
 * whose departure has not passed, a task scheduled at the check-out time and due at the next check-in of the property
 * (or one day later when no stay follows). When the dates of the stay change, the task still "todo" is moved (PATCH);
 * when the stay is cancelled, the task is cancelled (status "cancelled"). A task started or done is never touched.
 */
final class CleaningPlanner
{
    public const REF_PREFIX = 'booking:';
    public const REF_SUFFIX = ':checkout';

    public function __construct(private readonly PlaceClient $place)
    {
    }

    public static function refOf(int $bookingId): string
    {
        return self::REF_PREFIX.$bookingId.self::REF_SUFFIX;
    }

    /**
     * @param list<Booking> $bookings every booking of the property (active or not)
     *
     * @return array<string, array<string, mixed>> cleanings of the place indexed by externalRef, after the sync
     */
    public function sync(string $placeId, array $bookings, AccessCodePlanner $planner): array
    {
        $tasks = $this->cleanings($placeId);
        $now = new \DateTimeImmutable();
        $active = array_values(array_filter($bookings, static fn (Booking $b) => $b->isActive()));
        $changed = false;
        foreach ($bookings as $b) {
            $ref = self::refOf($b->id);
            $existing = $tasks[$ref] ?? null;
            if (!$b->isActive()) {
                if (null !== $existing && 'todo' === ($existing['status'] ?? null)) {
                    $this->place->updateCleaning((string) $existing['id'], ['status' => 'cancelled']);
                    $changed = true;
                }
                continue;
            }
            $at = $planner->validity($b)[1]->sub(new \DateInterval('PT1H')); // check-out time
            if ($at < $now) {
                continue; // past departure: history, never touched
            }
            $due = $this->nextArrival($b, $active, $planner) ?? $at->add(new \DateInterval('P1D'));
            if (null === $existing) {
                $this->place->createCleaning($placeId, [
                    'scheduledAt' => $at->format(\DATE_ATOM), 'dueAt' => $due->format(\DATE_ATOM),
                    'label' => mb_substr('Ménage après départ · '.$b->guest, 0, 120), 'externalRef' => $ref,
                    'notes' => \sprintf('Réservation %d (%s → %s).', $b->id, $b->arrival, $b->departure),
                ]);
                $changed = true;
            } elseif ('todo' === ($existing['status'] ?? null) && (self::differs($existing['scheduledAt'] ?? null, $at) || self::differs($existing['dueAt'] ?? null, $due))) {
                $this->place->updateCleaning((string) $existing['id'], ['scheduledAt' => $at->format(\DATE_ATOM), 'dueAt' => $due->format(\DATE_ATOM)]);
                $changed = true;
            }
        }

        return $changed ? $this->cleanings($placeId) : $tasks;
    }

    /** @return array<string, array<string, mixed>> cleanings of the place created by PMS, indexed by externalRef */
    public function cleanings(string $placeId): array
    {
        $out = [];
        foreach ($this->place->cleanings($placeId) as $t) {
            if (\is_string($t['externalRef'] ?? null) && str_starts_with($t['externalRef'], self::REF_PREFIX)) {
                $out[$t['externalRef']] = $t;
            }
        }

        return $out;
    }

    /** Check-in of the first stay arriving on or after the departure of $b. @param list<Booking> $active */
    private function nextArrival(Booking $b, array $active, AccessCodePlanner $planner): ?\DateTimeImmutable
    {
        $next = null;
        foreach ($active as $o) {
            if ($o->id !== $b->id && $o->arrival >= $b->departure && (null === $next || $o->arrival < $next->arrival)) {
                $next = $o;
            }
        }
        if (null === $next) {
            return null;
        }
        $checkIn = $planner->validity($next)[0]->add(new \DateInterval('PT1H'));
        $checkOut = $planner->validity($b)[1]->sub(new \DateInterval('PT1H'));

        return $checkIn > $checkOut ? $checkIn : null;
    }

    private static function differs(mixed $iso, \DateTimeImmutable $at): bool
    {
        return !\is_string($iso) || new \DateTimeImmutable($iso) != $at;
    }
}
