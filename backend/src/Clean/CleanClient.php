<?php

namespace App\Clean;

use App\Secrets\IntegrationSecrets;
use App\Rocket\BrickClient;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Clean (rocket-apps/rocket-clean), the owner of the cleanings. Same paths as the former cleanings of
 * Rocket Place: POST /api/places/{placeId}/cleanings (idempotent by externalRef), PATCH /api/cleanings/{id},
 * GET /api/cleanings/{id}/link, plus PUT /api/places/{placeId}/occupancy (stays of the property, so Clean flags the
 * personal/maintenance cleanings that overlap one). ROCKET_CLEAN_URL + secret rocket.clean.token (rcl_…), suite mode by
 * Rocket Auth audience "rocket-clean"; without them App\Clean\DemoClean answers (no network call).
 */
final class CleanClient extends BrickClient
{
    public function __construct(
        HttpClientInterface $http,
        private readonly DemoClean $demoClean,
        string $cleanUrl,
        IntegrationSecrets|string $cleanToken,
        ?ServiceTokenProvider $serviceTokens = null,
    ) {
        parent::__construct($http, $cleanUrl, $cleanToken, $serviceTokens);
    }

    protected function brickName(): string
    {
        return 'Rocket Clean';
    }

    protected function audience(): string
    {
        return 'rocket-clean';
    }

    protected function tokenSecret(): string
    {
        return 'rocket.clean.token';
    }

    protected function demo(string $method, string $path, ?array $json, array $query): array
    {
        return $this->demoClean->handle($method, $path, $json, $query);
    }

    /** Cleanings of a place. @return list<array<string, mixed>> */
    public function cleanings(string $placeId): array
    {
        return array_values(array_filter($this->request('GET', '/api/places/'.$placeId.'/cleanings'), 'is_array'));
    }

    /**
     * Find-or-create a cleaning, idempotent by externalRef (201 created, 200 existing and unchanged).
     *
     * @param array<string, mixed> $cleaning scheduledAt, dueAt, label, notes, externalRef, type, origin…
     *
     * @return array<mixed>
     */
    public function createCleaning(string $placeId, array $cleaning): array
    {
        return $this->request('POST', '/api/places/'.$placeId.'/cleanings', $cleaning);
    }

    /** Partial update of a cleaning (scheduledAt, dueAt, status...). @param array<string, mixed> $changes @return array<mixed> */
    public function updateCleaning(string $cleaningId, array $changes): array
    {
        return $this->request('PATCH', '/api/cleanings/'.$cleaningId, $changes);
    }

    /** Secret link without account of a cleaning (created on first call). @return array{url?: string, path?: string, expiresAt?: string} */
    public function cleaningLink(string $cleaningId): array
    {
        return $this->request('GET', '/api/cleanings/'.$cleaningId.'/link');
    }

    /**
     * Replaces the occupied periods of a place.
     *
     * @param list<array{from: string, until: string, externalRef?: string}> $periods
     *
     * @return array<mixed>
     */
    public function putOccupancy(string $placeId, array $periods): array
    {
        return $this->request('PUT', '/api/places/'.$placeId.'/occupancy', $periods);
    }
}
