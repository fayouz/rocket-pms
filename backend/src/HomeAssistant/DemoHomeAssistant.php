<?php

namespace App\HomeAssistant;

/** Fictitious Home Assistant states, used whenever a connector has no reachable instance configured (demo, tests). */
final class DemoHomeAssistant
{
    /** @return list<array{entity_id: string, state: string, attributes: array<string, mixed>}> */
    public static function states(): array
    {
        return [
            ['entity_id' => 'lock.entree_port', 'state' => 'locked', 'attributes' => ['friendly_name' => 'Port - entrée']],
            ['entity_id' => 'sensor.entree_port_battery', 'state' => '82', 'attributes' => ['friendly_name' => 'Port - entrée pile', 'unit_of_measurement' => '%']],
            ['entity_id' => 'lock.entree_vignes', 'state' => 'unlocked', 'attributes' => ['friendly_name' => 'Vignes - entrée']],
            ['entity_id' => 'sensor.entree_vignes_battery', 'state' => '14', 'attributes' => ['friendly_name' => 'Vignes - entrée pile', 'unit_of_measurement' => '%']],
            ['entity_id' => 'sensor.salon_temperature', 'state' => '19.4', 'attributes' => ['friendly_name' => 'Salon - température', 'unit_of_measurement' => '°C']],
        ];
    }
}
