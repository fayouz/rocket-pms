<?php

namespace App\Entity;

use App\Repository\AccessCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;

/**
 * The keypad code of a booking: planned automatically for upcoming stays, sent to the Nuki lock only on a user's click.
 * A stay that has started is never touched again.
 */
#[ORM\Entity(repositoryClass: AccessCodeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_access_code_lock_code', columns: ['lock_id', 'code'])]
class AccessCode
{
    public const PLANNED = 'planned';
    public const CREATED = 'created';
    public const ERROR = 'error';

    /** Lodgify booking id. */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private string $bookingId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'lock_id', referencedColumnName: 'nuki_id', nullable: false, onDelete: 'CASCADE')]
    private SmartLock $lock;

    #[ORM\Column(length: 6)]
    private string $code;

    /** With time zone: a Paris opening time is read back as the same instant, whatever the server's zone. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(length: 16)]
    private string $status = self::PLANNED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    use TrackedTrait;

    public function __construct(int $bookingId, SmartLock $lock, string $code, \DateTimeImmutable $validFrom, \DateTimeImmutable $validUntil)
    {
        $this->bookingId = (string) $bookingId;
        $this->lock = $lock;
        $this->code = $code;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
    }

    public function getBookingId(): int { return (int) $this->bookingId; }
    public function getLock(): SmartLock { return $this->lock; }
    public function getCode(): string { return $this->code; }
    public function getValidFrom(): \DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): \DateTimeImmutable { return $this->validUntil; }
    public function setValidity(\DateTimeImmutable $from, \DateTimeImmutable $until): static { $this->validFrom = $from; $this->validUntil = $until; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getError(): ?string { return $this->error; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function markCreated(\DateTimeImmutable $at): static { $this->status = self::CREATED; $this->error = null; $this->sentAt = $at; return $this; }
    public function markError(string $error): static { $this->status = self::ERROR; $this->error = mb_substr($error, 0, 500); return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bookingId' => $this->getBookingId(), 'lockId' => $this->lock->getNukiId(), 'code' => $this->code,
            'validFrom' => $this->validFrom->format(\DATE_ATOM), 'validUntil' => $this->validUntil->format(\DATE_ATOM),
            'status' => $this->status, 'error' => $this->error,
        ];
    }
}
