<?php

namespace App\Bilan;

use App\Entity\Expense;
use App\Entity\Property;
use App\Repository\ExpenseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Import of platform statements (Airbnb, Booking.com... CSV exports reshaped), ported from LoussaHousing's
 * platform_transaction import: each line has an externalId (unique per source), a date, a kind, a positive amount,
 * optionally a label and a booking reference. Lines become accounting entries of the bilan:
 * fee/commission → "Frais de plateformes", tourist_tax → "Taxe de séjour", refund/other → "Autre charge",
 * income → "Autre recette". Payouts are skipped: the stays are already counted from Lodgify (no double count).
 * Dedup by (property, source, externalId): importing the same statement twice adds nothing.
 * Invalid lines are reported with their line number and never block the others.
 */
final class StatementImporter
{
    public const MAX_LINES = 5000;
    private const KINDS = [
        'fee' => 'frais_plateformes', 'commission' => 'frais_plateformes', 'frais' => 'frais_plateformes',
        'tourist_tax' => 'taxe_sejour', 'taxe_sejour' => 'taxe_sejour', 'taxe de séjour' => 'taxe_sejour', 'taxe de sejour' => 'taxe_sejour',
        'refund' => 'autre_charge', 'remboursement' => 'autre_charge', 'other' => 'autre_charge', 'autre' => 'autre_charge',
        'income' => 'autre_recette', 'recette' => 'autre_recette',
        'payout' => null, 'reversement' => null,
    ];
    private const COLUMNS = ['externalid' => 'externalId', 'id' => 'externalId', 'date' => 'date', 'kind' => 'kind', 'type' => 'kind', 'amount' => 'amount', 'montant' => 'amount', 'label' => 'label', 'libelle' => 'label', 'libellé' => 'label', 'bookingref' => 'bookingRef', 'reservation' => 'bookingRef', 'réservation' => 'bookingRef'];

    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{inserted: int, duplicates: int, skipped: int, invalid: list<array{line: int, reason: string}>}
     */
    public function import(Property $property, string $source, string $csv): array
    {
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $source)) {
            throw new HttpException(422, 'source : 2 à 30 caractères (a-z, 0-9, - ou _), par exemple airbnb.');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (\count($lines) < 2) {
            throw new HttpException(422, 'Fichier vide : une ligne d’en-tête puis au moins une ligne.');
        }
        if (\count($lines) > self::MAX_LINES + 1) {
            throw new HttpException(422, \sprintf('Trop de lignes (%d au plus).', self::MAX_LINES));
        }
        $separator = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn ($h) => self::COLUMNS[mb_strtolower(trim((string) $h))] ?? null, str_getcsv($lines[0], $separator, '"', ''));
        foreach (['externalId', 'date', 'kind', 'amount'] as $required) {
            if (!\in_array($required, $header, true)) {
                throw new HttpException(422, 'Colonne manquante : '.$required.' (en-tête attendu : externalId;date;kind;amount;label;bookingRef).');
            }
        }
        $known = [];
        foreach ($this->expenses->findBy(['property' => $property, 'source' => $source]) as $e) {
            $known[(string) $e->getExternalId()] = true;
        }
        $result = ['inserted' => 0, 'duplicates' => 0, 'skipped' => 0, 'invalid' => []];
        foreach (\array_slice($lines, 1) as $i => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $row = [];
            foreach (str_getcsv($line, $separator, '"', '') as $k => $v) {
                if (null !== ($header[$k] ?? null)) {
                    $row[$header[$k]] = trim((string) $v);
                }
            }
            try {
                $externalId = mb_substr($row['externalId'] ?? '', 0, 200);
                if ('' === $externalId) {
                    throw new HttpException(422, 'externalId requis');
                }
                $kind = mb_strtolower($row['kind'] ?? '');
                if (!\array_key_exists($kind, self::KINDS)) {
                    throw new HttpException(422, 'kind inconnu (fee, tourist_tax, refund, other, income, payout)');
                }
                if (isset($known[$externalId])) {
                    ++$result['duplicates'];
                    continue;
                }
                if (null === self::KINDS[$kind]) {
                    ++$result['skipped'];
                    continue;
                }
                $label = mb_substr(trim(($row['label'] ?? '').('' !== ($row['bookingRef'] ?? '') ? ' · réservation '.$row['bookingRef'] : '')), 0, 400);
                $expense = (new Expense($property))->apply([
                    'date' => self::date($row['date'] ?? ''), 'amount' => ltrim($row['amount'] ?? '', '-'), 'category' => self::KINDS[$kind],
                    'note' => mb_substr(ucfirst($source).('' !== $label ? ' · '.$label : ''), 0, 500),
                ])->markImported($source, $externalId);
                $this->em->persist($expense);
                $known[$externalId] = true;
                ++$result['inserted'];
            } catch (HttpException $e) {
                $result['invalid'][] = ['line' => $i + 2, 'reason' => $e->getMessage()];
            }
        }
        $this->em->flush();

        return $result;
    }

    /** AAAA-MM-JJ, or JJ/MM/AAAA as exported by French spreadsheets. */
    private static function date(string $value): string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) ? "$m[3]-$m[2]-$m[1]" : $value;
    }
}
