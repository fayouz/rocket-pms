<?php

namespace App\Controller;

use App\Entity\Property;
use App\Lodgify\Booking;
use App\Lodgify\BookingProviderRegistry;
use App\Mailer\InboxUnavailable;
use App\Mailer\MailerClient;
use Psr\Cache\CacheItemPoolInterface;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * E-mails of a booking, through Rocket Mailer (never IMAP/SMTP here), ported from LoussaHousing's linked e-mails:
 * the conversations of the shared inbox (ROCKET_MAILER_INBOX) linked to the booking (a participant is the guest's
 * e-mail, or the subject names the booking: "réservation 123", "#123"), their thread as plain text, and sending an
 * e-mail to the guest, only on an explicit click (messageId uuid: a double click sends once). Nothing is stored.
 */
#[IsGranted('PMS_READ')]
final class BookingEmailController extends AbstractController
{
    public function __construct(
        private readonly BookingProviderRegistry $bookingProviders,
        private readonly MailerClient $mailer,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/api/properties/{id}/bookings/{bookingId}/emails', name: 'api_booking_emails', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'bookingId' => Requirement::DIGITS])]
    public function list(#[MapEntity] Property $property, int $bookingId): JsonResponse
    {
        $b = $this->bookingOf($property, $bookingId);
        $out = ['demo' => $this->mailer->isDemo(), 'available' => true, 'reason' => null, 'guestEmail' => $b->guestEmail, 'conversations' => []];
        try {
            $found = [];
            foreach (array_filter([$b->guestEmail, (string) $b->id]) as $q) {
                foreach ($this->mailer->conversations($this->asUser(), (string) $q) as $c) {
                    $match = self::matchOf($c, $b);
                    if (null !== $match && \is_string($c['id'] ?? null)) {
                        $found[$c['id']] = self::summary($c) + ['matchedBy' => $match];
                    }
                }
            }
            usort($found, static fn (array $x, array $y) => strcmp((string) $y['lastMessageAt'], (string) $x['lastMessageAt']));
            $out['conversations'] = array_values($found);
        } catch (InboxUnavailable $e) {
            $out['available'] = false;
            $out['reason'] = $e->getMessage();
        }

        return $this->json($out);
    }

    #[Route('/api/properties/{id}/bookings/{bookingId}/emails/{conversationId}', name: 'api_booking_email_thread', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'bookingId' => Requirement::DIGITS, 'conversationId' => Requirement::UUID])]
    public function thread(#[MapEntity] Property $property, int $bookingId, string $conversationId): JsonResponse
    {
        $b = $this->bookingOf($property, $bookingId);
        try {
            $data = $this->mailer->conversation($this->asUser(), $conversationId);
        } catch (InboxUnavailable $e) {
            throw new HttpException(409, $e->getMessage());
        }
        $conversation = \is_array($data['conversation'] ?? null) ? $data['conversation'] : [];
        if (null === self::matchOf($conversation, $b)) {
            throw new HttpException(404, 'Cette conversation n’est pas liée à la réservation.');
        }
        $messages = [];
        foreach (\is_array($data['items'] ?? null) ? $data['items'] : [] as $i) {
            if (!\is_array($i) || !\in_array($i['type'] ?? null, ['inbound', 'reply'], true)) {
                continue; // internal notes of the shared inbox stay in Rocket Mailer
            }
            $messages[] = [
                'key' => 'e'.($i['id'] ?? ''), 'kind' => 'email', 'from' => 'inbound' === $i['type'] ? 'guest' : 'host',
                'at' => (string) ($i['at'] ?? ''), 'subject' => (string) ($i['subject'] ?? ''),
                'text' => '' !== trim((string) ($i['text'] ?? '')) ? (string) $i['text'] : self::toText((string) ($i['html'] ?? '')),
                'status' => (string) ($i['status'] ?? ''), 'fromAddress' => (string) ($i['from'] ?? ''),
            ];
        }
        usort($messages, static fn (array $x, array $y) => strcmp($x['at'], $y['at']));

        return $this->json(['conversation' => self::summary($conversation), 'messages' => $messages]);
    }

    /** JSON {"subject", "text", "messageId" (uuid)}: e-mail to the guest of the booking, through Rocket Mailer. */
    #[Route('/api/properties/{id}/bookings/{bookingId}/emails', name: 'api_booking_email_send', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'bookingId' => Requirement::DIGITS])]
    public function send(#[MapEntity] Property $property, int $bookingId, Request $request): JsonResponse
    {
        $b = $this->bookingOf($property, $bookingId);
        if (null === $b->guestEmail || !filter_var($b->guestEmail, \FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Lodgify ne donne pas d’adresse e-mail pour ce voyageur : écris-lui par la messagerie Lodgify.');
        }
        $body = $request->toArray();
        $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($body['subject'] ?? '')));
        $text = trim((string) ($body['text'] ?? ''));
        if ('' === $subject || mb_strlen($subject) > 200) {
            throw new HttpException(422, 'Objet vide ou trop long (200 caractères au plus).');
        }
        if ('' === $text || mb_strlen($text) > 10000) {
            throw new HttpException(422, 'Message vide ou trop long (10000 caractères au plus).');
        }
        $messageId = (string) ($body['messageId'] ?? '');
        if (!Uuid::isValid($messageId)) {
            throw new HttpException(422, 'Identifiant de message invalide.');
        }
        $item = $this->cache->getItem('pms_email_sent_'.str_replace('-', '', $messageId));
        if ($item->isHit()) {
            return $this->json(['ok' => true, 'duplicate' => true] + (array) $item->get());
        }
        $sent = $this->mailer->send($this->asUser(), ['to' => [$b->guestEmail], 'subject' => $subject, 'htmlBody' => nl2br(htmlspecialchars($text, \ENT_QUOTES))]);
        $result = ['id' => $sent['id'] ?? null, 'status' => $sent['status'] ?? null, 'demo' => $this->mailer->isDemo()];
        $this->cache->save($item->set($result)->expiresAfter(86400));

        return $this->json(['ok' => true, 'duplicate' => false] + $result);
    }

    /**
     * How a conversation is linked to the booking: "guest" (a participant is the guest's e-mail), "booking" (the
     * subject names the booking id), or null.
     *
     * @param array<string, mixed> $c
     */
    public static function matchOf(array $c, Booking $b): ?string
    {
        $email = null === $b->guestEmail ? '' : mb_strtolower(trim($b->guestEmail));
        if ('' !== $email) {
            foreach (array_merge((array) ($c['participants'] ?? []), [(string) ($c['lastFrom'] ?? '')]) as $p) {
                if (\is_string($p) && str_contains(mb_strtolower($p), $email)) {
                    return 'guest';
                }
            }
        }
        if (1 === preg_match('/(r[ée]servation|booking|resa|n°|#)\s*:?\s*#?'.$b->id.'(?!\d)/iu', (string) ($c['subject'] ?? ''))) {
            return 'booking';
        }

        return null;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private static function summary(array $c): array
    {
        return [
            'id' => (string) ($c['id'] ?? ''), 'subject' => (string) ($c['subject'] ?? ''), 'status' => (string) ($c['status'] ?? ''),
            'participants' => array_values(array_filter((array) ($c['participants'] ?? []), 'is_string')), 'snippet' => (string) ($c['snippet'] ?? ''),
            'messageCount' => (int) ($c['messageCount'] ?? 0), 'lastMessageAt' => (string) ($c['lastMessageAt'] ?? ''), 'unread' => (bool) ($c['unread'] ?? false),
        ];
    }

    private function bookingOf(Property $property, int $bookingId): Booking
    {
        $b = $this->bookingProviders->providerFor($property)->booking($bookingId);
        if (null === $b || $b->propertyId !== $property->getLodgifyPropertyId()) {
            throw new HttpException(404, 'Réservation inconnue pour ce logement.');
        }

        return $b;
    }

    /** Rocket Mailer acts on behalf of a user: the signed-in one (its e-mail). */
    private function asUser(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new HttpException(422, 'Les e-mails passent par Rocket Mailer au nom d’un utilisateur connecté.');
        }

        return $user->getUserIdentifier();
    }

    private static function toText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h\d)>#i'], "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
