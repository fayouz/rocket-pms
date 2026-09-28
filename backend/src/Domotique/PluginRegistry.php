<?php

namespace App\Domotique;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Built-in plugin catalogue of PMS: only Lodgify (the PMS's own booking integration). Every physical connector
 * (Homey, Home Assistant, Nuki, web services, documents) now lives in Rocket Place, attached to a place.
 */
final class PluginRegistry
{
    /** @var list<PluginInterface> */
    private array $plugins;

    public function __construct(LodgifyPlugin $lodgify)
    {
        $this->plugins = [$lodgify];
    }

    /** @return list<PluginInterface> */
    public function all(): array
    {
        return $this->plugins;
    }

    public function get(string $id): PluginInterface
    {
        foreach ($this->plugins as $p) {
            if ($p->id() === $id) {
                return $p;
            }
        }
        throw new HttpException(404, 'Plugin inconnu.');
    }

    /** @return array{id: string, name: string, description: string, icon: string, category: string, fields: array<mixed>, capabilities: list<string>} */
    public function view(PluginInterface $p): array
    {
        return ['id' => $p->id(), 'name' => $p->name(), 'description' => $p->description(), 'icon' => $p->icon(), 'category' => $p->category(), 'fields' => $p->fields(), 'capabilities' => $p->capabilities()];
    }
}
