<?php

namespace App\Controller;

use App\Entity\Property;
use App\Clean\CleanClient;
use App\Place\PlaceClient;
use App\Stock\StockClient;
use App\Repository\PropertyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Overview of the links between PMS properties and Rocket Place places (Administration › Liaisons Place): per property
 * its Lodgify id, its place and a status ("linked", "unlinked", "missing" = place unknown to Rocket Place,
 * "unreachable" = Rocket Place failed), with counts read from Place (locks, upcoming access grants), Rocket Clean (open
 * cleanings) and Rocket Stock (low or empty stock). Read-only: linking stays on PUT|POST /api/properties/{id}/place. Demo Place without ROCKET_PLACE_URL.
 */
#[IsGranted('PMS_MANAGE')]
final class PlaceLinksOverviewController extends AbstractController
{
    public function __construct(
        private readonly PlaceClient $place,
        private readonly CleanClient $clean,
        private readonly StockClient $stock,
        #[Autowire('%env(ROCKET_PLACE_FRONT_URL)%')] private readonly string $placeFrontUrl,
    ) {
    }

    #[Route('/api/place-links', name: 'api_place_links', methods: ['GET'])]
    public function overview(PropertyRepository $properties): JsonResponse
    {
        $error = null;
        try {
            $places = array_column(array_filter($this->place->request('GET', '/api/places'), 'is_array'), null, 'id');
        } catch (HttpException $e) {
            [$places, $error] = [null, $e->getMessage()];
        }

        return $this->json([
            'demo' => $this->place->isDemo(),
            'placeFrontUrl' => '' === trim($this->placeFrontUrl) ? null : rtrim($this->placeFrontUrl, '/'),
            'error' => $error,
            'places' => null === $places ? [] : array_values(array_map(static fn (array $p) => ['id' => $p['id'], 'name' => $p['name'] ?? ''], $places)),
            'properties' => array_map(fn (Property $p) => $this->row($p, $places), $properties->findBy([], ['name' => 'ASC'])),
        ]);
    }

    /** @param array<string, array<string, mixed>>|null $places @return array<string, mixed> */
    private function row(Property $p, ?array $places): array
    {
        $placeId = $p->getPlaceId();
        $row = [
            'id' => $p->getId()->toRfc4122(), 'name' => $p->getName(), 'color' => $p->getColor(), 'lodgifyPropertyId' => $p->getLodgifyPropertyId(),
            'placeId' => $placeId, 'placeName' => null, 'status' => null === $placeId ? 'unlinked' : 'linked', 'error' => null, 'counts' => null,
        ];
        if (null === $placeId) {
            return $row;
        }
        if (null === $places) {
            return ['status' => 'unreachable'] + $row;
        }
        if (!isset($places[$placeId])) {
            return ['status' => 'missing'] + $row;
        }
        $row['placeName'] = (string) ($places[$placeId]['name'] ?? '');
        try {
            $row['counts'] = $this->counts($placeId);
        } catch (HttpException $e) {
            [$row['status'], $row['error']] = ['unreachable', $e->getMessage()];
        }

        return $row;
    }

    /** @return array{locks: int, upcomingGrants: int, openCleanings: int, lowStock: int} */
    private function counts(string $placeId): array
    {
        $now = new \DateTimeImmutable();
        $grants = array_filter($this->place->request('GET', '/api/places/'.$placeId.'/access-grants'), static fn ($g) => \is_array($g)
            && 'revoked' !== ($g['status'] ?? null) && \is_string($g['validUntil'] ?? null) && new \DateTimeImmutable($g['validUntil']) > $now);
        try {
            $cleanings = array_filter($this->clean->cleanings($placeId), static fn (array $c) => \in_array($c['status'] ?? null, ['todo', 'in_progress'], true));
        } catch (HttpException) {
            $cleanings = []; // Rocket Clean unreachable: no count
        }
        try {
            $levels = array_filter($this->stock->levels($placeId), static fn (array $l) => \in_array($l['level'] ?? null, ['low', 'empty'], true));
        } catch (HttpException) {
            $levels = []; // Rocket Stock unreachable: no count
        }

        return [
            'locks' => \count($this->place->request('GET', '/api/places/'.$placeId.'/locks')['locks'] ?? []),
            'upcomingGrants' => \count($grants), 'openCleanings' => \count($cleanings), 'lowStock' => \count($levels),
        ];
    }
}
