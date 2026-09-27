<?php

namespace App\Lock;

use App\Entity\SmartLock;
use App\Nuki\NukiClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Nuki Web API: the only channel able to write a Nuki keypad code (see App\Domotique\NukiPlugin). */
final class NukiProvider implements LockProviderInterface
{
    public function __construct(private readonly NukiClient $nuki)
    {
    }

    public function id(): string { return 'nuki'; }
    public function isDemo(): bool { return $this->nuki->isDemo(); }

    public function listLocks(): array
    {
        return array_map(static fn (array $l) => [
            'externalId' => (string) $l['id'], 'name' => $l['name'], 'state' => $l['state'], 'locked' => $l['locked'],
            'battery' => $l['battery'], 'batteryCritical' => $l['batteryCritical'], 'keypadBatteryCritical' => $l['keypadBatteryCritical'], 'logs' => $l['logs'],
        ], $this->nuki->locks());
    }

    public function createCode(SmartLock $lock, string $code, string $label, \DateTimeImmutable $from, \DateTimeImmutable $until): void
    {
        $this->nuki->createKeypadCode($lock->getNukiId(), $label, $code, $from, $until);
    }

    public function deleteCode(SmartLock $lock, string $code): void
    {
        throw new HttpException(501, 'Suppression d’un code Nuki : non disponible pour le moment (à refaire à la main dans Nuki).');
    }
}
