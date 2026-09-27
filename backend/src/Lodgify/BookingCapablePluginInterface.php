<?php

namespace App\Lodgify;

use App\Domotique\PluginInterface;

/**
 * A connector plugin that can also act as a booking channel manager (Lodgify today): implemented in addition to
 * PluginInterface. App\Lodgify\BookingProviderRegistry resolves the provider of a given App\Entity\Property from its
 * connectors, so the channel manager is never hard-coded.
 */
interface BookingCapablePluginInterface extends PluginInterface
{
    /** @param array<string, string> $config */
    public function bookingProvider(array $config): BookingProviderInterface;
}
