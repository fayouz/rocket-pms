<?php

namespace App\Domotique;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Built-in plugin catalogue: code-defined, nothing external is executed. Add a plugin = add a class + register it here. */
final class PluginRegistry
{
    /** @var list<PluginInterface> */
    private array $plugins;

    public function __construct(HomeyPlugin $homey, WebServicePlugin $webService)
    {
        $this->plugins = [$homey, $webService];
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

    /** @return array{id: string, name: string, description: string, icon: string, category: string, fields: array<mixed>} */
    public function view(PluginInterface $p): array
    {
        return ['id' => $p->id(), 'name' => $p->name(), 'description' => $p->description(), 'icon' => $p->icon(), 'category' => $p->category(), 'fields' => $p->fields()];
    }
}
