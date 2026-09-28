<?php

namespace App\Tests\Functional;

use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rocket Cast reads the screen of a property with an application token: GET /api/cast/properties/{id} = the public TV
 * payload + today's arrivals/departures + a stable "version". Demo Lodgify / Place only, no network call.
 */
final class CastTest extends WebTestCase
{
    use ApiTestTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoPlace::class)->reset();
    }

    public function testCastReadsTheScreenOfAProperty(): void
    {
        [, $secret] = $this->createApplication(false, 'Rocket Cast');
        $app = 'Bearer '.$secret;
        $this->api('POST', '/api/properties/sync', [], $app);
        $port = array_column($this->api('GET', '/api/properties', null, $app), 'id', 'name')['Le port'];

        $screen = $this->api('GET', "/api/cast/properties/$port", null, $app);
        $this->assertStatus(200);
        self::assertSame('Le port', $screen['property']);
        self::assertSame($port, $screen['propertyId']);
        self::assertIsArray($screen['arrivals']);
        self::assertIsArray($screen['departures']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $screen['version']);
        self::assertArrayHasKey('content', $screen);
        self::assertStringNotContainsString('code', strtolower(implode(',', array_keys($screen))), 'never a keypad code');
        self::assertSame($screen['version'], $this->api('GET', "/api/cast/properties/$port", null, $app)['version'], 'stable version');

        // Same payload as the public TV route
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $book = $this->api('GET', "/api/properties/$port/welcome-book", null, $admin);
        $tv = $this->api('GET', '/api/public/tv/'.$book['tvToken']);
        self::assertSame($tv['content'], $screen['content']);
        self::assertSame($tv['guest'], $screen['guest']);

        // The welcome book changes: new version
        $this->api('PUT', "/api/properties/$port/welcome-book", ['content' => ['welcomeText' => 'Bienvenue au port !']], $admin);
        $this->assertStatus(200);
        self::assertNotSame($screen['version'], $this->api('GET', "/api/cast/properties/$port", null, $app)['version']);

        $this->api('GET', "/api/cast/properties/$port");
        $this->assertStatus(401);
    }
}
