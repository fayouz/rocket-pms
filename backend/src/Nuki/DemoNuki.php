<?php

namespace App\Nuki;

/** Fictitious locks (no NUKI_API_TOKEN), one per demo property. */
final class DemoNuki
{
    /** @return list<array<string, mixed>> */
    public static function locks(): array
    {
        $ago = static fn (int $min) => (new \DateTimeImmutable('-'.$min.' minutes'))->format(\DATE_ATOM);

        return [
            ['id' => 90001, 'name' => 'Port - entrée', 'state' => 'Verrouillée', 'locked' => true, 'battery' => 82, 'batteryCritical' => false, 'keypadBatteryCritical' => false,
                'logs' => [['date' => $ago(35), 'who' => 'Sofia Rossi (clavier)', 'action' => 1, 'trigger' => 255], ['date' => $ago(300), 'who' => '', 'action' => 2, 'trigger' => 2]]],
            ['id' => 90002, 'name' => 'Vignes - entrée', 'state' => 'Déverrouillée', 'locked' => false, 'battery' => 14, 'batteryCritical' => true, 'keypadBatteryCritical' => false,
                'logs' => [['date' => $ago(12), 'who' => 'Marc Durand (clavier)', 'action' => 1, 'trigger' => 255]]],
        ];
    }
}
