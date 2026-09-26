<?php

namespace App\Lodgify;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lodgify public API (v1 and v2): properties, bookings (cached 5 minutes), booking detail with its quote, messaging
 * thread, sending a message to the guest. Without LODGIFY_API_KEY, the demo data of DemoLodgify is used.
 */
final class LodgifyClient
{
    private const BASE = 'https://api.lodgify.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly string $lodgifyApiKey,
    ) {
    }

    public function isDemo(): bool
    {
        return '' === $this->lodgifyApiKey;
    }

    /** @return list<array{id: int, name: string, internalName: ?string, latitude: ?float, longitude: ?float}> */
    public function properties(): array
    {
        if ($this->isDemo()) {
            return DemoLodgify::properties();
        }

        return $this->cache->get('lodgify.properties', function (ItemInterface $item) {
            $item->expiresAfter(300);
            $r = $this->get('/v2/properties');
            $list = array_is_list($r) ? $r : ($r['items'] ?? []);

            return array_map(static fn (array $p) => [
                'id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? ''),
                'internalName' => \is_string($p['internal_name'] ?? null) && '' !== trim($p['internal_name']) ? trim($p['internal_name']) : null,
                'latitude' => \is_numeric($p['latitude'] ?? null) ? (float) $p['latitude'] : null,
                'longitude' => \is_numeric($p['longitude'] ?? null) ? (float) $p['longitude'] : null,
            ], $list);
        });
    }

    /** All bookings (past included: stayFilter=All), newest data at most 5 minutes old. @return list<Booking> */
    public function bookings(): array
    {
        if ($this->isDemo()) {
            return DemoLodgify::bookings();
        }
        $raw = $this->cache->get('lodgify.bookings', function (ItemInterface $item) {
            $item->expiresAfter(300);
            $out = [];
            for ($page = 1; $page <= 20; ++$page) {
                $items = $this->get('/v2/reservations/bookings', ['page' => $page, 'size' => 50, 'includeCount' => 'true', 'trash' => 'false', 'stayFilter' => 'All'])['items'] ?? [];
                array_push($out, ...$items);
                if (\count($items) < 50) {
                    break;
                }
            }

            return $out;
        });

        return array_map(self::normalize(...), $raw);
    }

    public function booking(int $id): ?Booking
    {
        foreach ($this->bookings() as $b) {
            if ($b->id === $id) {
                return $b;
            }
        }

        return null;
    }

    /** Full booking with its quote (value and price breakdown). @return array<string, mixed> */
    public function bookingDetail(int $id): array
    {
        return $this->isDemo() ? DemoLodgify::detail($id) : $this->get('/v2/reservations/bookings/'.$id);
    }

    /** Messages of a thread, oldest first. @return list<array<string, mixed>> */
    public function thread(string $threadUid): array
    {
        if ($this->isDemo()) {
            return DemoLodgify::thread($threadUid);
        }

        return $this->get('/v2/messaging/'.rawurlencode($threadUid))['messages'] ?? [];
    }

    /**
     * Sends a message to the guest: pushed by Lodgify on the channel of the booking (Airbnb, Booking.com) or by email.
     * $messageId makes the call idempotent (a retry does not send it twice).
     */
    public function sendMessage(int $bookingId, string $html, string $messageId): void
    {
        if ($this->isDemo()) {
            throw new HttpException(400, 'Envoi impossible en mode démo.');
        }
        $this->request('POST', '/v1/reservation/booking/'.$bookingId.'/messages', ['json' => [[
            'type' => 'Owner', 'message' => $html, 'send_notification' => true, 'message_id' => $messageId,
        ]]]);
    }

    public function invalidateBookings(): void
    {
        $this->cache->delete('lodgify.bookings');
    }

    /** @param array<string, mixed> $b */
    private static function normalize(array $b): Booking
    {
        $hm = static fn (mixed $t) => \is_string($t) && preg_match('/^\d\d:\d\d/', $t) ? substr($t, 0, 5) : null;
        $email = \is_string($b['guest']['email'] ?? null) && '' !== trim($b['guest']['email']) ? strtolower(trim($b['guest']['email'])) : null;

        return new Booking(
            id: (int) $b['id'],
            propertyId: (int) ($b['property_id'] ?? $b['rooms'][0]['property_id'] ?? 0),
            arrival: substr((string) ($b['arrival'] ?? ''), 0, 10),
            departure: substr((string) ($b['departure'] ?? ''), 0, 10),
            guest: (string) ($b['guest']['name'] ?? $b['guest_name'] ?? 'Invité'),
            status: (string) ($b['status'] ?? ''),
            source: (string) ($b['source'] ?? $b['source_text'] ?? ''),
            total: (float) ($b['total_amount'] ?? $b['total'] ?? 0),
            threadUid: ($b['thread_uid'] ?? null) ?: null,
            guestEmail: $email,
            checkIn: $hm($b['check_in']['time'] ?? null),
            checkOut: $hm($b['check_out']['time'] ?? null),
        );
    }

    /** @param array<string, mixed> $query @return array<mixed> */
    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /** @param array<string, mixed> $options @return array<mixed> */
    private function request(string $method, string $path, array $options): array
    {
        $response = $this->http->request($method, self::BASE.$path, $options + [
            'headers' => ['X-ApiKey' => $this->lodgifyApiKey, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new HttpException(502, \sprintf('Lodgify a répondu avec l’erreur %d.', $status));
        }
        $content = $response->getContent(false);

        return '' === $content ? [] : (array) json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
