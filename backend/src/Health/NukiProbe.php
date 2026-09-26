<?php

namespace App\Health;

use App\Nuki\NukiClient;
use Rocket\Core\Health\ServiceProbeInterface;

/** Nuki web API on the "État des services" of the dashboard (read-only). Not configured: demo, nothing checked. */
final class NukiProbe implements ServiceProbeInterface
{
    public function __construct(private readonly NukiClient $nuki)
    {
    }

    public function id(): string { return 'nuki'; }
    public function label(): string { return 'Nuki'; }

    public function targets(): iterable
    {
        if ($this->nuki->isDemo()) {
            return [];
        }

        return ['api' => ['name' => 'API Nuki', 'check' => fn () => \sprintf('%d serrure(s)', \count($this->nuki->locks()))]];
    }
}
