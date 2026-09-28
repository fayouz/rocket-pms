<?php

namespace App\MessageHandler;

use App\Message\RunPlanning;
use App\Planning\PlanningRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunPlanningHandler
{
    public function __construct(private readonly PlanningRunner $runner)
    {
    }

    public function __invoke(RunPlanning $message): void
    {
        $this->runner->run();
    }
}
