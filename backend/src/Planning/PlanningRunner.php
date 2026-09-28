<?php

namespace App\Planning;

use App\Code\AccessCodePlanner;
use App\Entity\Property;
use App\Repository\PropertyRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plans the access grants and the cleanings (Rocket Clean) of every property linked to a place
 * (AccessCodePlanner::plan, which also syncs the cleanings). Run every 15 minutes by the worker (PlanningSchedule) and
 * on demand (POST /api/planning/run); reads (timeline) never plan. A failure on one property never stops the others.
 */
final class PlanningRunner
{
    public function __construct(private readonly PropertyRepository $properties, private readonly AccessCodePlanner $planner)
    {
    }

    /** @return list<array{id: string, name: string, ok: bool, grants: int, cleanings: int, error: ?string}> */
    public function run(): array
    {
        $out = [];
        foreach ($this->properties->findBy([], ['name' => 'ASC']) as $p) {
            if (null !== $p->getPlaceId()) {
                $out[] = $this->runOne($p);
            }
        }

        return $out;
    }

    /** @return array{id: string, name: string, ok: bool, grants: int, cleanings: int, error: ?string} */
    private function runOne(Property $p): array
    {
        $row = ['id' => $p->getId()->toRfc4122(), 'name' => $p->getName()];
        try {
            $grants = $this->planner->plan($p);

            return $row + ['ok' => true, 'grants' => \count($grants), 'cleanings' => \count($this->planner->lastCleanings()), 'error' => null];
        } catch (HttpException $e) {
            return $row + ['ok' => false, 'grants' => 0, 'cleanings' => 0, 'error' => $e->getMessage()];
        }
    }
}
