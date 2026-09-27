<?php

namespace App\Lock;

use App\Domotique\PluginRegistry;
use App\Entity\SmartLock;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the lock provider(s) of a App\Entity\SmartLock from its connectors: the state connector (live state,
 * battery, lock/unlock) and the code connector (keypad/access codes) may be different (e.g. state read through a
 * Home Assistant connector, codes still written through a Nuki connector — the only channel able to write a Nuki
 * keypad code). A lock with no connector falls back to the legacy, env-token Nuki provider, so existing rows keep
 * working unchanged.
 */
final class LockProviderRegistry
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly NukiProvider $legacyNuki,
    ) {
    }

    public function stateProviderFor(SmartLock $lock): LockProviderInterface
    {
        return null !== $lock->getConnector() ? $this->fromConnector($lock->getConnector()) : $this->legacyNuki;
    }

    public function codeProviderFor(SmartLock $lock): LockProviderInterface
    {
        return null !== $lock->getCodeConnector() ? $this->fromConnector($lock->getCodeConnector()) : $this->legacyNuki;
    }

    public function legacy(): NukiProvider
    {
        return $this->legacyNuki;
    }

    private function fromConnector(\App\Entity\Connector $connector): LockProviderInterface
    {
        $plugin = $this->plugins->get($connector->getPluginId());
        if (!$plugin instanceof LockCapablePluginInterface) {
            throw new HttpException(400, \sprintf('Le connecteur « %s » ne gère pas les serrures.', $connector->getName()));
        }

        return $plugin->lockProvider($connector->getConfig());
    }
}
