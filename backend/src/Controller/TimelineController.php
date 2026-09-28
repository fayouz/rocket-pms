<?php

namespace App\Controller;

use App\Entity\Property;
use App\Repository\PropertyRepository;
use App\Timeline\TimelineBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Timeline of one property or of all of them: ?past=<days> (default 3, max 30), ?future=<days> (default 45, max 120). */
#[IsGranted('ROLE_USER')]
final class TimelineController extends AbstractController
{
    public function __construct(private readonly TimelineBuilder $builder)
    {
    }

    #[Route('/api/properties/{id}/timeline', name: 'api_property_timeline', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function one(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        [$past, $future] = self::window($request);

        return $this->json(['now' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'events' => $this->builder->build($property, $past, $future)]);
    }

    #[Route('/api/timeline', name: 'api_timeline', methods: ['GET'])]
    public function all(Request $request, PropertyRepository $properties): JsonResponse
    {
        [$past, $future] = self::window($request);

        return $this->json([
            'now' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'properties' => array_map(fn (Property $p) => ['id' => $p->getId()->toRfc4122(), 'name' => $p->getName(), 'color' => $p->getColor(), 'events' => $this->builder->build($p, $past, $future)], $properties->findBy([], ['name' => 'ASC'])),
        ]);
    }

    /** @return array{0: int, 1: int} */
    private static function window(Request $request): array
    {
        $clamp = static fn (mixed $v, int $default, int $max) => is_numeric($v) && (int) $v >= 0 ? min((int) $v, $max) : $default;

        return [$clamp($request->query->get('past'), 3, 30), $clamp($request->query->get('future'), 45, 120)];
    }
}
