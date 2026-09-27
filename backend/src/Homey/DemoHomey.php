<?php

namespace App\Homey;

/** Fictitious Homey devices, used whenever a Homey connector has no reachable Homey configured (demo, tests). */
final class DemoHomey
{
    /** @return list<array{id: string, name: string, class: string, available: bool, capabilities: list<array{id: string, title: string, value: mixed, units: ?string}>}> */
    public static function devices(): array
    {
        return [
            ['id' => 'd1', 'name' => 'Salon - thermostat', 'class' => 'thermostat', 'available' => true, 'capabilities' => [
                ['id' => 'target_temperature', 'title' => 'Température cible', 'value' => 20.0, 'units' => '°C'],
                ['id' => 'measure_temperature', 'title' => 'Température', 'value' => 19.4, 'units' => '°C'],
            ]],
            ['id' => 'd2', 'name' => 'Entrée - prise', 'class' => 'socket', 'available' => true, 'capabilities' => [
                ['id' => 'onoff', 'title' => 'Marche/arrêt', 'value' => true, 'units' => null],
            ]],
            ['id' => 'd3', 'name' => 'Cave - capteur d’eau', 'class' => 'sensor', 'available' => false, 'capabilities' => [
                ['id' => 'alarm_water', 'title' => 'Alarme eau', 'value' => false, 'units' => null],
            ]],
            ['id' => 'd4', 'name' => 'Entrée - serrure', 'class' => 'lock', 'available' => true, 'capabilities' => [
                ['id' => 'locked', 'title' => 'Verrouillée', 'value' => true, 'units' => null],
                ['id' => 'measure_battery', 'title' => 'Batterie', 'value' => 76, 'units' => '%'],
            ]],
        ];
    }
}
