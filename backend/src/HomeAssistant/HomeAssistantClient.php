<?php

namespace App\HomeAssistant;

use App\Domotique\SecretEnv;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Home Assistant REST API (https://developers.home-assistant.io/docs/api/rest/): GET /api/states for entities
 * (locks, sensors...), POST /api/services/{domain}/{service} to call a service (used to send a lock keypad code,
 * see App\Lock\HomeAssistantProvider). Bearer long-lived access token, https unless the host is on the local
 * network (App\Domotique\SecretEnv::isPrivateHost). Without a configured address/token: demo states, so this class
 * never makes a real network call in tests.
 */
final class HomeAssistantClient
{
    private const TIMEOUT = 8;
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function isConfigured(array $config): bool
    {
        return '' !== trim($config['baseUrl'] ?? '') && '' !== trim($config['secretVar'] ?? '');
    }

    /** @param array<string, string> $config @return list<array{entity_id: string, state: string, attributes: array<string, mixed>}> */
    public function states(array $config): array
    {
        if (!$this->isConfigured($config)) {
            return DemoHomeAssistant::states();
        }
        $raw = $this->request($config, 'GET', '/api/states');
        if (!\is_array($raw)) {
            throw new HttpException(502, 'Réponse de Home Assistant inattendue.');
        }

        $states = [];
        foreach ($raw as $s) {
            if (\is_array($s) && isset($s['entity_id'], $s['state'])) {
                $states[] = ['entity_id' => (string) $s['entity_id'], 'state' => (string) $s['state'], 'attributes' => \is_array($s['attributes'] ?? null) ? $s['attributes'] : []];
            }
        }

        return $states;
    }

    /** Calls a Home Assistant service (e.g. lock.lock, zwave_js.set_lock_usercode): writes to a real device, only on an explicit user action. @param array<string, string> $config @param array<string, mixed> $data */
    public function callService(array $config, string $domain, string $service, array $data = []): void
    {
        if (!$this->isConfigured($config)) {
            throw new HttpException(400, 'Connecteur Home Assistant non configuré : rien n’est envoyé.');
        }
        $this->request($config, 'POST', \sprintf('/api/services/%s/%s', $domain, $service), $data);
    }

    /** @param array<string, string> $config @param array<string, mixed> $body */
    private function request(array $config, string $method, string $path, array $body = []): mixed
    {
        $base = rtrim(trim($config['baseUrl'] ?? ''), '/');
        $url = $base.$path;
        $u = parse_url($url);
        if (!\is_array($u) || !isset($u['host'])) {
            throw new HttpException(400, 'Adresse de Home Assistant invalide.');
        }
        if ('http' === ($u['scheme'] ?? '') && !SecretEnv::isPrivateHost((string) $u['host'])) {
            throw new HttpException(400, 'Authentification refusée en http vers Internet : utilise une adresse https ou une adresse locale.');
        }
        $token = SecretEnv::read(trim($config['secretVar'] ?? ''), 'Jeton Home Assistant');
        try {
            $response = $this->http->request($method, $url, [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                'json' => $body ?: null,
                'timeout' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            throw new HttpException(502, 'Home Assistant injoignable (adresse ou réseau à vérifier).');
        }
        if ($status >= 400) {
            $hint = \in_array($status, [401, 403], true) ? ' (le jeton a-t-il les droits nécessaires ?)' : '';
            throw new HttpException(502, \sprintf('Home Assistant a répondu avec l’erreur %d%s.', $status, $hint));
        }
        $content = $response->getContent(false);
        if (\strlen($content) > self::MAX_BYTES) {
            throw new HttpException(502, 'Réponse trop volumineuse.');
        }

        return '' === $content ? [] : json_decode($content, true);
    }
}
