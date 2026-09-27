<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lock providers beyond the legacy env-token Nuki API: a connector plugin can declare 'locks.state'/'locks.codes'
 * (App\Lock\LockCapablePluginInterface) and a App\Entity\SmartLock can reroute to it (App\Lock\LockProviderRegistry).
 * No real Home Assistant/Nuki account is ever called here: connectors without a configured address/token fall back
 * to demo data (App\HomeAssistant\DemoHomeAssistant, App\Nuki\DemoNuki).
 */
final class LockProvidersTest extends WebTestCase
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

    public function testCatalogueListsHomeAssistantAndNuki(): void
    {
        $plugins = $this->api('GET', '/api/plugins', null, $this->user);
        self::assertSame(['homey', 'home_assistant', 'nuki', 'webservice'], array_column($plugins, 'id'));
        self::assertSame(['locks.state', 'locks.codes'], $this->capabilitiesOf('home_assistant'));
        self::assertSame(['locks.state', 'locks.codes'], $this->capabilitiesOf('nuki'));
        self::assertSame(['locks.state'], $this->capabilitiesOf('homey'));
        self::assertSame([], $this->capabilitiesOf('webservice'));
    }

    public function testHomeAssistantConnectorRerouteesALocksLiveState(): void
    {
        $port = $this->seedPortWithLock();

        $connector = $this->api('POST', "/api/properties/$port/connectors", [
            'pluginId' => 'home_assistant', 'name' => 'HA salon', 'config' => ['baseUrl' => 'http://homeassistant.local:8123'],
        ], $this->admin);
        $this->assertStatus(201);

        // Not configured (no secretVar / real token): demo entities, so the connector's own info still works offline.
        $domotique = $this->api('GET', "/api/properties/$port/domotique", null, $this->user);
        self::assertNull($domotique['sections'][0]['error']);
        self::assertNotEmpty($domotique['sections'][0]['cards']);

        // Reroute the seeded lock (90001) to that HA connector, matching one of the demo entities.
        $this->api('PUT', '/api/locks/90001', ['property' => $port, 'connector' => $connector['id'], 'externalId' => 'lock.entree_port'], $this->admin);
        $this->assertStatus(200);

        $locks = $this->api('GET', "/api/properties/$port/locks", null, $this->user);
        $lock = $locks['locks'][0];
        self::assertSame('home_assistant', $lock['provider']);
        self::assertSame('Port - entrée', $lock['name']);
        self::assertTrue($lock['locked']); // demo entity lock.entree_port is "locked"
    }

    public function testHomeAssistantConnectorMustDeclareAServiceToWriteACode(): void
    {
        $port = $this->seedPortWithLock();
        $connector = $this->api('POST', "/api/properties/$port/connectors", [
            'pluginId' => 'home_assistant', 'name' => 'HA salon', 'config' => ['baseUrl' => 'http://homeassistant.local:8123'],
        ], $this->admin);
        $this->api('PUT', '/api/locks/90001', ['property' => $port, 'codeConnector' => $connector['id']], $this->admin);

        $codes = $this->api('GET', "/api/properties/$port/codes", null, $this->user);
        self::assertNotEmpty($codes['items'], 'the demo seeder plans an upcoming booking on "Le port"');
        $bookingId = $codes['items'][0]['bookingId'];

        $this->api('POST', "/api/codes/$bookingId", null, $this->admin);
        $this->assertStatus(400);
    }

    public function testConnectorWithoutLockCapabilityIsRejectedAsALockProvider(): void
    {
        $port = $this->seedPortWithLock();
        $connector = $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'webservice', 'name' => 'API météo', 'config' => ['baseUrl' => 'https://example.org']], $this->admin);
        $this->api('PUT', '/api/locks/90001', ['property' => $port, 'connector' => $connector['id']], $this->admin);

        $this->api('GET', "/api/properties/$port/locks", null, $this->user);
        $this->assertStatus(400);
    }

    public function testNukiConnectorRequiresATokenVariable(): void
    {
        $port = $this->seedPortWithLock();
        $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'nuki', 'name' => 'Nuki secondaire', 'config' => []], $this->admin);
        $this->assertStatus(400);

        $created = $this->api('POST', "/api/properties/$port/connectors", ['pluginId' => 'nuki', 'name' => 'Nuki secondaire', 'config' => ['secretVar' => 'CONNECTOR_NUKI_SALON']], $this->admin);
        $this->assertStatus(201);

        // The variable is not set in .env in this test environment: the "test" action fails clearly, no network call.
        $this->api('POST', "/api/connectors/{$created['id']}/test", null, $this->admin);
        $this->assertStatus(400);
    }

    /** @return list<string> */
    private function capabilitiesOf(string $pluginId): array
    {
        return static::getContainer()->get(\App\Domotique\PluginRegistry::class)->get($pluginId)->capabilities();
    }

    /** Demo property "Le port" with its seeded lock (90001); returns the property id. */
    private function seedPortWithLock(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $properties = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');

        return $properties['Le port'];
    }
}
