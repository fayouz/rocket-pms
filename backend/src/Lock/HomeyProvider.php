<?php

namespace App\Lock;

use App\Entity\SmartLock;
use App\Homey\HomeyClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Homey lock devices (class "lock"), read-only: Homey has no generic keypad/access-code service in this port. */
final class HomeyProvider implements LockProviderInterface
{
    /** @param array<string, string> $config */
    public function __construct(private readonly HomeyClient $homey, private readonly array $config)
    {
    }

    public function id(): string { return 'homey'; }
    public function isDemo(): bool { return '' === trim($this->config['homeyUrl'] ?? '') || '' === trim($this->config['secretVar'] ?? ''); }

    public function listLocks(): array
    {
        $locks = [];
        foreach ($this->homey->devices($this->config) as $d) {
            if ('lock' !== $d['class']) {
                continue;
            }
            $locked = null;
            $battery = null;
            foreach ($d['capabilities'] as $c) {
                if ('locked' === $c['id']) {
                    $locked = (bool) $c['value'];
                }
                if ('measure_battery' === $c['id'] && is_numeric($c['value'])) {
                    $battery = (int) $c['value'];
                }
            }
            $locks[] = [
                'externalId' => $d['id'], 'name' => $d['name'], 'state' => true === $locked ? 'Verrouillée' : (false === $locked ? 'Déverrouillée' : 'Inconnue'),
                'locked' => (bool) $locked, 'battery' => $battery, 'batteryCritical' => null !== $battery && $battery <= 20,
                'keypadBatteryCritical' => false, 'logs' => [],
            ];
        }

        return $locks;
    }

    public function createCode(SmartLock $lock, string $code, string $label, \DateTimeImmutable $from, \DateTimeImmutable $until): void
    {
        throw new HttpException(400, 'Homey ne propose pas de service générique de code clavier : utilise un connecteur Nuki pour les codes.');
    }

    public function deleteCode(SmartLock $lock, string $code): void
    {
        throw new HttpException(400, 'Homey ne propose pas de service générique de code clavier.');
    }
}
