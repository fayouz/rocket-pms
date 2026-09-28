<?php

namespace App\Health;

use App\Place\PlaceClient;
use Rocket\Core\Health\ServiceProbeInterface;

/** Rocket Place on the "État des services" of the dashboard (read-only). Not configured: demo, nothing checked. */
final class PlaceProbe implements ServiceProbeInterface
{
    public function __construct(private readonly PlaceClient $place)
    {
    }

    public function id(): string { return 'place'; }
    public function label(): string { return 'Rocket Place'; }

    public function targets(): iterable
    {
        if ($this->place->isDemo()) {
            return [];
        }

        return ['api' => ['name' => 'API Rocket Place', 'check' => fn () => \sprintf('%d lieu(x)', \count($this->place->request('GET', '/api/places')))]];
    }
}
