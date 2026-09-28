<?php

namespace App\Controller;

use App\Entity\Property;
use App\Place\PlaceClient;
use Doctrine\ORM\EntityManagerInterface;
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
 * Administration of the link between a property and its place in Rocket Place: list the places, link/unlink a
 * property, create a place from a property; and which place each lock opens (locks belong to Rocket Place).
 */
#[IsGranted('ROLE_ADMIN')]
final class PlaceLinkController extends AbstractController
{
    public function __construct(private readonly PlaceClient $place, private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/places', name: 'api_places', methods: ['GET'])]
    public function places(): JsonResponse
    {
        $places = array_map(static fn (array $p) => array_intersect_key($p, array_flip(['id', 'name', 'address', 'color'])), $this->place->request('GET', '/api/places'));

        return $this->json(['demo' => $this->place->isDemo(), 'places' => array_values($places)]);
    }

    /** JSON {"placeId": "<uuid>"|null}: the place must exist in Rocket Place. */
    #[Route('/api/properties/{id}/place', name: 'api_property_place_link', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function link(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $placeId = $request->toArray()['placeId'] ?? null;
        if (null !== $placeId) {
            if (!\is_string($placeId) || !Uuid::isValid($placeId)) {
                throw new HttpException(422, 'Identifiant de lieu invalide.');
            }
            $placeId = $this->place->request('GET', '/api/places/'.$placeId)['id'] ?? throw new HttpException(422, 'Lieu inconnu de Rocket Place.');
        }
        $property->setPlaceId($placeId);
        $this->em->flush();

        return $this->json(['id' => $property->getId()->toRfc4122(), 'placeId' => $property->getPlaceId()]);
    }

    /** Creates a place in Rocket Place from the property (name, colour, coordinates) and links it. */
    #[Route('/api/properties/{id}/place', name: 'api_property_place_create', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function create(#[MapEntity] Property $property): JsonResponse
    {
        if (null !== $property->getPlaceId()) {
            throw new HttpException(409, 'Ce logement est déjà lié à un lieu Rocket Place.');
        }
        $created = $this->place->request('POST', '/api/places', [
            'name' => $property->getName(), 'color' => $property->getColor(),
            'latitude' => $property->getLatitude(), 'longitude' => $property->getLongitude(),
        ]);
        $property->setPlaceId((string) ($created['id'] ?? throw new HttpException(502, 'Réponse inattendue de Rocket Place.')));
        $this->em->flush();

        return $this->json(['id' => $property->getId()->toRfc4122(), 'placeId' => $property->getPlaceId()], 201);
    }

    /** Every lock known to Rocket Place with the place it opens. */
    #[Route('/api/locks', name: 'api_locks', methods: ['GET'])]
    public function locks(): JsonResponse
    {
        return $this->json($this->place->request('GET', '/api/locks'));
    }

    /** JSON {"place": "<uuid>"|null}: which place a lock opens (stored in Rocket Place). */
    #[Route('/api/locks/{nukiId}', name: 'api_lock_link', methods: ['PUT'], requirements: ['nukiId' => '\d+'])]
    public function linkLock(int $nukiId, Request $request): JsonResponse
    {
        $place = $request->toArray()['place'] ?? null;
        if (null !== $place && (!\is_string($place) || !Uuid::isValid($place))) {
            throw new HttpException(422, 'Identifiant de lieu invalide.');
        }

        return $this->json($this->place->request('PUT', '/api/locks/'.$nukiId, ['place' => $place]));
    }
}
