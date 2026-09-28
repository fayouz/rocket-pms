<?php

namespace App\Domotique;

use App\Lodgify\BookingCapablePluginInterface;
use App\Lodgify\BookingProviderInterface;
use App\Lodgify\LodgifyClient;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lodgify as a connector plugin: several Lodgify accounts (several API keys) can be attached, one per property or
 * shared. The historical single vault secret `lodgify.api_key` (formerly secret lodgify.api_key) stays supported as the fallback used for a property that
 * has no connector (App\Lodgify\BookingProviderRegistry), so existing properties keep working unchanged.
 */
final class LodgifyPlugin implements PluginInterface, BookingCapablePluginInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly ConnectorSecrets $connectorSecrets,
    ) {
    }

    public function id(): string { return 'lodgify'; }
    public function name(): string { return 'Lodgify'; }
    public function description(): string { return 'Compte Lodgify (channel manager) : réservations, prix et conversation avec les voyageurs.'; }
    public function icon(): string { return 'i-lucide-calendar-check'; }
    public function category(): string { return 'general'; }
    public function capabilities(): array { return ['bookings', 'pricing', 'conversation']; }

    public function fields(): array
    {
        return [
            ['key' => 'secret', 'label' => 'Clé API Lodgify (coffre des secrets)', 'type' => 'secret', 'required' => true, 'secret' => true, 'defaultName' => 'lodgify.salon.api_key'],
        ];
    }

    public function validate(array $config, string $propertyId, ?string $connectorId): array
    {
        if (!ConnectorSecrets::isValidName(ConnectorSecrets::nameIn($config))) {
            throw new HttpException(400, 'Choisissez un secret du coffre (Administration → Secrets) ; la clé Lodgify globale et les secrets propres à l’application sont interdits.');
        }

        return $config;
    }

    public function test(array $config): string
    {
        $properties = $this->client($config)->properties();

        return \sprintf('Compte Lodgify joignable (%d logement(s)).', \count($properties));
    }

    public function info(array $config): array
    {
        $properties = $this->client($config)->properties();

        return array_map(static fn (array $p) => [
            'title' => $p['name'], 'icon' => 'i-lucide-home',
            'items' => [['label' => 'Nom interne', 'value' => $p['internalName'] ?? '—']],
        ], $properties);
    }

    public function bookingProvider(array $config): BookingProviderInterface
    {
        return $this->client($config);
    }

    /** @param array<string, string> $config */
    private function client(array $config): LodgifyClient
    {
        $name = ConnectorSecrets::nameIn($config);
        $key = $this->connectorSecrets->read($name, 'Clé API Lodgify');

        return new LodgifyClient($this->http, $this->cache, $key, 'lodgify.'.$name);
    }
}
