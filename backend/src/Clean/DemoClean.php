<?php

namespace App\Clean;

use App\Place\DemoPlace;

/**
 * Rocket Clean without ROCKET_CLEAN_URL/TOKEN: the cleanings (and occupancy) live in the demo state of DemoPlace, so the
 * demo places and their cleanings stay consistent. No network call.
 */
final class DemoClean
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
