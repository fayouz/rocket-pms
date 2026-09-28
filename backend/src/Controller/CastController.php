<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Lodgify\Booking;
use App\Repository\WelcomeBookRepository;
use App\WelcomeBook\WelcomeBookViews;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Screen of a property for Rocket Cast (source "rocket_pms"), read-only with an application token (rpm_…): the same
 * payload as the public TV route (GET /api/public/tv/{token}) plus today's arrivals and departures, and a "version"
 * (hash of the welcome book and of the current / next guest) so Cast only redraws when something changed. Never a
 * keypad code, first names only. No visit counted (the only write: the empty welcome book on first read).
 */
#[IsGranted('PMS_READ')]
final class CastController extends AbstractController
{
    public function __construct(
        private readonly WelcomeBookRepository $books,
        private readonly EntityManagerInterface $em,
        private readonly WelcomeBookViews $views,
        private readonly AccessCodePlanner $planner,
    ) {
    }

    #[Route('/api/cast/properties/{id}', name: 'api_cast_property', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function property(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $book = $this->books->forProperty($property);
        $this->em->flush(); // first read creates the (empty) welcome book, as the admin tab does
        $data = $this->views->tv($book, $book->pickLanguage($request->query->getString('lang'), $request->getLanguages()));
        $data['style']['coverPath'] = $data['style']['documentCover'] ? '/api/public/tv/'.$book->getTvToken().'/cover' : null;
        $data['propertyId'] = $property->getId()->toRfc4122();
        $today = $this->planner->today();
        $arrivals = [];
        $departures = [];
        foreach ($this->planner->bookingsOf($property) as $b) {
            if (!$b->isActive()) {
                continue;
            }
            if ($b->arrival === $today) {
                $arrivals[] = self::stay($b);
            }
            if ($b->departure === $today) {
                $departures[] = self::stay($b);
            }
        }
        $data['arrivals'] = $arrivals;
        $data['departures'] = $departures;
        $data['version'] = substr(hash('sha256', json_encode([
            $book->getContent(), $book->getTranslations(), $book->getStyle(), $book->getUpdatedAt()?->format(\DATE_ATOM),
            $data['lang'], $data['guest'], $data['nextArrival'], $arrivals, $departures, $today,
        ], \JSON_THROW_ON_ERROR)), 0, 16);

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array{firstName: string, arrival: string, departure: string, checkIn: ?string, checkOut: ?string} */
    private static function stay(Booking $b): array
    {
        return ['firstName' => WelcomeBookViews::firstName($b->guest), 'arrival' => $b->arrival, 'departure' => $b->departure, 'checkIn' => $b->checkIn, 'checkOut' => $b->checkOut];
    }
}
