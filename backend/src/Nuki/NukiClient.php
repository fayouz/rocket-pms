<?php

namespace App\Nuki;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Nuki web API: smart locks with their live state and last 5 events (cached 1 minute), and creation of a temporary
 * keypad code (writes to the lock: only called on an explicit user action). Without NUKI_API_TOKEN: demo locks.
 */
final class NukiClient
{
    private const BASE = 'https://api.nuki.io';
    /** https://developer.nuki.io — 1 locked, 3 unlocked, 5 unlatched, 254 motor blocked… */
    private const STATES = [0 => 'Non calibrée', 1 => 'Verrouillée', 2 => 'Déverrouillage…', 3 => 'Déverrouillée', 4 => 'Verrouillage…',
        5 => 'Ouverte (pêne retiré)', 6 => 'Déverrouillée (Lock’n’Go)', 7 => 'Ouverture…', 254 => 'Moteur bloqué', 255 => 'Inconnu'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly string $nukiApiToken,
    ) {
    }

    public function isDemo(): bool
    {
        return '' === $this->nukiApiToken;
    }

    /** @return list<array{id: int, name: string, state: string, locked: bool, battery: ?int, batteryCritical: bool, keypadBatteryCritical: bool, logs: list<array{date: string, who: string, action: int, trigger: int}>}> */
    public function locks(): array
    {
        if ($this->isDemo()) {
            return DemoNuki::locks();
        }

        return $this->cache->get('nuki.locks', function (ItemInterface $item) {
            $item->expiresAfter(60);

            return array_map(function (array $s) {
                try {
                    $logs = $this->request('GET', '/smartlock/'.$s['smartlockId'].'/log', ['query' => ['limit' => 5]]);
                } catch (HttpException) {
                    $logs = [];
                }
                $state = $s['state'] ?? [];

                return [
                    'id' => (int) $s['smartlockId'], 'name' => (string) ($s['name'] ?? ''),
                    'state' => self::STATES[$state['state'] ?? 255] ?? 'Inconnu', 'locked' => 1 === ($state['state'] ?? null),
                    'battery' => \is_int($state['batteryCharge'] ?? null) ? $state['batteryCharge'] : null,
                    'batteryCritical' => (bool) ($state['batteryCritical'] ?? false), 'keypadBatteryCritical' => (bool) ($state['keypadBatteryCritical'] ?? false),
                    'logs' => array_map(static fn (array $l) => ['date' => (string) $l['date'], 'who' => (string) ($l['name'] ?? ''), 'action' => (int) ($l['action'] ?? 0), 'trigger' => (int) ($l['trigger'] ?? 0)], $logs),
                ];
            }, $this->request('GET', '/smartlock'));
        });
    }

    /** Temporary keypad code (type 13). Nuki answers 204: the creation on the lock is asynchronous. */
    public function createKeypadCode(int $lockId, string $name, string $code, \DateTimeImmutable $from, \DateTimeImmutable $until): void
    {
        if ($this->isDemo()) {
            throw new HttpException(400, 'Mode démo : rien n’est envoyé à Nuki.');
        }
        $this->request('PUT', '/smartlock/'.$lockId.'/auth', ['json' => [
            'name' => mb_substr($name, 0, 20), 'type' => 13, 'code' => (int) $code,
            'allowedFromDate' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'allowedUntilDate' => $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ]]);
        $this->cache->delete('nuki.locks');
    }

    /** @param array<string, mixed> $options @return array<mixed> */
    private function request(string $method, string $path, array $options = []): array
    {
        $response = $this->http->request($method, self::BASE.$path, $options + [
            'headers' => ['Authorization' => 'Bearer '.$this->nukiApiToken, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $hint = \in_array($status, [401, 403], true) ? ' (le jeton a-t-il le droit smartlock.auth ?)' : '';
            throw new HttpException(502, \sprintf('Nuki a répondu avec l’erreur %d%s.', $status, $hint));
        }
        $content = $response->getContent(false);

        return '' === $content ? [] : (array) json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
