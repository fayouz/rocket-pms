<?php

namespace App\Dashboard;

use App\Code\AccessCodePlanner;
use App\Lodgify\Booking;
use App\Lodgify\LodgifyClient;
use App\Repository\PropertyRepository;
use Rocket\Core\Dashboard\DashboardSectionInterface;
use Rocket\Core\Entity\User;

/**
 * The properties on the dashboard: arrivals and departures of the day, turnovers, arrivals in the next 7 days, occupancy
 * and revenue of the period (Lodgify totals spread night by night), and the next arrivals.
 */
final class PropertiesSection implements DashboardSectionInterface
{
    public function __construct(
        private readonly LodgifyClient $lodgify,
        private readonly PropertyRepository $properties,
        private readonly AccessCodePlanner $planner,
    ) {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $linked = [];
        foreach ($this->properties->findAll() as $p) {
            if (null !== $p->getLodgifyPropertyId()) {
                $linked[$p->getLodgifyPropertyId()] = $p;
            }
        }
        $bookings = array_values(array_filter($this->lodgify->bookings(), static fn (Booking $b) => $b->isActive() && isset($linked[$b->propertyId])));
        $today = $this->planner->today();
        $in7 = (new \DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        $arrivals = array_filter($bookings, static fn (Booking $b) => $b->arrival === $today);
        $departures = array_filter($bookings, static fn (Booking $b) => $b->departure === $today);
        $turnovers = array_filter($arrivals, static fn (Booking $a) => [] !== array_filter($departures, static fn (Booking $d) => $d->propertyId === $a->propertyId));

        // Night by night between $previousFrom and today: revenue and occupied nights per day
        [$daily, $revenue, $previousRevenue, $nights] = [[], 0.0, 0.0, 0];
        $start = $from->format('Y-m-d');
        foreach ($bookings as $b) {
            $n = $b->nights();
            for ($i = 0; $i < $n; ++$i) {
                $day = (new \DateTimeImmutable($b->arrival))->modify('+'.$i.' days')->format('Y-m-d');
                if ($day >= $today) {
                    break;
                }
                $perNight = $b->total / $n;
                if ($day >= $start) {
                    $daily[$day]['nights'] = ($daily[$day]['nights'] ?? 0) + 1;
                    $revenue += $perNight;
                    ++$nights;
                } elseif ($day >= $previousFrom->format('Y-m-d')) {
                    $previousRevenue += $perNight;
                }
            }
        }
        $days = max(1, (int) $from->diff(new \DateTimeImmutable($today))->days);
        $next = array_filter($bookings, static fn (Booking $b) => $b->arrival > $today);
        usort($next, static fn (Booking $a, Booking $b) => strcmp($a->arrival, $b->arrival));

        return [
            'kpis' => [
                ['id' => 'arrivals_today', 'label' => 'Arrivées aujourd’hui', 'value' => \count($arrivals), 'format' => 'number', 'icon' => 'i-lucide-log-in', 'tone' => 'bg-primary/10 text-primary',
                    'detail' => \sprintf('%d départ(s) · %d ménage(s) entre deux séjours', \count($departures), \count($turnovers))],
                ['id' => 'arrivals_week', 'label' => 'Arrivées (7 j)', 'value' => \count(array_filter($bookings, static fn (Booking $b) => $b->arrival > $today && $b->arrival <= $in7)), 'format' => 'number', 'icon' => 'i-lucide-calendar-check', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
                ['id' => 'occupancy', 'label' => 'Occupation (30 j)', 'value' => (int) round(100 * $nights / ($days * max(1, \count($linked)))), 'format' => 'percent', 'icon' => 'i-lucide-percent', 'tone' => 'bg-violet-500/10 text-violet-600 dark:text-violet-400', 'series' => 'nights'],
                ['id' => 'revenue', 'label' => 'Revenus (30 j, €)', 'value' => (int) round($revenue), 'previous' => (int) round($previousRevenue), 'format' => 'number', 'icon' => 'i-lucide-euro', 'tone' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                    'detail' => 'montants Lodgify, hors commissions'],
            ],
            'series' => [['key' => 'nights', 'label' => 'Nuits occupées', 'color' => 'bg-violet-500']],
            'daily' => $daily,
            'recent' => [
                'title' => 'Prochaines arrivées',
                'link' => '/properties',
                'empty' => 'Aucune arrivée à venir.',
                'items' => array_map(static fn (Booking $b) => [
                    'id' => (string) $b->id,
                    'title' => $b->guest,
                    'subtitle' => $linked[$b->propertyId]->getName().' · '.$b->nights().' nuit(s)',
                    'at' => (new \DateTimeImmutable($b->arrival))->format(\DATE_ATOM),
                    'badge' => (new \DateTimeImmutable($b->arrival))->format('d/m'),
                    'badgeColor' => 'neutral',
                    'link' => '/properties/'.$linked[$b->propertyId]->getId()->toRfc4122(),
                ], \array_slice($next, 0, 6)),
            ],
            'quickActions' => [
                ['label' => 'Logements', 'icon' => 'i-lucide-building-2', 'to' => '/properties', 'tone' => 'bg-primary/10 text-primary'],
                ['label' => 'Timeline', 'icon' => 'i-lucide-git-commit-vertical', 'to' => '/timeline', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
            ],
        ];
    }
}
