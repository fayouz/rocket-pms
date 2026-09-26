<?php

namespace App\Lodgify;

/** A Lodgify booking, normalized (no raw Lodgify payload leaves the server). Dates are Y-m-d, times H:i. */
final readonly class Booking
{
    public function __construct(
        public int $id,
        public int $propertyId,
        public string $arrival,
        public string $departure,
        public string $guest,
        public string $status,
        public string $source,
        public float $total,
        public ?string $threadUid = null,
        public ?string $guestEmail = null,
        public ?string $checkIn = null,
        public ?string $checkOut = null,
    ) {
    }

    /** Declined, cancelled or still an open enquiry: not a real stay. */
    public function isActive(): bool
    {
        return !preg_match('/declined|cancel|open/i', $this->status);
    }

    public function nights(): int
    {
        return max(0, (int) round((strtotime($this->departure) - strtotime($this->arrival)) / 86400));
    }

    /** now = in progress, next = upcoming, past, other = not active */
    public function phase(string $today): string
    {
        if (!$this->isActive()) {
            return 'other';
        }

        return $this->arrival <= $today && $this->departure > $today ? 'now' : ($this->arrival > $today ? 'next' : 'past');
    }

    /** @return array<string, mixed> */
    public function toArray(string $today): array
    {
        return [
            'id' => $this->id, 'propertyId' => $this->propertyId, 'guest' => $this->guest, 'guestEmail' => $this->guestEmail,
            'arrival' => $this->arrival, 'departure' => $this->departure, 'checkIn' => $this->checkIn, 'checkOut' => $this->checkOut,
            'nights' => $this->nights(), 'status' => $this->status, 'source' => $this->source, 'total' => $this->total,
            'active' => $this->isActive(), 'phase' => $this->phase($today),
        ];
    }
}
