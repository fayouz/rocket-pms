<?php

namespace App\Lodgify;

use App\Domotique\PluginRegistry;
use App\Entity\Property;
use App\Repository\ConnectorRepository;

/**
 * Resolves the booking provider of a property: the first enabled connector declaring the 'bookings' capability
 * (App\Domotique\LodgifyPlugin), or the single legacy secret lodgify.api_key (App\Lodgify\LodgifyClient) when the property
 * has none, so existing properties keep working unchanged.
 */
final class BookingProviderRegistry
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly ConnectorRepository $connectors,
        private readonly LodgifyClient $legacy,
    ) {
    }

    public function providerFor(Property $property): BookingProviderInterface
    {
        foreach ($this->connectors->forProperty($property) as $connector) {
            if (!$connector->isEnabled()) {
                continue;
            }
            $plugin = $this->plugins->get($connector->getPluginId());
            if ($plugin instanceof BookingCapablePluginInterface && \in_array('bookings', $plugin->capabilities(), true)) {
                return $plugin->bookingProvider($connector->getConfig());
            }
        }

        return $this->legacy;
    }

    public function legacy(): LodgifyClient
    {
        return $this->legacy;
    }
}
