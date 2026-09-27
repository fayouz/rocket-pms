<?php

namespace App\Lock;

use App\Entity\SmartLock;

/**
 * A source of smart locks (Nuki, Home Assistant...): live state and, on an explicit user action, keypad codes.
 * Rocket PMS stays the single source of truth for access codes (App\Code\AccessCodePlanner): it decides what code,
 * for whom and when, then asks the provider of the lock to write it to the physical device.
 */
interface LockProviderInterface
{
    public function id(): string;

    /** True when no real device is configured (demo fallback): never call createCode/deleteCode in this mode. */
    public function isDemo(): bool;

    /** @return list<array{externalId: string, name: string, state: string, locked: bool, battery: ?int, batteryCritical: bool, keypadBatteryCritical: bool, logs: list<array{date: string, who: string, action: int, trigger: int}>}> */
    public function listLocks(): array;

    /** Writes a temporary keypad code to the lock (explicit user action only). */
    public function createCode(SmartLock $lock, string $code, string $label, \DateTimeImmutable $from, \DateTimeImmutable $until): void;

    /** Removes a keypad code from the lock, when supported (best-effort; not all providers can). */
    public function deleteCode(SmartLock $lock, string $code): void;
}
