<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Place\PlaceClient;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The physical side of a property, forwarded to its place in Rocket Place so the browser only ever talks to PMS:
 * locks, access grants (keypad codes of the stays), domotique, documents, stock. A property without a place answers
 * 409 with a clear message. Reads: any user; writes: admin (sending a code: any user, always on an explicit click).
 */
#[IsGranted('ROLE_USER')]
final class PlaceProxyController extends AbstractController
{
    private const ID = ['id' => Requirement::UUID];

    public function __construct(private readonly PlaceClient $place, private readonly AccessCodePlanner $planner)
    {
    }

    #[Route('/api/properties/{id}/locks', name: 'api_property_locks', methods: ['GET'], requirements: self::ID)]
    public function locks(#[MapEntity] Property $property): JsonResponse
    {
        return $this->json($this->place->request('GET', $this->base($property).'/locks'));
    }

    /** Live access grants of the property's place (planned first for the upcoming stays). */
    #[Route('/api/properties/{id}/access-grants', name: 'api_property_access_grants', methods: ['GET'], requirements: self::ID)]
    public function accessGrants(#[MapEntity] Property $property): JsonResponse
    {
        $base = $this->base($property);
        $this->planner->plan($property);

        return $this->json($this->place->request('GET', $base.'/access-grants'));
    }

    /** Keypad codes of the upcoming stays (planned if missing), with their guest: "Serrures" tab. */
    #[Route('/api/properties/{id}/codes', name: 'api_property_codes', methods: ['GET'], requirements: self::ID)]
    public function codes(#[MapEntity] Property $property): JsonResponse
    {
        $this->base($property);
        $grants = $this->planner->plan($property);
        $today = $this->planner->today();
        $items = [];
        foreach ($this->bookings($property) as $b) {
            if ($b->arrival <= $today || null === ($grant = $grants[(string) $b->id] ?? null)) {
                continue;
            }
            $items[] = PropertyController::access($grant, $b, $this->planner->isOutdated($grant, $b)) + ['guest' => $b->guest, 'source' => $b->source, 'arrival' => $b->arrival, 'departure' => $b->departure];
        }
        usort($items, static fn (array $a, array $c) => strcmp($a['arrival'], $c['arrival']));

        return $this->json(['demo' => $this->place->isDemo(), 'items' => $items]);
    }

    /** Writes the code of a grant to the lock: explicit user click only (the interface always asks for confirmation). */
    #[Route('/api/properties/{id}/access-grants/{grantId}/send', name: 'api_property_access_grant_send', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'grantId' => Requirement::UUID])]
    public function send(#[MapEntity] Property $property, string $grantId): JsonResponse
    {
        return $this->json($this->planner->send($property, $grantId));
    }

    #[Route('/api/properties/{id}/access-grants/{grantId}/revoke', name: 'api_property_access_grant_revoke', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'grantId' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(#[MapEntity] Property $property, string $grantId): JsonResponse
    {
        $base = $this->base($property);
        $ids = array_column($this->place->request('GET', $base.'/access-grants'), 'id');
        if (!\in_array($grantId, $ids, true)) {
            throw new HttpException(404, 'Accès introuvable pour ce logement.');
        }

        return $this->json($this->place->request('POST', '/api/access-grants/'.$grantId.'/revoke'));
    }

    #[Route('/api/properties/{id}/domotique', name: 'api_property_domotique', methods: ['GET'], requirements: self::ID)]
    public function domotique(#[MapEntity] Property $property): JsonResponse
    {
        return $this->json($this->place->request('GET', $this->base($property).'/domotique'));
    }

    #[Route('/api/properties/{id}/documents', name: 'api_property_documents', methods: ['GET'], requirements: self::ID)]
    public function documents(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $folder = (string) $request->query->get('folder', '');

        return $this->json($this->place->request('GET', $this->base($property).'/documents', null, '' === $folder ? [] : ['folder' => $folder]));
    }

    #[Route('/api/properties/{id}/documents/folders', name: 'api_property_documents_create_folder', methods: ['POST'], requirements: self::ID)]
    #[IsGranted('ROLE_ADMIN')]
    public function createFolder(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $body = $request->toArray();

        return $this->json($this->place->request('POST', $this->base($property).'/documents/folders', ['name' => (string) ($body['name'] ?? ''), 'folder' => $body['folder'] ?? null]), 201);
    }

    #[Route('/api/properties/{id}/documents/upload', name: 'api_property_documents_upload', methods: ['POST'], requirements: self::ID)]
    #[IsGranted('ROLE_ADMIN')]
    public function upload(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new HttpException(400, 'Fichier manquant ou invalide.');
        }
        $folder = $request->request->get('folder');

        return $this->json($this->place->upload($this->base($property).'/documents/upload', $file, null === $folder ? null : (string) $folder), 201);
    }

    #[Route('/api/properties/{id}/documents/{itemId}', name: 'api_property_documents_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID, 'itemId' => '[a-z]+:[\w-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateDocument(#[MapEntity] Property $property, string $itemId, Request $request): JsonResponse
    {
        $body = array_intersect_key($request->toArray(), ['name' => 1, 'folder' => 1]);

        return $this->json($this->place->request('PATCH', $this->base($property).'/documents/'.$itemId, $body));
    }

    #[Route('/api/properties/{id}/documents/{itemId}', name: 'api_property_documents_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID, 'itemId' => '[a-z]+:[\w-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteDocument(#[MapEntity] Property $property, string $itemId, Request $request): JsonResponse
    {
        $folder = (string) $request->query->get('folder', '');

        return $this->json($this->place->request('DELETE', $this->base($property).'/documents/'.$itemId, null, '' === $folder ? [] : ['folder' => $folder]));
    }

    #[Route('/api/properties/{id}/documents/{itemId}/content', name: 'api_property_documents_content', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'itemId' => '[a-z]+:[\w-]+'])]
    public function content(#[MapEntity] Property $property, string $itemId): Response
    {
        return new Response($this->place->content($this->base($property).'/documents/'.$itemId.'/content'), 200, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Stock of the place: the catalogue items and this place's levels (ok, low, empty). */
    #[Route('/api/properties/{id}/stock', name: 'api_property_stock', methods: ['GET'], requirements: self::ID)]
    public function stock(#[MapEntity] Property $property): JsonResponse
    {
        $placeIri = '/api/places/'.PlaceClient::placeIdOf($property);
        $items = $this->place->request('GET', '/api/stock-items');
        $levels = $this->place->request('GET', '/api/stock-levels', null, ['place' => $placeIri]);

        return $this->json(['items' => array_values($items), 'levels' => array_values($levels)]);
    }

    /** JSON {"level": "ok"|"low"|"empty"}: only a level of this property's place. */
    #[Route('/api/properties/{id}/stock/{levelId}', name: 'api_property_stock_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID, 'levelId' => Requirement::UUID])]
    public function updateStock(#[MapEntity] Property $property, string $levelId, Request $request): JsonResponse
    {
        $placeIri = '/api/places/'.PlaceClient::placeIdOf($property);
        $mine = array_column($this->place->request('GET', '/api/stock-levels', null, ['place' => $placeIri]), 'id');
        if (!\in_array($levelId, $mine, true)) {
            throw new HttpException(404, 'Niveau de stock introuvable pour ce logement.');
        }

        return $this->json($this->place->request('PATCH', '/api/stock-levels/'.$levelId, ['level' => (string) ($request->toArray()['level'] ?? '')]));
    }

    private function base(Property $property): string
    {
        return '/api/places/'.PlaceClient::placeIdOf($property);
    }

    /** @return list<\App\Lodgify\Booking> */
    private function bookings(Property $property): array
    {
        return array_values(array_filter(
            $this->planner->bookingsOf($property),
            static fn (\App\Lodgify\Booking $b) => $b->isActive(),
        ));
    }
}
