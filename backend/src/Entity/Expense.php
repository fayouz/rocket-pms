<?php

namespace App\Entity;

use App\Repository\ExpenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Accounting entry of a property for its bilan (ported from LoussaHousing's typed explorer files: type, date,
 * amount): a charge or another income, with an optional reference to a Rocket Place document (its id, e.g.
 * "file:…"). Rocket Place documents carry no typed metadata (amount, date), so the amounts live here.
 */
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ORM\Index(columns: ['property_id', 'date'])]
#[ORM\UniqueConstraint(name: 'expense_import_unique', columns: ['property_id', 'source', 'external_id'])]
class Expense
{
    /** category => [label, kind] ; kind "charge" or "income" (other income than the Lodgify bookings). */
    public const CATEGORIES = [
        'menage' => ['Ménage et blanchisserie', 'charge'],
        'consommables' => ['Consommables', 'charge'],
        'energie' => ['Énergie et eau', 'charge'],
        'internet' => ['Internet et abonnements', 'charge'],
        'assurance' => ['Assurance', 'charge'],
        'entretien' => ['Entretien et réparations', 'charge'],
        'travaux' => ['Travaux et équipement', 'charge'],
        'taxes' => ['Impôts et taxes', 'charge'],
        'taxe_sejour' => ['Taxe de séjour', 'charge'],
        'frais_plateformes' => ['Frais de plateformes', 'charge'],
        'credit' => ['Crédit et frais bancaires', 'charge'],
        'copropriete' => ['Copropriété et loyer', 'charge'],
        'autre_charge' => ['Autre charge', 'charge'],
        'autre_recette' => ['Autre recette', 'income'],
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Amount in euros, cents as an integer (no float rounding). Always positive: the category tells charge or income. */
    #[ORM\Column]
    private int $amountCents = 0;

    #[ORM\Column(length: 32)]
    private string $category = 'autre_charge';

    #[ORM\Column(length: 500)]
    private string $note = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $documentRef = null;

    /** Imported platform statement line (App\Bilan\StatementImporter): its source and id there, unique per property (dedup). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $externalId = null;

    use TrackedTrait;

    public function __construct(Property $property)
    {
        $this->id = Uuid::v7();
        $this->property = $property;
        $this->date = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid { return $this->id; }
    public function getProperty(): Property { return $this->property; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getCategory(): string { return $this->category; }
    public function getNote(): string { return $this->note; }
    public function getDocumentRef(): ?string { return $this->documentRef; }

    public function getSource(): ?string { return $this->source; }
    public function getExternalId(): ?string { return $this->externalId; }

    public function markImported(string $source, string $externalId): static
    {
        $this->source = $source;
        $this->externalId = $externalId;

        return $this;
    }

    public function isIncome(): bool
    {
        return 'income' === self::CATEGORIES[$this->category][1];
    }

    /**
     * Partial update from JSON (only the given keys), validated; 422 with a French message on invalid input.
     *
     * @param array<string, mixed> $data
     */
    public function apply(array $data): static
    {
        if (\array_key_exists('date', $data)) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['date']);
            if (false === $d || $d->format('Y-m-d') !== (string) $data['date'] || (int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
                throw new HttpException(422, 'date : date invalide (AAAA-MM-JJ).');
            }
            $this->date = $d;
        }
        if (\array_key_exists('amount', $data)) {
            $a = $data['amount'];
            if (\is_string($a)) {
                $a = str_replace([' ', ','], ['', '.'], $a);
            }
            if (!is_numeric($a) || (float) $a <= 0 || (float) $a >= 10_000_000) {
                throw new HttpException(422, 'amount : montant positif requis.');
            }
            $this->amountCents = (int) round((float) $a * 100);
        }
        if (\array_key_exists('category', $data)) {
            if (!\is_string($data['category']) || !isset(self::CATEGORIES[$data['category']])) {
                throw new HttpException(422, 'category : catégorie inconnue.');
            }
            $this->category = $data['category'];
        }
        if (\array_key_exists('note', $data)) {
            $this->note = mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $data['note']) ?? ''), 0, 500);
        }
        if (\array_key_exists('documentRef', $data)) {
            $ref = null === $data['documentRef'] ? '' : trim((string) $data['documentRef']);
            if ('' !== $ref && !preg_match('/^[\w:.-]{1,255}$/', $ref)) {
                throw new HttpException(422, 'documentRef : référence de document invalide.');
            }
            $this->documentRef = '' === $ref ? null : $ref;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(), 'date' => $this->date->format('Y-m-d'), 'amount' => $this->amountCents / 100,
            'category' => $this->category, 'categoryLabel' => self::CATEGORIES[$this->category][0], 'kind' => self::CATEGORIES[$this->category][1],
            'note' => $this->note, 'documentRef' => $this->documentRef, 'source' => $this->source, 'externalId' => $this->externalId,
        ];
    }
}
