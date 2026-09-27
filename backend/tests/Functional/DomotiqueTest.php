<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Plugin catalogue, connectors (admin-only writes) and the read-only "Domotique" tab. No real Homey or web service is
 * ever called here: connectors without a configured address fall back to demo devices (App\Homey\DemoHomey).
 */
final class DomotiqueTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->user = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
    }

    public function testCatalogue(): void
    {
        $this->api('GET', '/api/plugins');
        $this->assertStatus(401);
        $plugins = $this->api('GET', '/api/plugins', null, $this->user);
        self::assertSame(['homey', 'home_assistant', 'nuki', 'lodgify', 'rocketcloud', 'webservice'], array_column($plugins, 'id'));
    }

    public function testDemoSeederCreatesAHomeyConnector(): void
    {
        $port = $this->seed();
        $connectors = $this->api('GET', "/api/properties/$port/connectors", null, $this->user);
        self::assertCount(1, $connectors);
        self::assertSame('homey', $connectors[0]['pluginId']);
        self::assertSame('Homey (démo)', $connectors[0]['name']);

        $domotique = $this->api('GET', "/api/properties/$port/domotique", null, $this->user);
        self::assertCount(1, $domotique['sections']);
        self::assertNull($domotique['sections'][0]['error']);
        self::assertNotEmpty($domotique['sections'][0]['cards']);
        self::assertSame('Salon - thermostat', $domotique['sections'][0]['cards'][0]['title']);
    }

    public function testOnlyAnAdminManagesConnectors(): void
    {
        $port = $this->seed();
        $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'webservice', 'name' => 'API météo', 'config' => ['baseUrl' => 'https://example.org']], $this->user);
        $this->assertStatus(403);

        $created = $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'webservice', 'name' => 'API météo', 'config' => ['baseUrl' => 'https://example.org']], $this->admin);
        $this->assertStatus(201);
        self::assertSame('webservice', $created['pluginId']);
        self::assertSame(['baseUrl' => 'https://example.org'], $created['config']);

        // Missing required field
        $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'webservice', 'config' => []], $this->admin);
        $this->assertStatus(400);

        $id = $created['id'];
        $updated = $this->api('PATCH', "/api/connectors/$id", ['enabled' => false], $this->admin);
        self::assertFalse($updated['enabled']);
        $this->api('PATCH', "/api/connectors/$id", ['enabled' => true], $this->user);
        $this->assertStatus(403);

        $this->api('DELETE', "/api/connectors/$id", null, $this->user);
        $this->assertStatus(403);
        $this->api('DELETE', "/api/connectors/$id", null, $this->admin);
        $this->assertStatus(200);
        self::assertCount(1, $this->api('GET', "/api/properties/$port/connectors", null, $this->user));
    }

    public function testConnectorSecretIsNeverStoredNorExposed(): void
    {
        $port = $this->seed();
        $created = $this->api('POST', "/api/properties/$port/connectors", [
            'pluginId' => 'homey', 'name' => 'Homey salon',
            'config' => ['homeyUrl' => 'http://192.168.1.20', 'secretVar' => 'CONNECTOR_HOMEY_SALON'],
        ], $this->admin);
        $this->assertStatus(201);
        self::assertSame('CONNECTOR_HOMEY_SALON', $created['config']['secretVar'], 'only the .env variable name is stored, never the secret value');
        self::assertFalse($created['secrets']['secretVar'], 'the variable is not set in .env in this test environment');

        // Public address rejected for a local Homey
        $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'homey', 'config' => ['homeyUrl' => 'https://example.org']], $this->admin);
        $this->assertStatus(400);

        // Secret name must start with CONNECTOR_
        $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'homey', 'config' => ['secretVar' => 'HOMEY_API_KEY']], $this->admin);
        $this->assertStatus(400);
    }

    public function testConnectorTestAction(): void
    {
        $port = $this->seed();
        $connectors = $this->api('GET', "/api/properties/$port/connectors", null, $this->user);
        $id = $connectors[0]['id'];
        $result = $this->api('POST', "/api/connectors/$id/test", null, $this->admin);
        self::assertStringContainsString('appareil', $result['result']);
    }

    /** Demo property with its seeded Homey connector, as the sync does; returns the id of "Le port". */
    private function seed(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $properties = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');

        return $properties['Le port'];
    }
}
