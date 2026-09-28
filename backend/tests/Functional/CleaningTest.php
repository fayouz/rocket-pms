<?php

namespace App\Tests\Functional;

use App\Message\RunPlanning;
use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cleanings after departure, planned in Rocket Clean (demo) together with the access codes: one task per upcoming
 * departure (externalRef "booking:<id>:checkout"), due at the next check-in, idempotent, moved when its dates drift,
 * cancelled with its booking, shown in the timeline (which only reads). Planned by POST /api/planning/run or the
 * recurring task (RunPlanning, every 15 min). No network call.
 */
final class CleaningTest extends WebTestCase
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

    public function testCleaningPlannedAfterEachDeparture(): void
    {
        $port = $this->linked('Le port', DemoPlace::PORT);
        $this->api('GET', "/api/properties/$port/timeline", null, $this->user);
        $this->assertStatus(200);
        self::assertSame([], $this->cleanings(DemoPlace::PORT), 'reading the timeline plans nothing');

        $this->api('POST', '/api/planning/run', null, $this->user);
        $this->assertStatus(403);
        $run = $this->api('POST', '/api/planning/run', null, $this->admin);
        $this->assertStatus(200);
        self::assertTrue(array_column($run['properties'], 'ok', 'name')['Le port']);
        $timeline = $this->api('GET', "/api/properties/$port/timeline", null, $this->user);

        $cleanings = $this->cleanings(DemoPlace::PORT);
        self::assertArrayHasKey('booking:3:checkout', $cleanings);
        self::assertArrayHasKey('booking:5:checkout', $cleanings);
        $tz = new \DateTimeZone('Europe/Paris');
        $day = static fn (int $n, string $time) => (new \DateTimeImmutable('today', $tz))->modify("+$n days $time");
        $c3 = $cleanings['booking:3:checkout'];
        self::assertEquals($day(3, '11:00'), new \DateTimeImmutable($c3['scheduledAt']));
        self::assertEquals($day(8, '15:00'), new \DateTimeImmutable($c3['dueAt']), 'due at the next check-in');
        self::assertEquals($day(13, '11:00'), new \DateTimeImmutable($cleanings['booking:5:checkout']['dueAt']), 'no next stay: departure + 1 day');
        self::assertSame('todo', $c3['status']);
        self::assertSame('rental', $c3['type']);
        self::assertSame('pms', $c3['origin']);
        $occupancy = static::getContainer()->get(DemoPlace::class)->handle('GET', '/api/places/'.DemoPlace::PORT.'/occupancy', null, []);
        self::assertContains('booking:3', array_column($occupancy, 'externalRef'), 'stays pushed to Rocket Clean as occupancy');
        self::assertStringContainsString('Sofia Rossi', $c3['label']);
        self::assertContains('cleaning', array_column($timeline['events'], 'kind'));

        // Idempotent: planning again creates nothing
        $this->api('POST', '/api/planning/run', null, $this->admin);
        self::assertCount(\count($cleanings), $this->cleanings(DemoPlace::PORT));

        // Dates drifted (e.g. booking moved): the task still to do is moved back to the booking dates
        $place = static::getContainer()->get(DemoPlace::class);
        $place->handle('PATCH', '/api/cleanings/'.$c3['id'], ['scheduledAt' => $day(20, '10:00')->format(\DATE_ATOM), 'dueAt' => null], []);
        $this->api('GET', "/api/properties/$port/bookings", null, $this->user);
        $moved = $this->cleanings(DemoPlace::PORT)['booking:3:checkout'];
        self::assertEquals($day(3, '11:00'), new \DateTimeImmutable($moved['scheduledAt']));
        self::assertEquals($day(8, '15:00'), new \DateTimeImmutable($moved['dueAt']));

        // A task already started is never touched
        $place->handle('PATCH', '/api/cleanings/'.$c3['id'], ['status' => 'in_progress', 'scheduledAt' => $day(3, '12:00')->format(\DATE_ATOM)], []);
        $this->api('POST', '/api/planning/run', null, $this->admin);
        self::assertEquals($day(3, '12:00'), new \DateTimeImmutable($this->cleanings(DemoPlace::PORT)['booking:3:checkout']['scheduledAt']));
    }

    public function testCleaningOfACancelledBookingIsCancelled(): void
    {
        $place = static::getContainer()->get(DemoPlace::class);
        // Booking 6 (Les vignes) is declined: a task left from before is cancelled at the next planning
        $task = $place->handle('POST', '/api/places/'.DemoPlace::VIGNES.'/cleanings', ['scheduledAt' => (new \DateTimeImmutable('+1 day'))->format(\DATE_ATOM), 'externalRef' => 'booking:6:checkout'], []);
        $this->linked('Les vignes', DemoPlace::VIGNES);
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new RunPlanning()); // the 15-min recurring task
        $cleanings = $this->cleanings(DemoPlace::VIGNES);
        self::assertSame('cancelled', $cleanings['booking:6:checkout']['status']);
        self::assertSame($task['id'], $cleanings['booking:6:checkout']['id']);
        self::assertSame('todo', $cleanings['booking:4:checkout']['status']);
    }

    public function testCleaningLinkThroughPms(): void
    {
        $port = $this->linked('Le port', DemoPlace::PORT);
        $this->api('POST', '/api/planning/run', null, $this->admin);
        $list = $this->api('GET', "/api/properties/$port/cleanings", null, $this->admin);
        $this->assertStatus(200);
        $id = array_column($list, 'id', 'externalRef')['booking:3:checkout'];
        $event = array_values(array_filter($this->api('GET', "/api/properties/$port/timeline", null, $this->user)['events'], static fn (array $e) => 'cleaning' === $e['kind']))[0];
        self::assertNotSame('', $event['cleaningId']);

        $link = $this->api('GET', "/api/properties/$port/cleanings/$id/link", null, $this->admin);
        $this->assertStatus(200);
        self::assertStringStartsWith('/m/', $link['path']);
        self::assertSame($link, $this->api('GET', "/api/properties/$port/cleanings/$id/link", null, $this->admin), 'stable link');

        $this->api('GET', "/api/properties/$port/cleanings/$id/link", null, $this->user);
        $this->assertStatus(403);
        $vignes = $this->linked('Les vignes', DemoPlace::VIGNES);
        $this->api('GET', "/api/properties/$vignes/cleanings/$id/link", null, $this->admin);
        $this->assertStatus(404);
    }

    public function testPropertyWithoutPlaceHasNoCleaning(): void
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $port = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')['Le port'];
        $timeline = $this->api('GET', "/api/properties/$port/timeline", null, $this->user);
        $this->assertStatus(200);
        self::assertNotContains('cleaning', array_column($timeline['events'], 'kind'));
        self::assertSame([], $this->cleanings(DemoPlace::PORT));
    }

    /** @return array<string, array<string, mixed>> */
    private function cleanings(string $placeId): array
    {
        return array_column(static::getContainer()->get(DemoPlace::class)->handle('GET', "/api/places/$placeId/cleanings", null, []), null, 'externalRef');
    }

    private function linked(string $name, string $placeId): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $id = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')[$name];
        $this->api('PUT', "/api/properties/$id/place", ['placeId' => $placeId], $this->admin);
        $this->assertStatus(200);

        return $id;
    }
}
