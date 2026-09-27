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

    public function testAFolderOfAnotherPropertyIs404(): void
    {
        [$portId, $vignesId] = $this->seedTwo();

        // Create a folder under "Les vignes", then try to browse/write into it from "Le port".
        $this->api('GET', "/api/properties/$vignesId/documents", null, $this->admin);
        $foreignFolder = $this->api('POST', "/api/properties/$vignesId/documents/folders", ['name' => 'Contrats'], $this->admin);

        $this->api('GET', "/api/properties/$portId/documents?folder={$foreignFolder['id']}", null, $this->user);
        $this->assertStatus(404);

        $this->api('POST', "/api/properties/$portId/documents/folders", ['name' => 'Sous-dossier', 'folder' => $foreignFolder['id']], $this->admin);
        $this->assertStatus(404);

        $this->api('PATCH', "/api/properties/$portId/documents/{$foreignFolder['id']}", ['name' => 'Renommé'], $this->admin);
        $this->assertStatus(404);

        $this->api('DELETE', "/api/properties/$portId/documents/{$foreignFolder['id']}", null, $this->admin);
        $this->assertStatus(404);
    }

    public function testAFileOfAnotherPropertyIs404(): void
    {
        [$portId, $vignesId] = $this->seedTwo();

        $vignesDocuments = $this->api('GET', "/api/properties/$vignesId/documents", null, $this->admin);
        $foreignFile = $vignesDocuments['items'][0]['id']; // demo-seeded "Bienvenue.pdf"

        $this->api('GET', "/api/properties/$portId/documents/$foreignFile/content", null, $this->user);
        $this->assertStatus(404);

        $this->api('PATCH', "/api/properties/$portId/documents/$foreignFile", ['name' => 'Renommé'], $this->admin);
        $this->assertStatus(404);

        $this->api('DELETE', "/api/properties/$portId/documents/$foreignFile", null, $this->admin);
        $this->assertStatus(404);

        // Cannot move a document of "Le port" into "Les vignes" folder either.
        $portDocuments = $this->api('GET', "/api/properties/$portId/documents", null, $this->admin);
        $ownFile = $portDocuments['items'][0]['id'];
        $this->api('PATCH', "/api/properties/$portId/documents/$ownFile", ['folder' => $foreignFile], $this->admin);
        $this->assertStatus(404);
    }

    public function testThePropertyRootFolderCannotBeMovedOrDeleted(): void
    {
        $port = $this->seed();
        $documents = $this->api('GET', "/api/properties/$port/documents", null, $this->admin);
        $rootFolder = 'folder:'.$documents['rootFolderId'];

        $this->api('PATCH', "/api/properties/$port/documents/$rootFolder", ['folder' => null], $this->admin);
        $this->assertStatus(400);

        $this->api('DELETE', "/api/properties/$port/documents/$rootFolder", null, $this->admin);
        $this->assertStatus(400);
    }

    /** Demo property with its seeded documents folder; returns the id of "Le port". */
    private function seed(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $properties = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');

        return $properties['Le port'];
    }

    /** @return array{0: string, 1: string} ids of "Le port" and "Les vignes" */
    private function seedTwo(): array
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $properties = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');

        return [$properties['Le port'], $properties['Les vignes']];
    }
}
