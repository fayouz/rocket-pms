<?php

namespace App\Tests\Functional;

use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PMS as a client of Rocket Place: linking a property to its place, access grants planned idempotently (externalRef =
 * booking id), the proxies (locks, domotique, documents, stock) and the 409 of a property without a place. Rocket
 * Place is App\Place\DemoPlace here (no ROCKET_PLACE_URL): no network call at all.
 */
final class PlaceTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoPlace::class)->reset();
        HttpMock::reset();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->user = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
    }

    protected function tearDown(): void
    {
        self::assertSame([], HttpMock::$requests, 'no outgoing HTTP request in demo mode');
        parent::tearDown();
    }

    public function testAPropertyWithoutPlaceAnswers409(): void
    {
        $port = $this->properties()['Le port'];
        foreach (['locks', 'codes', 'access-grants', 'domotique', 'documents', 'stock'] as $path) {
            $body = $this->api('GET', "/api/properties/$port/$path", null, $this->user);
            $this->assertStatus(409);
            self::assertStringContainsString('Rocket Place', (string) ($body['detail'] ?? ''), $path);
        }
        // Bookings still work, without any code
        $bookings = $this->api('GET', "/api/properties/$port/bookings", null, $this->user);
        $this->assertStatus(200);
        self::assertSame([null, null, null], array_column($bookings['items'], 'access'));
    }

    public function testLinkingAPropertyToAPlace(): void
    {
        $port = $this->properties()['Le port'];

        $this->api('GET', '/api/places', null, $this->user);
        $this->assertStatus(403);
        $places = $this->api('GET', '/api/places', null, $this->admin);
        self::assertTrue($places['demo']);
        self::assertSame(['Le port', 'Les vignes'], array_column($places['places'], 'name'));

        $this->api('PUT', "/api/properties/$port/place", ['placeId' => DemoPlace::PORT], $this->user);
        $this->assertStatus(403);
        $this->api('PUT', "/api/properties/$port/place", ['placeId' => 'nope'], $this->admin);
        $this->assertStatus(422);
        $this->api('PUT', "/api/properties/$port/place", ['placeId' => '0192f7c4-7f38-7d3a-9a8c-3b2a1f0e9d8c'], $this->admin);
        $this->assertStatus(404);

        self::assertSame(DemoPlace::PORT, $this->api('PUT', "/api/properties/$port/place", ['placeId' => DemoPlace::PORT], $this->admin)['placeId']);
        self::assertSame(DemoPlace::PORT, $this->api('GET', "/api/properties/$port", null, $this->user)['placeId']);

        // placeId is not writable through the generic PATCH
        $this->api('PATCH', "/api/properties/$port", ['placeId' => null], $this->admin);
        self::assertSame(DemoPlace::PORT, $this->api('GET', "/api/properties/$port", null, $this->user)['placeId']);

        // Already linked: creating a place is refused; unlink then create one from the property
        $this->api('POST', "/api/properties/$port/place", null, $this->admin);
        $this->assertStatus(409);
        self::assertNull($this->api('PUT', "/api/properties/$port/place", ['placeId' => null], $this->admin)['placeId']);
        $created = $this->api('POST', "/api/properties/$port/place", null, $this->admin);
        $this->assertStatus(201);
        self::assertNotSame(DemoPlace::PORT, $created['placeId']);
        self::assertContains('Le port', array_column($this->api('GET', '/api/places', null, $this->admin)['places'], 'name'));
        self::assertCount(3, $this->api('GET', '/api/places', null, $this->admin)['places']);
    }

    public function testAccessGrantsArePlannedIdempotently(): void
    {
        $port = $this->linked();

        $first = $this->api('GET', "/api/properties/$port/access-grants", null, $this->user);
        self::assertCount(1, $first);
        self::assertSame('5', $first[0]['externalRef'], 'externalRef = booking id');
        self::assertSame('planned', $first[0]['status']);
        self::assertSame(90001, $first[0]['lockId']);

        // Bookings, codes, timeline and a second listing all plan again: still one grant, same code
        $this->api('GET', "/api/properties/$port/bookings", null, $this->user);
        $this->api('GET', "/api/properties/$port/codes", null, $this->user);
        $this->api('GET', "/api/properties/$port/timeline", null, $this->user);
        $again = $this->api('GET', "/api/properties/$port/access-grants", null, $this->user);
        self::assertSame([$first[0]['id']], array_column($again, 'id'));
        self::assertSame($first[0]['code'], $again[0]['code']);

        $bookings = $this->api('GET', "/api/properties/$port/bookings", null, $this->user)['items'];
        $upcoming = array_values(array_filter($bookings, static fn (array $b) => 5 === $b['id']))[0];
        self::assertSame($first[0]['id'], $upcoming['access']['grantId']);

        // Revoking is admin-only; a revoked grant is planned again (new grant) on the next listing
        $this->api('POST', "/api/properties/$port/access-grants/{$first[0]['id']}/revoke", null, $this->user);
        $this->assertStatus(403);
        self::assertSame('revoked', $this->api('POST', "/api/properties/$port/access-grants/{$first[0]['id']}/revoke", null, $this->admin)['status']);
        $replanned = array_values(array_filter($this->api('GET', "/api/properties/$port/access-grants", null, $this->user), static fn (array $g) => 'revoked' !== $g['status']));
        self::assertCount(1, $replanned);
        self::assertNotSame($first[0]['id'], $replanned[0]['id']);
    }

    public function testAGrantOfAnotherPropertyIsRefused(): void
    {
        $port = $this->linked();
        $vignes = $this->properties()['Les vignes'];
        $this->api('PUT', "/api/properties/$vignes/place", ['placeId' => DemoPlace::VIGNES], $this->admin);
        $grant = $this->api('GET', "/api/properties/$port/access-grants", null, $this->user)[0];

        $this->api('POST', "/api/properties/$vignes/access-grants/{$grant['id']}/send", null, $this->user);
        $this->assertStatus(404);
        $this->api('POST', "/api/properties/$vignes/access-grants/{$grant['id']}/revoke", null, $this->admin);
        $this->assertStatus(404);
    }

    public function testDomotiqueDocumentsAndStockProxies(): void
    {
        $port = $this->linked();

        $domotique = $this->api('GET', "/api/properties/$port/domotique", null, $this->user);
        self::assertSame('Salon - thermostat', $domotique['sections'][0]['cards'][0]['title']);

        $documents = $this->api('GET', "/api/properties/$port/documents", null, $this->user);
        self::assertSame(['Bienvenue.pdf'], array_column($documents['items'], 'name'));
        $this->api('POST', "/api/properties/$port/documents/folders", ['name' => 'Contrats'], $this->user);
        $this->assertStatus(403);
        $folder = $this->api('POST', "/api/properties/$port/documents/folders", ['name' => 'Contrats'], $this->admin);
        $this->assertStatus(201);
        self::assertSame('folder', $folder['kind']);
        self::assertCount(2, $this->api('GET', "/api/properties/$port/documents", null, $this->user)['items']);
        $this->api('DELETE', "/api/properties/$port/documents/{$folder['id']}", null, $this->admin);
        $this->assertStatus(200);
        $this->client->request('GET', "/api/properties/$port/documents/{$documents['items'][0]['id']}/content", server: ['HTTP_AUTHORIZATION' => $this->user]);
        $this->assertStatus(200);
        self::assertSame("Document de démonstration.\n", $this->client->getResponse()->getContent());

        $stock = $this->api('GET', "/api/properties/$port/stock", null, $this->user);
        self::assertCount(2, $stock['items']);
        self::assertCount(2, $stock['levels']);
        self::assertSame(['/api/places/'.DemoPlace::PORT], array_values(array_unique(array_column($stock['levels'], 'place'))));
        $level = $stock['levels'][0]['id'];
        self::assertSame('empty', $this->api('PATCH', "/api/properties/$port/stock/$level", ['level' => 'empty'], $this->user)['level']);

        // A stock level of another place is not reachable from this property
        $vignes = $this->properties()['Les vignes'];
        $this->api('PUT', "/api/properties/$vignes/place", ['placeId' => DemoPlace::VIGNES], $this->admin);
        $this->api('PATCH', "/api/properties/$vignes/stock/$level", ['level' => 'ok'], $this->user);
        $this->assertStatus(404);
    }

    /** @return array<string, string> property ids by name, after the Lodgify sync */
    private function properties(): array
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);

        return array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');
    }

    /** "Le port" linked to its demo place (lock 90001, upcoming booking 5). */
    private function linked(): string
    {
        $port = $this->properties()['Le port'];
        $this->api('PUT', "/api/properties/$port/place", ['placeId' => DemoPlace::PORT], $this->admin);

        return $port;
    }
}
