<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Documents" tab of a property: folders and files proxied to Rocket Cloud. Without ROCKET_CLOUD_URL/TOKEN in this
 * test environment, App\Cloud\DemoCloud answers (no network call), which also seeds a demo document per property.
 */
final class DocumentTest extends WebTestCase
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

    public function testListingCreatesTheFolderLazily(): void
    {
        $port = $this->seed();

        $this->api('GET', "/api/properties/$port/documents");
        $this->assertStatus(401);

        $documents = $this->api('GET', "/api/properties/$port/documents", null, $this->user);
        self::assertNotEmpty($documents['folderId']);
        self::assertCount(1, $documents['items']);
        self::assertSame('Bienvenue.pdf', $documents['items'][0]['name']);
        self::assertSame('file', $documents['items'][0]['kind']);
    }

    public function testOnlyAnAdminWrites(): void
    {
        $port = $this->seed();

        $this->api('POST', "/api/properties/$port/documents/folders", ['name' => 'Contrats'], $this->user);
        $this->assertStatus(403);

        $created = $this->api('POST', "/api/properties/$port/documents/folders", ['name' => 'Contrats'], $this->admin);
        $this->assertStatus(201);
        self::assertSame('folder', $created['kind']);
        self::assertSame('Contrats', $created['name']);

        $documents = $this->api('GET', "/api/properties/$port/documents", null, $this->user);
        self::assertCount(2, $documents['items']);

        $this->api('DELETE', "/api/properties/$port/documents/{$created['id']}", null, $this->user);
        $this->assertStatus(403);
        $this->api('DELETE', "/api/properties/$port/documents/{$created['id']}", null, $this->admin);
        $this->assertStatus(200);

        $documents = $this->api('GET', "/api/properties/$port/documents", null, $this->user);
        self::assertCount(1, $documents['items']);
    }

    /** Demo property with its seeded documents folder; returns the id of "Le port". */
    private function seed(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $properties = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');

        return $properties['Le port'];
    }
}
