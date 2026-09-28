<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Entity\WelcomeBook;
use App\Repository\PropertyRepository;
use App\Lodgify\Booking;
use App\Lodgify\BookingProviderRegistry;
use App\Mailer\MailerClient;
use App\Place\PlaceClient;
use App\Repository\WelcomeBookRepository;
use App\Repository\WelcomeBookVisitRepository;
use App\WelcomeBook\GuestLinkSigner;
use App\WelcomeBook\PublicRateLimiter;
use App\WelcomeBook\WelcomeBookViews;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Welcome book (livret d'accueil) and TV screen of a property. Signed-in: read (PMS_READ), edit and rotate the links
 * (PMS_MANAGE), get the guest link of a booking. Public (/api/public/…, no account, rate-limited): the guest page by
 * per-booking signed token, the kiosk TV screen by per-property token. Unknown and expired links both answer 404.
 * Public pages pick their language from ?lang= then Accept-Language (French by default), count one visit per page load
 * and day (no IP stored), and serve the cover image when it is a Rocket Place document. Sending the guest link to the
 * guest (Lodgify message or e-mail through Rocket Mailer) is only ever an explicit click (::sendGuestLink).
 */
final class WelcomeBookController extends AbstractController
{
    public function __construct(
        private readonly WelcomeBookRepository $books,
        private readonly EntityManagerInterface $em,
        private readonly GuestLinkSigner $signer,
        private readonly WelcomeBookViews $views,
        private readonly WelcomeBookVisitRepository $visits,
        private readonly PlaceClient $place,
        #[Autowire('%env(FRONTEND_URL)%')] private readonly string $frontendUrl,
        #[Autowire('%env(ROCKET_CAST_FRONT_URL)%')] private readonly string $castFrontUrl = '',
    ) {
    }

    #[Route('/api/properties/{id}/welcome-book', name: 'api_welcome_book', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_READ')]
    public function show(#[MapEntity] Property $property): JsonResponse
    {
        $book = $this->books->forProperty($property);
        $this->em->flush();

        return $this->json($this->admin($book));
    }

    #[Route('/api/properties/{id}/welcome-book', name: 'api_welcome_book_update', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_MANAGE')]
    public function update(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $book = $this->books->forProperty($property)->updateContent(\is_array($data['content'] ?? null) ? $data['content'] : []);
        if (\is_array($data['translations'] ?? null)) {
            $book->updateTranslations($data['translations']);
        }
        if (\is_array($data['style'] ?? null)) {
            $book->updateStyle($data['style']);
            if (null !== $book->getStyle()['coverDocumentRef']) {
                PlaceClient::placeIdOf($property); // a document cover needs the place holding the document
            }
        }
        $this->em->flush();

        return $this->json($this->admin($book));
    }

    /** JSON {"link": "tv"|"guest"}: new TV token, or new guest salt (every guest link sent so far stops working). */
    #[Route('/api/properties/{id}/welcome-book/rotate', name: 'api_welcome_book_rotate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_MANAGE')]
    public function rotate(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $book = $this->books->forProperty($property);
        match ($request->toArray()['link'] ?? null) {
            'tv' => $book->rotateTvToken(),
            'guest' => $book->rotateGuestSalt(),
            default => throw new HttpException(422, 'Lien inconnu (tv ou guest).'),
        };
        $this->em->flush();

        return $this->json($this->admin($book));
    }

    #[Route('/api/properties/{id}/bookings/{bookingId}/guest-link', name: 'api_booking_guest_link', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'bookingId' => Requirement::DIGITS])]
    #[IsGranted('PMS_READ')]
    public function guestLink(#[MapEntity] Property $property, int $bookingId): JsonResponse
    {
        $book = $this->books->forProperty($property);
        $booking = $this->views->booking($book, $bookingId) ?? throw new NotFoundHttpException('Réservation inconnue pour ce logement.');
        $this->em->flush();

        $token = $this->signer->sign($book, $bookingId);

        return $this->json(['token' => $token, 'path' => '/g/'.$token, 'url' => $this->absolute('/g/'.$token), 'message' => self::message($booking, $this->absolute('/g/'.$token), 'fr')] + WelcomeBookViews::window($booking));
    }

    /**
     * Sends the guest link to the guest, ONLY on an explicit click: JSON {"channel": "lodgify"|"email", "messageId"
     * (uuid, a double click sends once), "text"? (the link is appended when missing), "lang"?}.
     */
    #[Route('/api/properties/{id}/bookings/{bookingId}/guest-link/send', name: 'api_booking_guest_link_send', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'bookingId' => Requirement::DIGITS])]
    #[IsGranted('PMS_READ')]
    public function sendGuestLink(#[MapEntity] Property $property, int $bookingId, Request $request, BookingProviderRegistry $providers, MailerClient $mailer, #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache): JsonResponse
    {
        $book = $this->books->forProperty($property);
        $booking = $this->views->booking($book, $bookingId) ?? throw new NotFoundHttpException('Réservation inconnue pour ce logement.');
        if (!$booking->isActive()) {
            throw new HttpException(409, 'Cette réservation n’est pas active : aucun lien n’est envoyé.');
        }
        $this->em->flush();
        $body = $request->toArray();
        $messageId = (string) ($body['messageId'] ?? '');
        if (!Uuid::isValid($messageId)) {
            throw new HttpException(422, 'Identifiant de message invalide.');
        }
        $lang = \in_array($body['lang'] ?? null, WelcomeBook::LANGUAGES, true) ? $body['lang'] : WelcomeBook::DEFAULT_LANGUAGE;
        $url = $this->absolute('/g/'.$this->signer->sign($book, $bookingId));
        $text = trim((string) ($body['text'] ?? ''));
        $text = '' === $text ? self::message($booking, $url, $lang) : (str_contains($text, $url) ? $text : $text."\n\n".$url);
        if (mb_strlen($text) > 5000) {
            throw new HttpException(422, 'Message trop long (5000 caractères au plus).');
        }
        $item = $cache->getItem('pms_guest_link_sent_'.str_replace('-', '', $messageId));
        if ($item->isHit()) {
            return $this->json(['ok' => true, 'duplicate' => true] + (array) $item->get());
        }
        $html = nl2br(htmlspecialchars($text, \ENT_QUOTES));
        $channel = $body['channel'] ?? null;
        if ('lodgify' === $channel) {
            $providers->providerFor($property)->sendMessage($bookingId, $html, $messageId);
            $result = ['channel' => 'lodgify'];
        } elseif ('email' === $channel) {
            if (null === $booking->guestEmail || !filter_var($booking->guestEmail, \FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(422, 'Lodgify ne donne pas d’adresse e-mail pour ce voyageur : envoie le lien par la messagerie Lodgify.');
            }
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new HttpException(422, 'Les e-mails passent par Rocket Mailer au nom d’un utilisateur connecté.');
            }
            $sent = $mailer->send($user->getUserIdentifier(), ['to' => [$booking->guestEmail], 'subject' => self::SUBJECTS[$lang].' · '.$property->getName(), 'htmlBody' => $html]);
            $result = ['channel' => 'email', 'id' => $sent['id'] ?? null, 'demo' => $mailer->isDemo()];
        } else {
            throw new HttpException(422, 'Canal inconnu (lodgify ou email).');
        }
        $cache->save($item->set($result)->expiresAfter(86400));

        return $this->json(['ok' => true, 'duplicate' => false] + $result);
    }

    /** Visits of the public pages over the last ?days= (default 30, max 365), per link and per day. No visitor data. */
    #[Route('/api/properties/{id}/welcome-book/stats', name: 'api_welcome_book_stats', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('PMS_READ')]
    public function stats(#[MapEntity] Property $property, Request $request, AccessCodePlanner $planner): JsonResponse
    {
        $book = $this->books->forProperty($property);
        $this->em->flush();
        $days = max(1, min(365, $request->query->getInt('days', 30)));
        $rows = $this->visits->since($book, new \DateTimeImmutable($planner->today().' -'.($days - 1).' days'));
        $guests = [];
        try {
            foreach ($planner->bookingsOf($property) as $b) {
                $guests[$b->id] = $b->guest;
            }
        } catch (HttpException) {
            // Lodgify unreachable: the counters are still shown, without the guest names
        }
        $links = [];
        foreach ($rows as $r) {
            $key = $r['link'].':'.$r['bookingId'];
            $links[$key] ??= ['link' => $r['link'], 'bookingId' => 'tv' === $r['link'] ? null : $r['bookingId'], 'guest' => 'tv' === $r['link'] ? null : ($guests[$r['bookingId']] ?? null), 'total' => 0, 'lastDay' => $r['day']];
            $links[$key]['total'] += $r['count'];
        }

        return $this->json(['days' => $days, 'total' => array_sum(array_column($rows, 'count')), 'links' => array_values($links), 'daily' => $rows]);
    }

    #[Route('/api/public/guest/{token}', name: 'api_public_guest', methods: ['GET'])]
    public function guest(string $token, Request $request, PropertyRepository $properties, PublicRateLimiter $limiter, AccessCodePlanner $planner): JsonResponse
    {
        [$book, $booking] = $this->guestBook($token, $request, $properties, $limiter, $planner);
        $lang = $book->pickLanguage($request->query->getString('lang'), $request->getLanguages());
        $this->visits->record($book, 'guest', $booking->id, $planner->today());
        $data = $this->views->guest($book, $booking, $lang);
        $data['style']['coverPath'] = $data['style']['documentCover'] ? '/api/public/guest/'.$token.'/cover' : null;

        return $this->publicJson($data);
    }

    #[Route('/api/public/guest/{token}/cover', name: 'api_public_guest_cover', methods: ['GET'])]
    public function guestCover(string $token, Request $request, PropertyRepository $properties, PublicRateLimiter $limiter, AccessCodePlanner $planner): Response
    {
        return $this->cover($this->guestBook($token, $request, $properties, $limiter, $planner)[0]);
    }

    /** @return array{0: WelcomeBook, 1: Booking} the book and booking of a valid guest link, within its window */
    private function guestBook(string $token, Request $request, PropertyRepository $properties, PublicRateLimiter $limiter, AccessCodePlanner $planner): array
    {
        $limiter->hit($request);
        [$propertyId, $bookingId] = GuestLinkSigner::parse($token) ?? [null, null];
        $property = null === $propertyId ? null : $properties->find($propertyId);
        $book = null === $property ? null : $this->books->findOneBy(['property' => $property]);
        $booking = null !== $book && $this->signer->verify($book, $token) ? $this->views->booking($book, (int) $bookingId) : null;
        if (null === $book || null === $booking) {
            $limiter->failure($request);
            throw new NotFoundHttpException('Lien invalide.');
        }
        $window = WelcomeBookViews::window($booking);
        $today = $planner->today();
        if (!$booking->isActive() || $today < $window['from'] || $today > $window['until']) {
            throw new NotFoundHttpException($today < $window['from'] ? 'Ce livret sera disponible deux jours avant ton arrivée.' : 'Ce lien a expiré.');
        }

        return [$book, $booking];
    }

    #[Route('/api/public/tv/{token}', name: 'api_public_tv', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function tv(string $token, Request $request, PublicRateLimiter $limiter, AccessCodePlanner $planner): JsonResponse
    {
        $book = $this->tvBook($token, $request, $limiter);
        $this->visits->record($book, 'tv', 0, $planner->today());
        $data = $this->views->tv($book, $book->pickLanguage($request->query->getString('lang'), $request->getLanguages()));
        $data['style']['coverPath'] = $data['style']['documentCover'] ? '/api/public/tv/'.$token.'/cover' : null;

        return $this->publicJson($data);
    }

    #[Route('/api/public/tv/{token}/cover', name: 'api_public_tv_cover', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function tvCover(string $token, Request $request, PublicRateLimiter $limiter): Response
    {
        return $this->cover($this->tvBook($token, $request, $limiter));
    }

    private function tvBook(string $token, Request $request, PublicRateLimiter $limiter): WelcomeBook
    {
        $limiter->hit($request);
        $book = $this->books->findOneBy(['tvToken' => $token]);
        if (null === $book) {
            $limiter->failure($request);
            throw new NotFoundHttpException('Lien invalide.');
        }

        return $book;
    }

    /** Cover image stored as a Rocket Place document of the property's place: only images are served (sniffed, not trusted). */
    private function cover(WelcomeBook $book): Response
    {
        $ref = $book->getStyle()['coverDocumentRef'];
        $placeId = $book->getProperty()->getPlaceId();
        if (null === $ref || null === $placeId) {
            throw new NotFoundHttpException('Pas d’image de couverture.');
        }
        try {
            $bytes = $this->place->content('/api/places/'.$placeId.'/documents/'.$ref.'/content');
        } catch (HttpException) {
            throw new NotFoundHttpException('Image de couverture indisponible.');
        }
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!\in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new NotFoundHttpException('Le document de couverture n’est pas une image.');
        }

        return new Response($bytes, 200, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'", 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** @return array<string, mixed> */
    private function admin(WelcomeBook $book): array
    {
        return [
            'content' => $book->getContent(), 'translations' => $book->getTranslations(), 'languages' => WelcomeBook::LANGUAGES, 'style' => $book->getStyle(),
            'tvToken' => $book->getTvToken(), 'tvPath' => '/tv/'.$book->getTvToken(), 'tvUrl' => $this->absolute('/tv/'.$book->getTvToken()),
            'updatedAt' => $book->getUpdatedAt()?->format(\DATE_ATOM),
            // Rocket Cast (source rocket_pms): front to open, the property to pick there
            'castFrontUrl' => '' === trim($this->castFrontUrl) ? null : rtrim($this->castFrontUrl, '/'),
            'propertyId' => $book->getProperty()->getId()->toRfc4122(),
        ];
    }

    private const SUBJECTS = ['fr' => 'Votre livret d’accueil', 'en' => 'Your welcome book', 'es' => 'Su guía de bienvenida', 'de' => 'Ihr Gästehandbuch', 'it' => 'La vostra guida di benvenuto'];
    private const GREETINGS = [
        'fr' => "Bonjour %s,\n\nVoici votre livret d’accueil (Wi-Fi, arrivée, départ, bonnes adresses) :\n%s\n\nÀ très bientôt !",
        'en' => "Hello %s,\n\nHere is your welcome book (Wi-Fi, check-in, check-out, local tips):\n%s\n\nSee you soon!",
        'es' => "Hola %s,\n\nAquí tiene su guía de bienvenida (wifi, llegada, salida, recomendaciones):\n%s\n\n¡Hasta pronto!",
        'de' => "Hallo %s,\n\nhier ist Ihr Gästehandbuch (WLAN, Anreise, Abreise, Tipps):\n%s\n\nBis bald!",
        'it' => "Buongiorno %s,\n\necco la vostra guida di benvenuto (Wi-Fi, arrivo, partenza, consigli):\n%s\n\nA presto!",
    ];

    /** Default message carrying the guest link, in the guest's language. */
    private static function message(Booking $b, string $url, string $lang): string
    {
        return \sprintf(self::GREETINGS[$lang] ?? self::GREETINGS['fr'], WelcomeBookViews::firstName($b->guest), $url);
    }

    /** Absolute URL of a frontend page (FRONTEND_URL), for links sent outside the app. */
    private function absolute(string $path): string
    {
        return rtrim($this->frontendUrl, '/').$path;
    }

    /** @param array<string, mixed> $data */
    private function publicJson(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
