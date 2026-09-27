<?php

namespace App\Health;

use App\Repository\ConnectorRepository;
use Rocket\Core\Health\ServiceProbeInterface;

/** One target per configured Homey connector (local address + key): not configured connectors are skipped (demo). */
final class HomeyProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly ConnectorRepository $connectors,
        private readonly \App\Homey\HomeyClient $homey,
    ) {
    }

    public function id(): string { return 'homey'; }
    public function label(): string { return 'Homey'; }

    public function targets(): iterable
    {
        foreach ($this->connectors->findBy(['pluginId' => 'homey']) as $connector) {
            $config = $connector->getConfig();
            if ('' === ($config['homeyUrl'] ?? '') || '' === ($config['secretVar'] ?? '')) {
                continue;
            }
            yield $connector->getName() => [
                'name' => 'Homey · '.$connector->getName(),
                'check' => fn () => \sprintf('%d appareil(s)', \count($this->homey->devices($config))),
            ];
        }
    }
}
