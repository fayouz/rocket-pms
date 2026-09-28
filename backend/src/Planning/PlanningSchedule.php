<?php

namespace App\Planning;

use App\Message\RunPlanning;
use Rocket\Core\Scheduler\RecurringTaskProviderInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/** Planning of codes and cleanings every 15 minutes, run by the worker (`messenger:consume async scheduler_default`) of rocket-core. */
final class PlanningSchedule implements RecurringTaskProviderInterface
{
    public function recurringMessages(): iterable
    {
        yield RecurringMessage::every('15 minutes', new RunPlanning());
    }
}
