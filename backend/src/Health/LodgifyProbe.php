<?php

namespace App\Health;

use App\Lodgify\LodgifyClient;
use Rocket\Core\Health\ServiceProbeInterface;

/** Lodgify on the "État des services" of the dashboard (read-only call). Not configured: demo, nothing checked. */
final class LodgifyProbe implements ServiceProbeInterface
{
    public function __construct(private readonly LodgifyClient $lodgify)
    {
    }

    public function id(): string { return 'lodgify'; }
    public function label(): string { return 'Lodgify'; }

    public function targets(): iterable
    {
        if ($this->lodgify->isDemo()) {
            return [];
        }

        return ['api' => ['name' => 'API Lodgify', 'check' => fn () => \sprintf('%d logement(s)', \count($this->lodgify->properties()))]];
    }
}
