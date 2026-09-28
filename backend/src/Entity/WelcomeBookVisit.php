<?php

namespace App\Entity;

use App\Repository\WelcomeBookVisitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Visit counter of the public welcome-book pages: one row per book, link ("guest" with its booking id, or "tv" with
 * booking id 0) and day, incremented on each page load. Nothing about the visitor is stored (no IP, no user agent).
 */
#[ORM\Entity(repositoryClass: WelcomeBookVisitRepository::class)]
#[ORM\UniqueConstraint(name: 'welcome_book_visit_unique', columns: ['book_id', 'link', 'booking_id', 'day'])]
class WelcomeBookVisit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WelcomeBook $book;

    #[ORM\Column(length: 8)]
    private string $link;

    #[ORM\Column(type: Types::BIGINT)]
    private string $bookingId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private int $count = 0;

    public function __construct(WelcomeBook $book, string $link, int $bookingId, \DateTimeImmutable $day)
    {
        $this->book = $book;
        $this->link = $link;
        $this->bookingId = (string) $bookingId;
        $this->day = $day;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): WelcomeBook { return $this->book; }
    public function getLink(): string { return $this->link; }
    public function getBookingId(): int { return (int) $this->bookingId; }
    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function getCount(): int { return $this->count; }
}
