<?php

namespace App\Timeline;

use App\Cleaning\CleaningPlanner;
use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Place\PlaceClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Read-only timeline of a property (planning codes and cleanings is App\Planning\PlanningRunner's job, never a read): arrivals and departures, keypad code opening and expiry (access grants of Rocket Place),
 * the cleanings after each departure (Rocket Place cleaning tasks planned by PMS), and the last events of the locks of its place, between $past days ago and $future days ahead, sorted by date.
 */
final class TimelineBuilder
{
    private const ACTIONS = [1 => 'Déverrouillage', 2 => 'Verrouillage', 3 => 'Ouverture (pêne)', 4 => 'Lock’n’Go', 5 => 'Lock’n’Go + ouverture'];

    public function __construct(
        private readonly PlaceClient $place,
        private readonly AccessCodePlanner $planner,
        private readonly CleaningPlanner $cleaningPlanner,
    ) {
    }

    /** @return list<array{at: string, kind: string, icon: string, title: string, description: string}> */
    public function build(Property $property, int $past, int $future): array
    {
        $from = new \DateTimeImmutable('-'.$past.' days');
        $to = new \DateTimeImmutable('+'.$future.' days');
        $in = static fn (\DateTimeImmutable $d) => $d >= $from && $d <= $to;
        $events = [];
        try {
            $grants = $this->planner->liveGrants($property);
            $locks = null === $property->getPlaceId() ? [] : ($this->place->request('GET', '/api/places/'.$property->getPlaceId().'/locks')['locks'] ?? []);
        } catch (HttpException) {
            [$grants, $locks] = [[], []]; // Rocket Place unreachable: the stays are still shown
        }
        try {
            $cleanings = null === $property->getPlaceId() ? [] : $this->cleaningPlanner->cleanings($property->getPlaceId());
        } catch (HttpException) {
            $cleanings = []; // Rocket Place without cleanings
        }
        foreach ($this->planner->bookingsOf($property) as $b) {
            if (!$b->isActive()) {
                continue;
            }
            [$open, $close] = $this->planner->validity($b);
            $arrival = $open->add(new \DateInterval('PT1H'));
            $departure = $close->sub(new \DateInterval('PT1H'));
            if ($in($arrival)) {
                $events[] = ['at' => $arrival, 'kind' => 'stay', 'icon' => 'i-lucide-log-in', 'title' => 'Arrivée · '.$b->guest, 'description' => $b->source];
            }
            if ($in($departure)) {
                $events[] = ['at' => $departure, 'kind' => 'stay', 'icon' => 'i-lucide-log-out', 'title' => 'Départ · '.$b->guest, 'description' => $b->source];
            }
            $grant = $grants[(string) $b->id] ?? null;
            if (null !== $grant) {
                $state = ['created' => 'envoyé à la serrure', 'error' => 'erreur d’envoi', 'planned' => 'pas encore envoyé à la serrure'][$grant['status']] ?? (string) $grant['status'];
                $opens = new \DateTimeImmutable((string) $grant['validFrom']);
                $closes = new \DateTimeImmutable((string) $grant['validUntil']);
                if ($in($opens)) {
                    $events[] = ['at' => $opens, 'kind' => 'code', 'icon' => 'i-lucide-key-round', 'title' => 'Code '.$grant['code'].' actif · '.$b->guest, 'description' => $state];
                }
                if ($in($closes)) {
                    $events[] = ['at' => $closes, 'kind' => 'code', 'icon' => 'i-lucide-lock', 'title' => 'Code '.$grant['code'].' expire · '.$b->guest, 'description' => $state];
                }
            }
        }
        foreach ($cleanings as $c) {
            $at = new \DateTimeImmutable((string) $c['scheduledAt']);
            if ($in($at) && 'cancelled' !== ($c['status'] ?? null)) {
                $state = ['todo' => 'à faire', 'in_progress' => 'en cours', 'done' => 'terminé'][$c['status'] ?? ''] ?? (string) ($c['status'] ?? '');
                $due = null === ($c['dueAt'] ?? null) ? '' : ' · avant le '.(new \DateTimeImmutable((string) $c['dueAt']))->setTimezone($at->getTimezone())->format('d/m H:i');
                $who = \is_array($c['assignee'] ?? null) ? ' · '.($c['assignee']['name'] ?? $c['assignee']['email'] ?? '') : '';
                $events[] = ['at' => $at, 'kind' => 'cleaning', 'icon' => 'i-lucide-spray-can', 'title' => (string) ($c['label'] ?? 'Ménage'), 'description' => $state.$due.$who, 'cleaningId' => (string) ($c['id'] ?? '')];
            }
        }
        foreach ($locks as $l) {
            foreach ($l['logs'] ?? [] as $g) {
                $at = new \DateTimeImmutable($g['date']);
                if ($in($at)) {
                    $events[] = ['at' => $at, 'kind' => 'lock', 'icon' => 'i-lucide-door-open', 'title' => (self::ACTIONS[$g['action']] ?? 'Action '.$g['action']).' · '.$l['name'], 'description' => $g['who']];
                }
            }
        }
        usort($events, static fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return array_map(static fn (array $e) => ['at' => $e['at']->format(\DATE_ATOM)] + $e, $events);
    }
}
