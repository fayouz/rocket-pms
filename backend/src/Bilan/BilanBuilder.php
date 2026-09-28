<?php

namespace App\Bilan;

use App\Entity\Expense;
use App\Entity\Property;
use App\Lodgify\Booking;
use App\Lodgify\BookingProviderRegistry;
use App\Repository\ExpenseRepository;

/**
 * Yearly bilan of a property, ported from LoussaHousing (server/utils/bilan.ts): gross revenue of the Lodgify bookings
 * spread night by night (a stay across two months or years counts in each), plus the PMS accounting entries (charges
 * and other income, App\Entity\Expense), per month and per category. Lodgify data is read, never stored.
 */
final class BilanBuilder
{
    public const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function __construct(
        private readonly BookingProviderRegistry $bookingProviders,
        private readonly ExpenseRepository $expenses,
        private readonly string $timezone,
    ) {
    }

    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone($this->timezone));
    }

    /** @return array<string, mixed> */
    public function build(Property $property, int $year): array
    {
        $lodgify = $this->bookingProviders->providerFor($property);
        $mine = array_filter($lodgify->bookings(), static fn (Booking $b) => $b->propertyId === $property->getLodgifyPropertyId() && $b->isActive());
        $months = array_map(static fn (string $label) => ['label' => $label, 'revenue' => 0.0, 'nights' => 0, 'charges' => 0, 'income' => 0], self::MONTHS);
        $revenue = 0.0;
        $nights = 0;
        $stays = 0;
        $firstNight = null;
        $years = [(int) $this->today()->format('Y') => true];
        foreach ($mine as $b) {
            $n = $b->nights();
            $years[(int) substr($b->arrival, 0, 4)] = true;
            if ($n <= 0) {
                continue;
            }
            $perNight = $b->total / $n;
            $counted = false;
            $d = new \DateTimeImmutable($b->arrival);
            for ($i = 0; $i < $n; ++$i, $d = $d->modify('+1 day')) {
                if ((int) $d->format('Y') !== $year) {
                    continue;
                }
                $counted = true;
                $firstNight = null === $firstNight || $d < $firstNight ? $d : $firstNight;
                $m = (int) $d->format('n') - 1;
                $months[$m]['revenue'] += $perNight;
                ++$months[$m]['nights'];
                $revenue += $perNight;
                ++$nights;
            }
            $stays += $counted ? 1 : 0;
        }

        $entries = $this->expenses->between($property, new \DateTimeImmutable("$year-01-01"), new \DateTimeImmutable(($year + 1).'-01-01'));
        $byCat = [];
        $charges = 0;
        $income = 0;
        foreach ($entries as $e) {
            $m = (int) $e->getDate()->format('n') - 1;
            $key = $e->isIncome() ? 'income' : 'charges';
            $months[$m][$key] += $e->getAmountCents();
            $e->isIncome() ? $income += $e->getAmountCents() : $charges += $e->getAmountCents();
            $byCat[$e->getCategory()] ??= ['key' => $e->getCategory(), 'label' => Expense::CATEGORIES[$e->getCategory()][0], 'kind' => Expense::CATEGORIES[$e->getCategory()][1], 'total' => 0, 'count' => 0];
            $byCat[$e->getCategory()]['total'] += $e->getAmountCents();
            ++$byCat[$e->getCategory()]['count'];
        }
        foreach ($this->expenses->years($property) as $y) {
            $years[$y] = true;
        }

        // Occupancy over the period operated: from the first night sold this year to today (current year) or year end
        $yearStart = new \DateTimeImmutable("$year-01-01");
        $yearEnd = new \DateTimeImmutable(($year + 1).'-01-01');
        $from = $firstNight ?? $yearStart;
        $until = min($yearEnd, $this->today()->modify('+1 day')->setTimezone(new \DateTimeZone('UTC')));
        $until = new \DateTimeImmutable($until->format('Y-m-d'));
        $days = max(1, (int) $from->diff(max($until, $from))->days);

        $r = static fn (float $v) => round($v, 2);
        $c = static fn (int $cents) => $cents / 100;
        $categories = array_values($byCat);
        usort($categories, static fn (array $a, array $b) => [$a['kind'], $b['total']] <=> [$b['kind'], $a['total']]);
        krsort($years);

        return [
            'property' => ['id' => $property->getId()->toRfc4122(), 'name' => $property->getName()],
            'year' => $year, 'years' => array_values(array_filter(array_keys($years), static fn (int $y) => $y >= 2000 && $y <= 2100)),
            'demo' => $lodgify->isDemo(), 'currency' => 'EUR',
            'revenue' => $r($revenue), 'nights' => $nights, 'stays' => $stays,
            'occupancy' => min(100, (int) round(100 * $nights / $days)), 'daysConsidered' => $days,
            'since' => $from > $yearStart ? $from->format('Y-m-d') : null,
            'months' => array_map(static fn (array $m) => ['label' => $m['label'], 'revenue' => $r($m['revenue']), 'nights' => $m['nights'], 'charges' => $c($m['charges']), 'income' => $c($m['income']), 'result' => $r($m['revenue'] + $c($m['income']) - $c($m['charges']))], $months),
            'categories' => array_map(static fn (array $x) => ['total' => $c($x['total'])] + $x, $categories),
            'chargesTotal' => $c($charges), 'otherIncome' => $c($income), 'entries' => \count($entries),
            'result' => $r($revenue + $c($income) - $c($charges)),
            'items' => array_map(static fn (Expense $e) => $e->toArray(), $entries),
        ];
    }

    /** CSV (semicolon, decimal comma, UTF-8 with BOM: opens as is in a French spreadsheet): months, categories, entries. */
    public function csv(array $bilan): string
    {
        $out = fopen('php://temp', 'r+');
        $n = static fn (float|int $v) => number_format((float) $v, 2, ',', '');
        $row = static fn (array $cols) => fputcsv($out, array_map(static fn ($v) => \is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $cols), ';', '"', '');
        $row(['Bilan', $bilan['property']['name'], (string) $bilan['year']]);
        $row([]);
        $row(['Mois', 'Nuits', 'Revenus Lodgify', 'Autres recettes', 'Charges', 'Résultat']);
        foreach ($bilan['months'] as $m) {
            $row([$m['label'], $m['nights'], $n($m['revenue']), $n($m['income']), $n($m['charges']), $n($m['result'])]);
        }
        $row(['Total', $bilan['nights'], $n($bilan['revenue']), $n($bilan['otherIncome']), $n($bilan['chargesTotal']), $n($bilan['result'])]);
        $row([]);
        $row(['Catégorie', 'Type', 'Écritures', 'Total']);
        foreach ($bilan['categories'] as $x) {
            $row([$x['label'], 'income' === $x['kind'] ? 'Recette' : 'Charge', $x['count'], $n($x['total'])]);
        }
        $row([]);
        $row(['Date', 'Catégorie', 'Montant', 'Note', 'Document']);
        foreach ($bilan['items'] as $e) {
            $row([$e['date'], $e['categoryLabel'], $n('income' === $e['kind'] ? $e['amount'] : -$e['amount']), $e['note'], $e['documentRef'] ?? '']);
        }
        rewind($out);

        return "\u{FEFF}".stream_get_contents($out);
    }
}
