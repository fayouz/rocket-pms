<?php

namespace App\Tests\Functional;

use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A client application (e.g. LoussaHousing) calls PMS server-to-server with its application token (Bearer rpm_…):
 * PMS business endpoints are open to it (App\Security\PmsAccessVoter, App\Security\PmsScopeGuardListener), rocket-core
 * administration and connector secrets are not. Demo Lodgify / demo Rocket Place only: nothing real is called.
 */
final class ApplicationAccessTest extends WebTestCase
{
    use ApiTestTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoPlace::class)->reset();
    }

    public function testApplicationTokenReadsPropertiesBookingsAndPlaceProxies(): void
    {
        [, $secret] = $this->createApplication(false, 'LoussaHousing');
        $app = 'Bearer '.$secret;

        self::assertSame(['created' => 2], $this->api('POST', '/api/properties/sync', [], $app));
        $port = $this->api('GET', '/api/properties', null, $app)[0]['id'];
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->api('PUT', "/api/properties/$port/place", ['placeId' => DemoPlace::PORT], $admin);

        foreach (["/api/properties/$port/bookings", "/api/properties/$port/bookings/5/pricing", "/api/properties/$port/bookings/5/conversation",
            "/api/properties/$port/locks", "/api/properties/$port/codes", "/api/properties/$port/domotique", "/api/properties/$port/documents",
            "/api/properties/$port/stock", '/api/timeline'] as $uri) {
            $this->api('GET', $uri, null, $app);
            $this->assertStatus(200);
        }
    }

    public function testApplicationTokenCannotReachAdministrationOrSecrets(): void
    {
        [, $secret] = $this->createApplication(false, 'LoussaHousing');
        foreach (['/api/users', '/api/applications', '/api/plugins', '/api/places'] as $uri) {
            $this->api('GET', $uri, null, 'Bearer '.$secret);
            $this->assertStatus(403);
        }
    }
}
