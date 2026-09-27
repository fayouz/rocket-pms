<?php

namespace App\Domotique;

use App\Lock\LockCapablePluginInterface;
use App\Lock\LockProviderInterface;
use App\Lock\NukiProvider;
use App\Nuki\NukiClient;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Nuki as a connector plugin: several Nuki accounts (several API tokens) can be attached, one per property or
 * shared. The historical single `NUKI_API_TOKEN` env var stays supported as the fallback used for a App\Entity\
 * SmartLock that has no connector (App\Lock\LockProviderRegistry), so existing rows keep working unchanged.
 * Locks state and keypad codes: this is, per research, the ONLY channel able to write a Nuki keypad code (Home
 * Assistant's Nuki integration and Nuki-over-Matter/MQTT cannot).
 */
final class NukiPlugin implements PluginInterface, LockCapablePluginInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
    ) {
    }

    public function id(): string { return 'nuki'; }
    public function name(): string { return 'Nuki'; }
    public function description(): string { return 'Compte Nuki (API web) : serrures, état et codes clavier temporaires. Seul canal capable de créer un code clavier Nuki.'; }
    public function icon(): string { return 'i-lucide-key-round'; }
    public function category(): string { return 'domotique'; }
    public function capabilities(): array { return ['locks.state', 'locks.codes']; }

    public function fields(): array
    {
        return [
            ['key' => 'secretVar', 'label' => 'Variable .env du jeton API Nuki', 'type' => 'text', 'required' => true, 'secret' => true, 'placeholder' => 'CONNECTOR_NUKI_SALON', 'help' => 'Jeton avec le droit smartlock.auth pour créer des codes.'],
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
        $locks = $this->client($config)->locks();

        return \sprintf('Compte Nuki joignable (%d serrure(s)).', \count($locks));
    }

    public function info(array $config): array
    {
        $locks = $this->client($config)->locks();

        return array_map(static fn (array $l) => [
            'title' => $l['name'], 'icon' => 'i-lucide-lock',
            'items' => [['label' => 'État', 'value' => $l['state']], ['label' => 'Batterie', 'value' => null === $l['battery'] ? 'inconnue' : $l['battery'].' %']],
        ], $locks);
    }

    public function lockProvider(array $config): LockProviderInterface
    {
        return new NukiProvider($this->client($config));
    }

    /** @param array<string, string> $config */
    private function client(array $config): NukiClient
    {
        $token = SecretEnv::read($config['secretVar'] ?? '', 'Jeton Nuki');

        return new NukiClient($this->http, $this->cache, $token);
    }
}
