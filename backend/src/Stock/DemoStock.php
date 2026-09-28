<?php

namespace App\Stock;

use App\Place\DemoPlace;

/**
 * Rocket Stock without ROCKET_STOCK_URL/TOKEN: the catalogue and levels of the demo state of DemoPlace (same paths as
 * Rocket Stock: /api/stock-items, /api/stock-levels). No network call.
 */
final class DemoStock
{
    public function __construct(private readonly DemoPlace $place)
    {
    }

    /** @param array<string, mixed>|null $json @param array<string, mixed> $query @return array<mixed> */
    public function handle(string $method, string $path, ?array $json, array $query): array
    {
        return $this->place->handle($method, $path, $json, $query);
    }
}
