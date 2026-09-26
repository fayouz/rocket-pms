<?php

namespace App\Timeline;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Lodgify\LodgifyClient;
use App\Nuki\NukiClient;
use App\Repository\AccessCodeRepository;
use App\Repository\SmartLockRepository;

/**
 * Timeline of a property: arrivals and departures, keypad code opening and expiry, and the last events of its locks,
 * between $past days ago and $future days ahead, sorted by date.
 */
final class TimelineBuilder
{
    private const ACTIONS = [1 => 'Déverrouillage', 2 => 'Verrouillage', 3 => 'Ouverture (pêne)', 4 => 'Lock’n’Go', 5 => 'Lock’n’Go + ouverture'];

    public function __construct(
        private readonly LodgifyClient $lodgify,
        private readonly NukiClient $nuki,
        private readonly AccessCodeRepository $codes,
        private readonly SmartLockRepository $locks,
        private readonly AccessCodePlanner $planner,
    ) {
    }

    /** @return list<array{at: string, kind: string, icon: string, title: string, description: string}> */
    public function build(Property $property, int $past, int $future): array
    {
        $from = new \DateTimeImmutable('-'.$past.' days');
        $to = new \DateTimeImmutable('+'.$future.' days');
        $in = static fn (\DateTimeImmutable $d) => $d >= $from && $d <= $to;
        $events = [];
        foreach ($this->lodgify->bookings() as $b) {
            if ($b->propertyId !== $property->getLodgifyPropertyId() || !$b->isActive()) {
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
            $code = $this->codes->find($b->id);
            if (null !== $code) {
                $state = ['created' => 'créé sur Nuki', 'error' => 'erreur de création', 'planned' => 'pas encore créé sur Nuki'][$code->getStatus()] ?? $code->getStatus();
                if ($in($code->getValidFrom())) {
                    $events[] = ['at' => $code->getValidFrom(), 'kind' => 'code', 'icon' => 'i-lucide-key-round', 'title' => 'Code '.$code->getCode().' actif · '.$b->guest, 'description' => $state];
                }
                if ($in($code->getValidUntil())) {
                    $events[] = ['at' => $code->getValidUntil(), 'kind' => 'code', 'icon' => 'i-lucide-lock', 'title' => 'Code '.$code->getCode().' expire · '.$b->guest, 'description' => $state];
                }
            }
        }
        foreach ($this->nuki->locks() as $l) {
            if ($this->locks->find($l['id'])?->getProperty()?->getId()->equals($property->getId()) !== true) {
                continue;
            }
            foreach ($l['logs'] as $g) {
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
