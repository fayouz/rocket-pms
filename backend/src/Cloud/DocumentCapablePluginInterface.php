<?php

namespace App\Cloud;

use App\Domotique\PluginInterface;

/**
 * A connector plugin that can also act as a document store (Rocket Cloud today): implemented in addition to
 * PluginInterface. App\Cloud\DocumentProviderRegistry resolves the provider of a given App\Entity\Property from its
 * connectors, so the document store is never hard-coded.
 */
interface DocumentCapablePluginInterface extends PluginInterface
{
    /** @param array<string, string> $config */
    public function documentProvider(array $config): DocumentProviderInterface;
}
