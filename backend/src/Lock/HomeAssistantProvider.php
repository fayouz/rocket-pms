<?php

namespace App\Lock;

use App\Entity\SmartLock;
use App\HomeAssistant\HomeAssistantClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lock entities (domain `lock.*`) read from Home Assistant, with their paired battery sensor when found
 * (`sensor.<object_id>_battery`, a common convention; adjust the entity names in Home Assistant if needed).
 * Keypad codes: only sent if the connector configured a service (`lockServiceDomain`/`lockService`), which must
 * accept a `code` and an `entity_id`/`node_id`-style payload (e.g. Z-Wave JS `set_lock_usercode`). Home Assistant's
 * own Nuki integration does NOT support this: don't configure a service for a Nuki lock (see App\Domotique\
 * HomeAssistantPlugin, App\Domotique\NukiPlugin).
 */
final class HomeAssistantProvider implements LockProviderInterface
{
    /** @param array<string, string> $config */
    public function __construct(private readonly HomeAssistantClient $client, private readonly array $config)
    {
    }

    public function id(): string { return 'home_assistant'; }
    public function isDemo(): bool { return !$this->client->isConfigured($this->config); }

    public function listLocks(): array
    {
        $states = $this->client->states($this->config);
        $byId = [];
        foreach ($states as $s) {
            $byId[$s['entity_id']] = $s;
        }

        $locks = [];
        foreach ($states as $s) {
            if (!str_starts_with($s['entity_id'], 'lock.')) {
                continue;
            }
            $objectId = substr($s['entity_id'], \strlen('lock.'));
            $battery = $byId['sensor.'.$objectId.'_battery'] ?? null;
            $locked = 'locked' === $s['state'];
            $locks[] = [
                'externalId' => $s['entity_id'], 'name' => (string) ($s['attributes']['friendly_name'] ?? $s['entity_id']),
                'state' => $locked ? 'Verrouillée' : ('unlocked' === $s['state'] ? 'Déverrouillée' : $s['state']), 'locked' => $locked,
                'battery' => null !== $battery && is_numeric($battery['state']) ? (int) $battery['state'] : null,
                'batteryCritical' => null !== $battery && is_numeric($battery['state']) && (int) $battery['state'] <= 20,
                'keypadBatteryCritical' => false, 'logs' => [],
            ];
        }

        return $locks;
    }

    public function createCode(SmartLock $lock, string $code, string $label, \DateTimeImmutable $from, \DateTimeImmutable $until): void
    {
        $domain = trim($this->config['lockServiceDomain'] ?? '');
        $service = trim($this->config['lockService'] ?? '');
        if ('' === $domain || '' === $service) {
            throw new HttpException(400, 'Aucun service de code clavier configuré pour ce connecteur Home Assistant (et Nuki via Home Assistant ne le permet de toute façon pas : utilise un connecteur Nuki).');
        }
        $this->client->callService($this->config, $domain, $service, ['entity_id' => $lock->getExternalId(), 'code' => $code, 'usercode' => $code]);
    }

    public function deleteCode(SmartLock $lock, string $code): void
    {
        throw new HttpException(501, 'Suppression de code : non prise en charge pour ce connecteur.');
    }
}
