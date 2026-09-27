<?php

namespace App\Domotique;

use App\Homey\HomeyClient;

/** Homey Pro hub: devices of the property and their capability values. Read-only (v0.2). */
final class HomeyPlugin implements PluginInterface
{
    public function __construct(private readonly HomeyClient $homey)
    {
    }

    public function id(): string { return 'homey'; }
    public function name(): string { return 'Homey'; }
    public function description(): string { return 'Hub domotique Homey (Pro) : appareils du logement et leurs valeurs (températures, prises, capteurs…). Lecture seule.'; }
    public function icon(): string { return 'i-lucide-house-wifi'; }
    public function category(): string { return 'domotique'; }

    public function fields(): array
    {
        return [
            ['key' => 'homeyUrl', 'label' => 'Adresse locale du Homey', 'type' => 'url', 'placeholder' => 'http://192.168.1.20', 'help' => 'Réseau local uniquement (LAN, VPN) : jamais une adresse publique.'],
            ['key' => 'secretVar', 'label' => 'Variable .env de la clé d’API', 'type' => 'text', 'secret' => true, 'placeholder' => 'CONNECTOR_HOMEY_SALON'],
        ];
    }

    public function validate(array $config, string $propertyId, ?string $connectorId): array
    {
        $url = trim($config['homeyUrl'] ?? '');
        if ('' !== $url) {
            $host = parse_url($url, \PHP_URL_HOST);
            if (!\is_string($host) || !preg_match('#^https?://#', $url)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(400, 'Adresse locale du Homey invalide.');
            }
            if (!SecretEnv::isPrivateHost($host)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(400, 'L’adresse doit être sur le réseau local (192.168.x.x, 10.x.x.x, .local…).');
            }
        }
        if ('' !== ($config['secretVar'] ?? '') && !SecretEnv::isValidName($config['secretVar'])) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(400, 'Le nom de variable doit commencer par CONNECTOR_ (majuscules, chiffres, _).');
        }

        return $config;
    }

    public function test(array $config): string
    {
        $devices = $this->homey->devices($config);

        return \sprintf('Homey joignable (%d appareil(s)).', \count($devices));
    }

    public function info(array $config): array
    {
        $devices = $this->homey->devices($config);

        return array_map(static function (array $d) {
            $items = [];
            foreach ($d['capabilities'] as $c) {
                if (null === $c['value']) {
                    continue;
                }
                $value = \is_bool($c['value']) ? ($c['value'] ? 'oui' : 'non') : (string) $c['value'];
                $items[] = ['label' => $c['title'], 'value' => $c['units'] ? $value.' '.$c['units'] : $value];
            }

            return ['title' => $d['name'], 'icon' => $d['available'] ? 'i-lucide-cpu' : 'i-lucide-unplug', 'items' => \array_slice($items, 0, 8)];
        }, \array_slice($devices, 0, 60));
    }
}
