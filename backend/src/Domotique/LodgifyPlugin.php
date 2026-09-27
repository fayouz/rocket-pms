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
 * shared. The historical single `LODGIFY_API_KEY` env var stays supported as the fallback used for a property that
 * has no connector (App\Lodgify\BookingProviderRegistry), so existing properties keep working unchanged.
 */
final class LodgifyPlugin implements PluginInterface, BookingCapablePluginInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
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
            ['key' => 'secretVar', 'label' => 'Variable .env de la clé API Lodgify', 'type' => 'text', 'required' => true, 'secret' => true, 'placeholder' => 'CONNECTOR_LODGIFY_SALON'],
        ];
    }

    public function validate(array $config, string $propertyId, ?string $connectorId): array
    {
        if ('' === ($config['secretVar'] ?? '') || !SecretEnv::isValidName($config['secretVar'])) {
            throw new HttpException(400, 'Le nom de variable doit commencer par CONNECTOR_ (majuscules, chiffres, _).');
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
        $secretVar = $config['secretVar'] ?? '';
        $key = SecretEnv::read($secretVar, 'Clé API Lodgify');

        return new LodgifyClient($this->http, $this->cache, $key, 'lodgify.'.$secretVar);
    }
}
