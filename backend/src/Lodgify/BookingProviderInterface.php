<?php

namespace App\Lodgify;

/**
 * A source of bookings, prices and guest conversations (Lodgify today, possibly another channel manager tomorrow).
 * Implemented by App\Lodgify\LodgifyClient; resolved per property by App\Lodgify\BookingProviderRegistry from the
 * property's own connector (App\Domotique\LodgifyPlugin), falling back to the single legacy LODGIFY_API_KEY.
 */
interface BookingProviderInterface
{
    public function isDemo(): bool;

    /** @return list<array{id: int, name: string, internalName: ?string, latitude: ?float, longitude: ?float}> */
    public function properties(): array;

    /** @return list<Booking> */
    public function bookings(): array;

    public function booking(int $id): ?Booking;

    /** @return array<string, mixed> */
    public function bookingDetail(int $id): array;

    /** @return list<array<string, mixed>> */
    public function thread(string $threadUid): array;

    public function sendMessage(int $bookingId, string $html, string $messageId): void;

    public function invalidateBookings(): void;
}
