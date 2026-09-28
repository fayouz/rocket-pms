<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Entity\WelcomeBook;
use App\Repository\PropertyRepository;
use App\Repository\WelcomeBookRepository;
use App\WelcomeBook\GuestLinkSigner;
use App\WelcomeBook\PublicRateLimiter;
use App\WelcomeBook\WelcomeBookViews;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Welcome book (livret d'accueil) and TV screen of a property. Signed-in: read (PMS_READ), edit and rotate the links
 * (PMS_MANAGE), get the guest link of a booking. Public (/api/public/…, no account, rate-limited): the guest page by
 * per-booking signed token, the kiosk TV screen by per-property token. Unknown and expired links both answer 404.
 */
final class WelcomeBookController extends AbstractController
{
    public function __construct(
        private readonly WelcomeBookRepository $books,
        private readonly EntityManagerInterface $em,
        private readonly GuestLinkSigner $signer,
        private readonly WelcomeBookViews $views,
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
        $book = $this->books->forProperty($property)->updateContent($request->toArray()['content'] ?? []);
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

        return $this->json(['token' => $token, 'path' => '/g/'.$token] + WelcomeBookViews::window($booking));
    }

    #[Route('/api/public/guest/{token}', name: 'api_public_guest', methods: ['GET'])]
    public function guest(string $token, Request $request, PropertyRepository $properties, PublicRateLimiter $limiter, AccessCodePlanner $planner): JsonResponse
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

        return $this->publicJson($this->views->guest($book, $booking));
    }

    #[Route('/api/public/tv/{token}', name: 'api_public_tv', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function tv(string $token, Request $request, PublicRateLimiter $limiter): JsonResponse
    {
        $limiter->hit($request);
        $book = $this->books->findOneBy(['tvToken' => $token]);
        if (null === $book) {
            $limiter->failure($request);
            throw new NotFoundHttpException('Lien invalide.');
        }

        return $this->publicJson($this->views->tv($book));
    }

    /** @return array<string, mixed> */
    private function admin(WelcomeBook $book): array
    {
        return ['content' => $book->getContent(), 'tvToken' => $book->getTvToken(), 'tvPath' => '/tv/'.$book->getTvToken(), 'updatedAt' => $book->getUpdatedAt()?->format(\DATE_ATOM)];
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
