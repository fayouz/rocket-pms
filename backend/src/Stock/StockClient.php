<?php

namespace App\Stock;

use App\Rocket\BrickClient;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Stock (rocket-apps/rocket-stock), the owner of the stock of the places. Rocket Stock keeps the paths
 * of the former stock of Rocket Place (GET /api/stock-items, GET /api/stock-levels?place=<IRI>, PATCH
 * /api/stock-levels/{id} {"level"}). ROCKET_STOCK_URL + ROCKET_STOCK_TOKEN (rst_…), suite mode by Rocket Auth audience
 * "rocket-stock"; without them App\Stock\DemoStock answers (no network call).
 */
final class StockClient extends BrickClient
{
    public function __construct(
        HttpClientInterface $http,
        private readonly DemoStock $demoStock,
        string $stockUrl,
        string $stockToken,
        ?ServiceTokenProvider $serviceTokens = null,
    ) {
        parent::__construct($http, $stockUrl, $stockToken, $serviceTokens);
    }

    protected function brickName(): string
    {
        return 'Rocket Stock';
    }

    protected function audience(): string
    {
        return 'rocket-stock';
    }

    protected function tokenEnv(): string
    {
        return 'ROCKET_STOCK_TOKEN';
    }

    protected function demo(string $method, string $path, ?array $json, array $query): array
    {
        return $this->demoStock->handle($method, $path, $json, $query);
    }

    /** @return list<array<string, mixed>> catalogue */
    public function items(): array
    {
        return array_values(array_filter($this->request('GET', '/api/stock-items'), 'is_array'));
    }

    /** @return list<array<string, mixed>> stock levels of a place */
    public function levels(string $placeId): array
    {
        return array_values(array_filter($this->request('GET', '/api/stock-levels', null, ['place' => '/api/places/'.$placeId]), 'is_array'));
    }

    /** @return array<mixed> */
    public function setLevel(string $levelId, string $level): array
    {
        return $this->request('PATCH', '/api/stock-levels/'.$levelId, ['level' => $level]);
    }
}
