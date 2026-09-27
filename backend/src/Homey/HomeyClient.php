<?php

namespace App\Homey;

use App\Domotique\SecretEnv;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Homey Pro, LOCAL access only in this port (address + CONNECTOR_… API key): read-only, list of devices and their
 * capability values. No command is ever sent (v0.2 keeps the "Domotique" tab read-only, as in the source app).
 * Without a configured address/key: demo devices (never a real network call), which also keeps functional tests offline.
 */
final class HomeyClient
{
    private const TIMEOUT = 8;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** @param array<string, string> $config @return list<array{id: string, name: string, class: string, available: bool, capabilities: list<array{id: string, title: string, value: mixed, units: ?string}>}> */
    public function devices(array $config): array
    {
        $url = trim($config['homeyUrl'] ?? '');
        $secretVar = trim($config['secretVar'] ?? '');
        if ('' === $url || '' === $secretVar) {
            return DemoHomey::devices();
        }
        $key = SecretEnv::read($secretVar, 'Clé d’API Homey');
        $raw = $this->request($url, $key, '/api/manager/devices/device');
        if (!\is_array($raw)) {
            throw new HttpException(502, 'Réponse de Homey inattendue.');
        }
        $devices = [];
        foreach ($raw as $d) {
            if (!\is_array($d) || !isset($d['id'])) {
                continue;
            }
            $capsObj = \is_array($d['capabilitiesObj'] ?? null) ? $d['capabilitiesObj'] : [];
            $ids = \is_array($d['capabilities'] ?? null) ? array_map('strval', $d['capabilities']) : array_keys($capsObj);
            $devices[] = [
                'id' => (string) $d['id'], 'name' => mb_substr((string) ($d['name'] ?? '(sans nom)'), 0, 80),
                'class' => (string) ($d['class'] ?? 'other'), 'available' => false !== ($d['available'] ?? true),
                'capabilities' => array_values(array_map(static function (string $id) use ($capsObj) {
                    $c = $capsObj[$id] ?? [];
                    $value = $c['value'] ?? null;

                    return ['id' => $id, 'title' => mb_substr((string) ($c['title'] ?? $id), 0, 60), 'value' => \is_scalar($value) || null === $value ? $value : null, 'units' => isset($c['units']) ? (string) $c['units'] : null];
                }, \array_slice($ids, 0, 40))),
            ];
        }
        usort($devices, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $devices;
    }

    private function request(string $baseUrl, string $key, string $path): mixed
    {
        try {
            $response = $this->http->request('GET', rtrim($baseUrl, '/').$path, [
                'headers' => ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            throw new HttpException(502, 'Homey ne répond pas ou est injoignable depuis le serveur.');
        }
        if (401 === $status) {
            throw new HttpException(502, 'Clé d’API refusée par Homey.');
        }
        if ($status >= 400) {
            throw new HttpException(502, \sprintf('Homey a répondu avec l’erreur %d.', $status));
        }
        $content = $response->getContent(false);

        return '' === $content ? null : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
