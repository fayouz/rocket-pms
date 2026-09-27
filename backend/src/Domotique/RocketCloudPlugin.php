<?php

namespace App\Domotique;

use App\Cloud\CloudClient;
use App\Cloud\DemoCloud;
use App\Cloud\DocumentCapablePluginInterface;
use App\Cloud\DocumentProviderInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rocket Cloud as a connector plugin: another Rocket Cloud instance/token can be attached per property. The
 * historical single `ROCKET_CLOUD_URL`/`ROCKET_CLOUD_TOKEN` env vars stay supported as the fallback used for a
 * property that has no connector (App\Cloud\DocumentProviderRegistry), so existing properties keep working unchanged.
 */
final class RocketCloudPlugin implements PluginInterface, DocumentCapablePluginInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DemoCloud $demoCloud,
    ) {
    }

    public function id(): string { return 'rocketcloud'; }
    public function name(): string { return 'Rocket Cloud'; }
    public function description(): string { return 'Stockage des documents du logement dans une instance Rocket Cloud dédiée.'; }
    public function icon(): string { return 'i-lucide-folder-cloud'; }
    public function category(): string { return 'general'; }
    public function capabilities(): array { return ['documents']; }

    public function fields(): array
    {
        return [
            ['key' => 'url', 'label' => 'Adresse de Rocket Cloud', 'type' => 'url', 'required' => true, 'placeholder' => 'https://cloud.exemple.fr'],
            ['key' => 'secretVar', 'label' => 'Variable .env du jeton Rocket Cloud', 'type' => 'text', 'required' => true, 'secret' => true, 'placeholder' => 'CONNECTOR_ROCKET_CLOUD_SALON'],
        ];
    }

    public function validate(array $config, string $propertyId, ?string $connectorId): array
    {
        if ('' === ($config['url'] ?? '') || !preg_match('#^https?://#', $config['url'])) {
            throw new HttpException(400, '« Adresse de Rocket Cloud » : adresse invalide.');
        }
        if ('' === ($config['secretVar'] ?? '') || !SecretEnv::isValidName($config['secretVar'])) {
            throw new HttpException(400, 'Le nom de variable doit commencer par CONNECTOR_ (majuscules, chiffres, _).');
        }

        return $config;
    }

    public function test(array $config): string
    {
        $this->client($config)->list($this->client($config)->ensureFolder('test', '', 'Test'));

        return 'Rocket Cloud joignable.';
    }

    public function info(array $config): array
    {
        return [];
    }

    public function documentProvider(array $config): DocumentProviderInterface
    {
        return $this->client($config);
    }

    /** @param array<string, string> $config */
    private function client(array $config): CloudClient
    {
        $token = SecretEnv::read($config['secretVar'] ?? '', 'Jeton Rocket Cloud');

        return new CloudClient($this->http, $this->demoCloud, $config['url'] ?? '', $token);
    }
}
