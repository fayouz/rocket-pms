<?php

namespace App\Tests\Functional;

use App\Place\DemoPlace;
use App\Repository\PropertyRepository;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Overview of the property ↔ Rocket Place links (GET /api/place-links), demo Place only: no network call. */
final class PlaceLinksTest extends WebTestCase
{
    use ApiTestTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoPlace::class)->reset();
        HttpMock::reset();
    }

    protected function tearDown(): void
    {
        self::assertSame([], HttpMock::$requests, 'no outgoing HTTP request in demo mode');
        parent::tearDown();
    }

    public function testOverviewStatusesAndCounts(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->api('POST', '/api/properties/sync', [], $admin);
        $ids = array_column($this->api('GET', '/api/properties', null, $admin), 'id', 'name');
        $this->api('PUT', "/api/properties/{$ids['Le port']}/place", ['placeId' => DemoPlace::PORT], $admin);
        $this->api('POST', '/api/planning/run', null, $admin);

        $overview = $this->api('GET', '/api/place-links', null, $admin);
        $this->assertStatus(200);
        self::assertTrue($overview['demo']);
        self::assertNull($overview['placeFrontUrl']);
        self::assertContains(DemoPlace::PORT, array_column($overview['places'], 'id'));
        $rows = array_column($overview['properties'], null, 'name');
        self::assertSame('linked', $rows['Le port']['status']);
        self::assertSame(1001, $rows['Le port']['lodgifyPropertyId']);
        self::assertSame(1, $rows['Le port']['counts']['locks']);
        self::assertGreaterThan(0, $rows['Le port']['counts']['upcomingGrants']);
        self::assertGreaterThan(0, $rows['Le port']['counts']['openCleanings']);
        self::assertArrayHasKey('lowStock', $rows['Le port']['counts']);
        self::assertSame('unlinked', $rows['Les vignes']['status']);
        self::assertNull($rows['Les vignes']['counts']);

        // A place deleted in Rocket Place: "missing"
        $vignes = static::getContainer()->get(PropertyRepository::class)->findOneBy(['name' => 'Les vignes']);
        $vignes->setPlaceId('0192f7c4-dead-7000-8000-000000000000');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('missing', array_column($this->api('GET', '/api/place-links', null, $admin)['properties'], 'status', 'name')['Les vignes']);

        // Simple users cannot, applications acting for themselves can
        $this->api('GET', '/api/place-links', null, 'Bearer '.$this->jwtFor($this->createUser('alice@example.org')));
        $this->assertStatus(403);
        [, $secret] = $this->createApplication(false, 'LoussaHousing');
        $this->api('GET', '/api/place-links', null, 'Bearer '.$secret);
        $this->assertStatus(200);
    }
}
