<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Lock\LockProviderRegistry;
use App\Lodgify\LodgifyClient;
use App\Repository\AccessCodeRepository;
use App\Repository\ConnectorRepository;
use App\Repository\PropertyRepository;
use App\Repository\SmartLockRepository;
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

/** Nuki locks (live state, last events), their link to a property, and the keypad codes of upcoming stays. */
#[IsGranted('ROLE_USER')]
final class LockController extends AbstractController
{
    public function __construct(
        private readonly LockProviderRegistry $lockProviders,
        private readonly SmartLockRepository $locks,
        private readonly AccessCodeRepository $codes,
        private readonly AccessCodePlanner $planner,
        private readonly LodgifyClient $lodgify,
    ) {
    }

    /** Every lock with the property it opens (Administration: choose the property). */
    #[Route('/api/locks', name: 'api_locks', methods: ['GET'])]
    public function all(): JsonResponse
    {
        $legacy = $this->lockProviders->legacy();

        return $this->json(['demo' => $legacy->isDemo(), 'locks' => $this->withProperty($legacy->listLocks())]);
    }

    /**
     * JSON {"property": "<uuid>"|null, "connector": "<uuid>"|null, "codeConnector": "<uuid>"|null, "externalId": string|null}.
     * `connector`/`codeConnector` reroute this lock's live state / code creation to another connector (its plugin
     * must implement App\Lock\LockCapablePluginInterface); leave null to keep the legacy env-token Nuki API.
     */
    #[Route('/api/locks/{nukiId}', name: 'api_lock_link', methods: ['PUT'], requirements: ['nukiId' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function link(int $nukiId, Request $request, PropertyRepository $properties, ConnectorRepository $connectors, EntityManagerInterface $em): JsonResponse
    {
        $lock = $this->locks->find($nukiId) ?? throw new HttpException(404, 'Serrure inconnue : lance d’abord la synchronisation.');
        $body = $request->toArray();
        if (\array_key_exists('property', $body)) {
            $lock->setProperty($this->resolveUuid($body['property'], $properties, 'Logement inconnu.'));
        }
        if (\array_key_exists('connector', $body)) {
            $lock->setConnector($this->resolveUuid($body['connector'], $connectors, 'Connecteur inconnu.'));
        }
        if (\array_key_exists('codeConnector', $body)) {
            $lock->setCodeConnector($this->resolveUuid($body['codeConnector'], $connectors, 'Connecteur inconnu.'));
        }
        if (\array_key_exists('externalId', $body)) {
            $lock->setExternalId(null === $body['externalId'] ? null : (string) $body['externalId']);
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    private function resolveUuid(mixed $id, PropertyRepository|ConnectorRepository $repo, string $errorLabel): ?object
    {
        if (null === $id) {
            return null;
        }

        return (Uuid::isValid((string) $id) ? $repo->find(Uuid::fromString((string) $id)) : null) ?? throw new HttpException(422, $errorLabel);
    }

    #[Route('/api/properties/{id}/locks', name: 'api_property_locks', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function ofProperty(#[MapEntity] Property $property): JsonResponse
    {
        $legacy = $this->lockProviders->legacy();
        $mine = array_values(array_filter($this->withProperty($legacy->listLocks()), static fn (array $l) => $l['propertyId'] === $property->getId()->toRfc4122()));

        // A lock rerouted to another connector for its live state (Home Assistant, Homey...): refresh its entry.
        foreach ($this->locks->findBy(['property' => $property]) as $lock) {
            if (null === $lock->getConnector()) {
                continue;
            }
            $provider = $this->lockProviders->stateProviderFor($lock);
            $found = current(array_filter($provider->listLocks(), static fn (array $l) => $l['externalId'] === $lock->getExternalId()));
            foreach ($mine as $i => $l) {
                if ($l['id'] === $lock->getNukiId()) {
                    $mine[$i] = ($found ?: $l) + ['id' => $lock->getNukiId(), 'provider' => $provider->id(), 'propertyId' => $property->getId()->toRfc4122(), 'property' => $property->getName()];
                }
            }
        }

        return $this->json(['demo' => $legacy->isDemo(), 'locks' => $mine]);
    }

    /** Keypad codes of the upcoming stays of the property, planned if missing. */
    #[Route('/api/properties/{id}/codes', name: 'api_property_codes', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function codes(#[MapEntity] Property $property): JsonResponse
    {
        $this->planner->plan();
        $today = $this->planner->today();
        $items = [];
        $demo = true;
        foreach ($this->lodgify->bookings() as $b) {
            if ($b->propertyId !== $property->getLodgifyPropertyId() || $b->arrival <= $today || null === ($code = $this->codes->find($b->id))) {
                continue;
            }
            $demo = $this->lockProviders->codeProviderFor($code->getLock())->isDemo();
            $items[] = $code->toArray() + ['guest' => $b->guest, 'source' => $b->source, 'arrival' => $b->arrival, 'departure' => $b->departure, 'outdated' => $this->planner->isOutdated($code, $b)];
        }
        usort($items, static fn (array $a, array $c) => strcmp($a['arrival'], $c['arrival']));

        return $this->json(['demo' => $demo, 'items' => $items]);
    }

    /** Writes the code of an upcoming booking to its lock (the interface always asks for confirmation). */
    #[Route('/api/codes/{bookingId}', name: 'api_code_send', methods: ['POST'], requirements: ['bookingId' => '\d+'])]
    public function send(int $bookingId): JsonResponse
    {
        return $this->json($this->planner->send($bookingId)->toArray());
    }

    /** @param list<array<string, mixed>> $locks (from a provider: `externalId`, here the numeric Nuki id) @return list<array<string, mixed>> */
    private function withProperty(array $locks): array
    {
        return array_map(function (array $l) {
            $id = (int) $l['externalId'];
            $property = $this->locks->find($id)?->getProperty();
            unset($l['externalId']);

            return ['id' => $id] + $l + ['provider' => 'nuki', 'propertyId' => $property?->getId()->toRfc4122(), 'property' => $property?->getName()];
        }, $locks);
    }
}
