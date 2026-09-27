<?php

namespace App\Lock;

use App\Domotique\PluginInterface;

/**
 * A connector plugin that can also act as a lock controller (Home Assistant, Homey...): implemented in addition to
 * PluginInterface (the read-only "Domotique" info cards still work the same way). App\Lock\LockProviderRegistry
 * resolves the provider of a given App\Entity\SmartLock from its connector's plugin, so the home-automation
 * controller is never hard-coded: any plugin may declare it can list/drive locks.
 */
interface LockCapablePluginInterface extends PluginInterface
{
    /** @param array<string, string> $config */
    public function lockProvider(array $config): LockProviderInterface;
}
