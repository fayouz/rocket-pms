<?php

namespace App\Controller;

use App\Entity\Property;
use App\Clean\CleanClient;
use App\Place\PlaceClient;
use App\Planning\PlanningRunner;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Planning of access codes (Rocket Place) and cleanings (Rocket Clean) (also run every 15 min by the worker, App\Planning\PlanningSchedule),
 * and the cleanings of a property with their secret link for the cleaner (no account needed).
 */
#[IsGranted('PMS_MANAGE')]
final class PlanningController extends AbstractController
{
    public function __construct(private readonly CleanClient $clean)
    {
    }

    #[Route('/api/planning/run', name: 'api_planning_run', methods: ['POST'])]
    public function run(PlanningRunner $runner): JsonResponse
    {
        return $this->json(['ranAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'properties' => $runner->run()]);
    }

    #[Route('/api/properties/{id}/cleanings', name: 'api_property_cleanings', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function cleanings(#[MapEntity] Property $property): JsonResponse
    {
        return $this->json($this->clean->cleanings(PlaceClient::placeIdOf($property)));
    }

    #[Route('/api/properties/{id}/cleanings/{cleaningId}/link', name: 'api_property_cleaning_link', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'cleaningId' => Requirement::UUID])]
    public function link(#[MapEntity] Property $property, string $cleaningId): JsonResponse
    {
        $ids = array_column($this->clean->cleanings(PlaceClient::placeIdOf($property)), 'id');
        if (!\in_array($cleaningId, $ids, true)) {
            throw new HttpException(404, 'Ce ménage n’appartient pas à ce logement.');
        }

        return $this->json($this->clean->cleaningLink($cleaningId));
    }
}
