<?php

namespace App\Lodgify;

/** Fictitious Lodgify data (no LODGIFY_API_KEY): two properties, a few bookings around today, one conversation. */
final class DemoLodgify
{
    /** @return list<array{id: int, name: string, internalName: ?string, latitude: ?float, longitude: ?float}> */
    public static function properties(): array
    {
        return [
            ['id' => 1001, 'name' => 'Appartement du port', 'internalName' => 'Le port', 'latitude' => 44.8378, 'longitude' => -0.5792],
            ['id' => 1002, 'name' => 'Maison des vignes', 'internalName' => 'Les vignes', 'latitude' => 44.8910, 'longitude' => -0.1560],
        ];
    }

    /** @return list<Booking> */
    public static function bookings(): array
    {
        // "Today" in the PMS time zone, as the dashboard and the planner count it (not the PHP default, often UTC)
        $tz = new \DateTimeZone($_SERVER['PMS_TIMEZONE'] ?? $_ENV['PMS_TIMEZONE'] ?? date_default_timezone_get());
        $d = static fn (int $n) => (new \DateTimeImmutable('today', $tz))->modify(($n >= 0 ? '+' : '').$n.' days')->format('Y-m-d');

        return [
            new Booking(1, 1001, $d(-3), $d(0), 'Alex Martin', 'Booked', 'AirbnbIntegration', 210, 'demo-1', null, '15:00', '11:00'),
            new Booking(2, 1002, $d(-1), $d(2), 'Marc Durand', 'Booked', 'BookingCom', 240, 'demo-2', 'marc.demo@guest.booking.com', '16:00', '10:00'),
            new Booking(3, 1001, $d(0), $d(3), 'Sofia Rossi', 'Booked', 'AirbnbIntegration', 205, 'demo-3', null, '15:00', '11:00'),
            new Booking(4, 1002, $d(4), $d(6), 'Paul Morel', 'Booked', 'Manual', 140, null, 'paul.demo@example.org', '15:00', '11:00'),
            new Booking(5, 1001, $d(8), $d(12), 'Anna Kowalska', 'Booked', 'BookingCom', 420, 'demo-5', null, '15:00', '11:00'),
            new Booking(6, 1002, $d(-20), $d(-15), 'Tom Baker', 'Declined', 'BookingCom', 380, null, null, null, null),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(int $id): array
    {
        $b = array_values(array_filter(self::bookings(), static fn (Booking $x) => $x->id === $id))[0] ?? null;
        $total = $b?->total ?? 0.0;
        $stay = round($total * 0.85, 2);

        return [
            'currency_code' => 'EUR', 'total_amount' => $total, 'amount_paid' => 0, 'amount_due' => $total,
            'quote' => ['room_type_items' => [['prices' => [
                ['type' => 'RoomRate', 'amount' => $stay, 'description' => 'Room rate'],
                ['type' => 'Fee', 'amount' => 15, 'description' => 'frais de ménage'],
                ['type' => 'Tax', 'amount' => round($total - $stay - 15, 2), 'description' => 'taxe de séjour'],
            ]]]],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function thread(string $uid): array
    {
        $at = static fn (string $m) => (new \DateTimeImmutable($m, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');

        return [
            ['id' => 1, 'type' => 'Owner', 'subject' => 'Votre réservation est confirmée', 'message' => 'Bonjour,<br/>Merci pour votre réservation !', 'date_created' => $at('-3 days'), 'message_status' => 'Delivered'],
            ['id' => 2, 'type' => 'Renter', 'subject' => '', 'message' => 'Bonjour, peut-on arriver vers 17 h ?', 'date_created' => $at('-2 days'), 'message_status' => 'Unknown'],
            ['id' => 3, 'type' => 'Owner', 'subject' => '', 'message' => 'Bien sûr, le code vous sera envoyé le jour de l&#39;arrivée.', 'date_created' => $at('-2 days +1 hour'), 'message_status' => 'Delivered'],
        ];
    }
}
