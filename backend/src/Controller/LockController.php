<?php

namespace App\Controller;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Lodgify\LodgifyClient;
use App\Nuki\NukiClient;
use App\Repository\AccessCodeRepository;
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
        private readonly NukiClient $nuki,
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
        return $this->json(['demo' => $this->nuki->isDemo(), 'locks' => $this->withProperty($this->nuki->locks())]);
    }

    /** JSON {"property": "<uuid>" | null}. */
    #[Route('/api/locks/{nukiId}', name: 'api_lock_link', methods: ['PUT'], requirements: ['nukiId' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function link(int $nukiId, Request $request, PropertyRepository $properties, EntityManagerInterface $em): JsonResponse
    {
        $lock = $this->locks->find($nukiId) ?? throw new HttpException(404, 'Serrure inconnue : lance d’abord la synchronisation.');
        $id = $request->toArray()['property'] ?? null;
        $property = null;
        if (null !== $id) {
            $property = Uuid::isValid((string) $id) ? $properties->find(Uuid::fromString((string) $id)) : null;
            $property ?? throw new HttpException(422, 'Logement inconnu.');
        }
        $lock->setProperty($property);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/api/properties/{id}/locks', name: 'api_property_locks', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function ofProperty(#[MapEntity] Property $property): JsonResponse
    {
        $mine = array_values(array_filter($this->withProperty($this->nuki->locks()), static fn (array $l) => $l['propertyId'] === $property->getId()->toRfc4122()));

        return $this->json(['demo' => $this->nuki->isDemo(), 'locks' => $mine]);
    }

    /** Keypad codes of the upcoming stays of the property, planned if missing. */
    #[Route('/api/properties/{id}/codes', name: 'api_property_codes', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function codes(#[MapEntity] Property $property): JsonResponse
    {
        $this->planner->plan();
        $today = $this->planner->today();
        $items = [];
        foreach ($this->lodgify->bookings() as $b) {
            if ($b->propertyId !== $property->getLodgifyPropertyId() || $b->arrival <= $today || null === ($code = $this->codes->find($b->id))) {
                continue;
            }
            $items[] = $code->toArray() + ['guest' => $b->guest, 'source' => $b->source, 'arrival' => $b->arrival, 'departure' => $b->departure, 'outdated' => $this->planner->isOutdated($code, $b)];
        }
        usort($items, static fn (array $a, array $c) => strcmp($a['arrival'], $c['arrival']));

        return $this->json(['demo' => $this->nuki->isDemo(), 'items' => $items]);
    }

    /** Writes the code of an upcoming booking to its lock (the interface always asks for confirmation). */
    #[Route('/api/codes/{bookingId}', name: 'api_code_send', methods: ['POST'], requirements: ['bookingId' => '\d+'])]
    public function send(int $bookingId): JsonResponse
    {
        return $this->json($this->planner->send($bookingId)->toArray());
    }

    /** @param list<array<string, mixed>> $locks @return list<array<string, mixed>> */
    private function withProperty(array $locks): array
    {
        return array_map(function (array $l) {
            $property = $this->locks->find($l['id'])?->getProperty();

            return $l + ['propertyId' => $property?->getId()->toRfc4122(), 'property' => $property?->getName()];
        }, $locks);
    }
}
