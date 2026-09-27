<?php

namespace App\Cloud;

use App\Domotique\PluginRegistry;
use App\Entity\Property;
use App\Repository\ConnectorRepository;

/**
 * Resolves the document provider of a property: the first enabled connector declaring the 'documents' capability
 * (App\Domotique\RocketCloudPlugin), or the single legacy ROCKET_CLOUD_URL/ROCKET_CLOUD_TOKEN (App\Cloud\CloudClient)
 * when the property has none, so existing properties keep working unchanged.
 */
final class DocumentProviderRegistry
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly ConnectorRepository $connectors,
        private readonly CloudClient $legacy,
    ) {
    }

    public function providerFor(Property $property): DocumentProviderInterface
    {
        foreach ($this->connectors->forProperty($property) as $connector) {
            if (!$connector->isEnabled()) {
                continue;
            }
            $plugin = $this->plugins->get($connector->getPluginId());
            if ($plugin instanceof DocumentCapablePluginInterface && \in_array('documents', $plugin->capabilities(), true)) {
                return $plugin->documentProvider($connector->getConfig());
            }
        }

        return $this->legacy;
    }
}
