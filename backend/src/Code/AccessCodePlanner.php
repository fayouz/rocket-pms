<?php

namespace App\Code;

use App\Cleaning\CleaningPlanner;
use App\Entity\Property;
use App\Lodgify\Booking;
use App\Lodgify\BookingProviderRegistry;
use App\Place\PlaceClient;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keypad access of the stays, delegated to Rocket Place. PMS only computes which access grants are needed: one per
 * upcoming active booking of a property linked to a place with a lock, opening 1 h before check-in and closing 1 h
 * after check-out (local time, PMS_TIMEZONE). Each grant is planned in Rocket Place with externalRef = booking id, so
 * planning is idempotent (an existing grant for that booking is reused). A planned grant whose booking dates changed
 * is revoked and re-planned; a grant already sent to the lock is never touched (flagged "outdated").
 * Sending the code to the lock stays an explicit user click (::send), never implicit. A stay that has started is
 * never touched again. Planning also syncs the cleanings after each departure (App\Cleaning\CleaningPlanner); a
 * failure there (Rocket Place without cleanings, 4xx) never blocks the codes.
 */
final class AccessCodePlanner
{
    private const DEFAULT_CHECKIN = '15:00';
    private const DEFAULT_CHECKOUT = '11:00';
    private const MARGIN = 'PT1H';

    public function __construct(
        private readonly BookingProviderRegistry $bookingProviders,
        private readonly PlaceClient $place,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly string $timezone,
        private readonly ?CleaningPlanner $cleanings = null,
    ) {
    }

    /** Opening and closing of the access of a booking. @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public function validity(Booking $b): array
    {
        $tz = new \DateTimeZone($this->timezone);
        $from = new \DateTimeImmutable($b->arrival.' '.($b->checkIn ?? self::DEFAULT_CHECKIN), $tz);
        $until = new \DateTimeImmutable($b->departure.' '.($b->checkOut ?? self::DEFAULT_CHECKOUT), $tz);

        return [$from->sub(new \DateInterval(self::MARGIN)), $until->add(new \DateInterval(self::MARGIN))];
    }

    /**
     * Plans the missing grants of the upcoming stays of the property, then returns the live (not revoked) grants of
     * its place, indexed by externalRef (booking id). Empty when the property has no place.
     *
     * @return array<string, array<string, mixed>>
     */
    public function plan(Property $property): array
    {
        $this->lastCleanings = [];
        $placeId = $property->getPlaceId();
        if (null === $placeId) {
            return [];
        }
        $lock = $this->lockFactory->createLock('pms-access-plan-'.$placeId, 30);
        $lock->acquire(true);
        try {
            $grants = $this->grants($placeId);
            $lockId = null;
            $changed = false;
            foreach ($this->upcoming($property) as $b) {
                [$from, $until] = $this->validity($b);
                $existing = $grants[(string) $b->id] ?? null;
                if (null !== $existing && ('created' === $existing['status'] || !$this->differs($existing, $from, $until))) {
                    continue;
                }
                $lockId ??= $this->firstLockId($placeId);
                if (null === $lockId) {
                    break; // no lock on the place: nothing to plan
                }
                if (null !== $existing) {
                    $this->place->request('POST', '/api/access-grants/'.$existing['id'].'/revoke');
                }
                $this->place->request('POST', '/api/places/'.$placeId.'/access-grants', [
                    'lockId' => $lockId, 'label' => mb_substr('LH-'.$b->id.' '.$b->guest, 0, 120),
                    'validFrom' => $from->format(\DATE_ATOM), 'validUntil' => $until->format(\DATE_ATOM), 'externalRef' => (string) $b->id,
                ]);
                $changed = true;
            }
            $this->syncCleanings($property, $placeId);

            return $changed ? $this->grants($placeId) : $grants;
        } finally {
            $lock->release();
        }
    }

    /** Cleanings of the place after this sync, indexed by externalRef ("booking:<id>:checkout"); empty when unavailable. @var array<string, array<string, mixed>> */
    private array $lastCleanings = [];

    /** @return array<string, array<string, mixed>> cleanings of the last ::plan of this property's place */
    public function lastCleanings(): array
    {
        return $this->lastCleanings;
    }

    private function syncCleanings(Property $property, string $placeId): void
    {
        $this->lastCleanings = [];
        if (null === $this->cleanings) {
            return;
        }
        try {
            $this->lastCleanings = $this->cleanings->sync($placeId, $this->bookingsOf($property), $this);
        } catch (HttpException) {
            // Rocket Place without cleanings or refusing them: the access codes are still planned
        }
    }

    /** A grant sent to the lock whose booking dates changed since: it must be redone by hand. @param array<string, mixed> $grant */
    public function isOutdated(array $grant, Booking $b): bool
    {
        [$from, $until] = $this->validity($b);

        return 'created' === $grant['status'] && $this->differs($grant, $from, $until);
    }

    /** Writes the code of a grant of this property to the lock (explicit user action). @return array<string, mixed> */
    public function send(Property $property, string $grantId): array
    {
        $placeId = PlaceClient::placeIdOf($property);
        $grant = current(array_filter($this->grants($placeId), static fn (array $g) => $g['id'] === $grantId))
            ?: throw new HttpException(404, $this->translator->trans('booking.unknown'));
        if ('created' === $grant['status']) {
            throw new HttpException(409, $this->translator->trans('code.already_created'));
        }
        $b = null === $grant['externalRef'] || !ctype_digit((string) $grant['externalRef']) ? null : $this->bookingProviders->providerFor($property)->booking((int) $grant['externalRef']);
        if (null === $b || $b->arrival <= $this->today()) {
            throw new HttpException(409, $this->translator->trans('code.started'));
        }

        return $this->place->request('POST', '/api/access-grants/'.$grantId.'/send');
    }

    public function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
    }

    /** @return array<string, array<string, mixed>> live grants of the place indexed by externalRef */
    private function grants(string $placeId): array
    {
        $out = [];
        foreach ($this->place->request('GET', '/api/places/'.$placeId.'/access-grants') as $g) {
            if (\is_array($g) && 'revoked' !== ($g['status'] ?? '') && null !== ($g['externalRef'] ?? null)) {
                $out[(string) $g['externalRef']] = $g;
            }
        }

        return $out;
    }

    /** Bookings of the property (its own Lodgify connector, or the legacy account). @return list<Booking> */
    public function bookingsOf(Property $property): array
    {
        if (null === $property->getLodgifyPropertyId()) {
            return [];
        }

        return array_values(array_filter($this->bookingProviders->providerFor($property)->bookings(), static fn (Booking $b) => $b->propertyId === $property->getLodgifyPropertyId()));
    }

    /** @return list<Booking> */
    private function upcoming(Property $property): array
    {
        $today = $this->today();

        return array_values(array_filter($this->bookingsOf($property), static fn (Booking $b) => $b->isActive() && $b->arrival > $today));
    }

    private function firstLockId(string $placeId): ?int
    {
        $locks = $this->place->request('GET', '/api/places/'.$placeId.'/locks')['locks'] ?? [];

        return isset($locks[0]['id']) ? (int) $locks[0]['id'] : null;
    }

    /** @param array<string, mixed> $grant */
    private function differs(array $grant, \DateTimeImmutable $from, \DateTimeImmutable $until): bool
    {
        return new \DateTimeImmutable((string) $grant['validFrom']) != $from || new \DateTimeImmutable((string) $grant['validUntil']) != $until;
    }
}
