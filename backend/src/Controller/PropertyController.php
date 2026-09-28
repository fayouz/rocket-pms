<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Lodgify\Booking;
use App\Lodgify\BookingProviderInterface;
use App\Lodgify\BookingProviderRegistry;
use App\Property\PropertySync;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Bookings of a property (Lodgify), with their keypad code (an access grant of Rocket Place): list, value and price breakdown, conversation with the
 * guest (read and reply). Lodgify data is never stored, only read through the 5-minute cache.
 */
#[IsGranted('ROLE_USER')]
final class PropertyController extends AbstractController
{
    public function __construct(
        private readonly BookingProviderRegistry $bookingProviders,
        private readonly AccessCodePlanner $planner,
    ) {
    }

    #[Route('/api/properties/sync', name: 'api_properties_sync', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function sync(PropertySync $sync): JsonResponse
    {
        return $this->json($sync->sync());
    }

    /** Last 60 days and all upcoming stays, newest arrival first. */
    #[Route('/api/properties/{id}/bookings', name: 'api_property_bookings', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function bookings(#[MapEntity] Property $property): JsonResponse
    {
        $lodgify = $this->bookingProviders->providerFor($property);
        $grants = $this->planner->plan($property);
        $today = $this->planner->today();
        $since = (new \DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d');
        $list = array_filter($lodgify->bookings(), static fn (Booking $b) => $b->propertyId === $property->getLodgifyPropertyId() && $b->departure >= $since);
        usort($list, static fn (Booking $a, Booking $b) => strcmp($b->arrival, $a->arrival));

        return $this->json([
            'demo' => $lodgify->isDemo(),
            'items' => array_map(function (Booking $b) use ($today, $grants) {
                $grant = $grants[(string) $b->id] ?? null;

                return $b->toArray($today) + ['access' => null === $grant ? null : self::access($grant, $b, $this->planner->isOutdated($grant, $b))];
            }, array_values($list)),
        ]);
    }

    /** Value of the stay and detail of the calculation (Lodgify quote); the platform commission is not provided by Lodgify. */
    #[Route('/api/properties/{id}/bookings/{bookingId}/pricing', name: 'api_booking_pricing', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'bookingId' => '\d+'])]
    public function pricing(#[MapEntity] Property $property, int $bookingId): JsonResponse
    {
        $lodgify = $this->bookingProviders->providerFor($property);
        $b = $this->bookingOf($lodgify, $property, $bookingId);
        $r = $lodgify->bookingDetail($bookingId);
        $q = $r['quote'] ?? [];
        $prices = [];
        foreach (['room_type_items', 'addon_items', 'other_items'] as $key) {
            foreach ($q[$key] ?? [] as $item) {
                array_push($prices, ...($item['prices'] ?? [$item]));
            }
        }
        $lines = [];
        foreach ($prices as $p) {
            if (0.0 === (float) ($p['amount'] ?? 0)) {
                continue;
            }
            $type = (string) ($p['type'] ?? '');
            $desc = trim((string) ($p['description'] ?? ''));
            $label = 'RoomRate' === $type ? 'Nuitées' : ('' !== $desc && 0 !== strcasecmp($desc, 'room rate') ? mb_strtoupper(mb_substr($desc, 0, 1)).mb_substr($desc, 1) : $type);
            $lines[] = ['kind' => $type, 'label' => $label, 'amount' => (float) $p['amount']];
        }

        return $this->json([
            'currency' => (string) ($r['currency_code'] ?? 'EUR'), 'total' => (float) ($r['total_amount'] ?? $b->total),
            'paid' => (float) ($r['amount_paid'] ?? 0), 'due' => (float) ($r['amount_due'] ?? 0), 'nights' => $b->nights(), 'lines' => $lines,
        ]);
    }

    /** Lodgify thread of the booking (host and guest). HTML is converted to text here: no external HTML reaches the browser. */
    #[Route('/api/properties/{id}/bookings/{bookingId}/conversation', name: 'api_booking_conversation', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'bookingId' => '\d+'])]
    public function conversation(#[MapEntity] Property $property, int $bookingId): JsonResponse
    {
        $lodgify = $this->bookingProviders->providerFor($property);
        $b = $this->bookingOf($lodgify, $property, $bookingId);
        $messages = [];
        foreach (null === $b->threadUid ? [] : $lodgify->thread($b->threadUid) as $m) {
            $messages[] = [
                'key' => 'l'.$m['id'], 'kind' => 'lodgify', 'from' => 'Owner' === ($m['type'] ?? '') ? 'host' : 'guest',
                'at' => (new \DateTimeImmutable((string) $m['date_created'], new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                'subject' => (string) ($m['subject'] ?? ''), 'text' => self::toText((string) ($m['message'] ?? '')), 'status' => (string) ($m['message_status'] ?? ''),
            ];
        }
        usort($messages, static fn (array $a, array $c) => strcmp($a['at'], $c['at']));

        return $this->json(['messages' => $messages]);
    }

    /** Reply to the guest: JSON {"text", "messageId" (uuid, idempotent)}. Pushed by Lodgify on the channel of the booking. */
    #[Route('/api/properties/{id}/bookings/{bookingId}/conversation', name: 'api_booking_reply', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'bookingId' => '\d+'])]
    public function reply(#[MapEntity] Property $property, int $bookingId, Request $request): JsonResponse
    {
        $lodgify = $this->bookingProviders->providerFor($property);
        $this->bookingOf($lodgify, $property, $bookingId);
        $body = $request->toArray();
        $text = trim((string) ($body['text'] ?? ''));
        if ('' === $text || mb_strlen($text) > 5000) {
            throw new HttpException(422, 'Message vide ou trop long (5000 caractères au plus).');
        }
        if (!Uuid::isValid((string) ($body['messageId'] ?? ''))) {
            throw new HttpException(422, 'Identifiant de message invalide.');
        }
        $lodgify->sendMessage($bookingId, nl2br(htmlspecialchars($text, \ENT_QUOTES)), (string) $body['messageId']);

        return $this->json(['ok' => true]);
    }

    /**
     * Access grant of Rocket Place as the keypad code of a booking (same shape as before the move to Rocket Place).
     *
     * @param array<string, mixed> $grant
     *
     * @return array<string, mixed>
     */
    public static function access(array $grant, Booking $b, bool $outdated): array
    {
        return [
            'grantId' => $grant['id'], 'bookingId' => $b->id, 'lockId' => $grant['lockId'] ?? null, 'code' => $grant['code'] ?? '',
            'validFrom' => $grant['validFrom'], 'validUntil' => $grant['validUntil'], 'status' => $grant['status'],
            'error' => $grant['error'] ?? null, 'outdated' => $outdated,
        ];
    }

    private function bookingOf(BookingProviderInterface $lodgify, Property $property, int $bookingId): Booking
    {
        $b = $lodgify->booking($bookingId);
        if (null === $b || $b->propertyId !== $property->getLodgifyPropertyId()) {
            throw new HttpException(404, 'Réservation inconnue pour ce logement.');
        }

        return $b;
    }

    private static function toText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h\d)>#i'], "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
