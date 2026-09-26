<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The property core in demo mode (no Lodgify or Nuki key): sync, bookings with their value and conversation, locks and
 * keypad codes, timeline, dashboard, and who may do what.
 */
final class PropertyTest extends WebTestCase
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

    public function testOnlyAnAdminSyncsAndEditsProperties(): void
    {
        $this->api('GET', '/api/properties');
        $this->assertStatus(401);
        $this->api('POST', '/api/properties/sync', [], $this->user);
        $this->assertStatus(403);

        self::assertSame(['created' => 2, 'locks' => 2], $this->api('POST', '/api/properties/sync', [], $this->admin));
        self::assertSame(['created' => 0, 'locks' => 0], $this->api('POST', '/api/properties/sync', [], $this->admin));
        $properties = $this->api('GET', '/api/properties', null, $this->user);
        self::assertSame(['Le port', 'Les vignes'], array_column($properties, 'name'));

        $id = $properties[0]['id'];
        $this->api('PATCH', "/api/properties/$id", ['color' => 'green'], $this->user);
        $this->assertStatus(403);
        self::assertSame('green', $this->api('PATCH', "/api/properties/$id", ['color' => 'green'], $this->admin)['color']);
        $this->api('PATCH', "/api/properties/$id", ['color' => 'fuchsia'], $this->admin);
        $this->assertStatus(422);
    }

    public function testBookingsValueAndConversation(): void
    {
        $port = $this->seed();
        $bookings = $this->api('GET', "/api/properties/$port/bookings", null, $this->user);
        self::assertTrue($bookings['demo']);
        self::assertSame([5, 3, 1], array_column($bookings['items'], 'id'), 'newest arrival first, only this property');
        $current = array_values(array_filter($bookings['items'], static fn (array $b) => 3 === $b['id']))[0];
        self::assertSame('now', $current['phase']);

        $pricing = $this->api('GET', "/api/properties/$port/bookings/5/pricing", null, $this->user);
        self::assertSame(420.0, (float) $pricing['total']);
        self::assertEqualsWithDelta(420.0, array_sum(array_column($pricing['lines'], 'amount')), 0.001);
        self::assertSame('Nuitées', $pricing['lines'][0]['label']);

        $messages = $this->api('GET', "/api/properties/$port/bookings/5/conversation", null, $this->user)['messages'];
        self::assertSame(['host', 'guest', 'host'], array_column($messages, 'from'));
        self::assertStringContainsString("jour de l'arrivée", $messages[2]['text'], 'HTML entities decoded, tags removed');

        // A booking of another property is refused; replying is impossible in demo mode
        $this->api('GET', "/api/properties/$port/bookings/2/pricing", null, $this->user);
        $this->assertStatus(404);
        $this->api('POST', "/api/properties/$port/bookings/5/conversation", ['text' => 'Bonjour', 'messageId' => '0192f7c4-7f38-7d3a-9a8c-3b2a1f0e9d8c'], $this->user);
        $this->assertStatus(400);
        $this->api('POST', "/api/properties/$port/bookings/5/conversation", ['text' => ' ', 'messageId' => 'x'], $this->user);
        $this->assertStatus(422);
    }

    public function testLocksAndKeypadCodes(): void
    {
        $port = $this->seed();
        $locks = $this->api('GET', "/api/properties/$port/locks", null, $this->user)['locks'];
        self::assertSame([90001], array_column($locks, 'id'));

        $codes = $this->api('GET', "/api/properties/$port/codes", null, $this->user)['items'];
        self::assertSame([5], array_column($codes, 'bookingId'), 'only upcoming stays get a code');
        self::assertMatchesRegularExpression('/^[1-9]{6}$/', $codes[0]['code']);
        self::assertStringStartsNotWith('12', $codes[0]['code']);
        self::assertSame('planned', $codes[0]['status']);
        // Opens 1 h before check-in (15:00 Paris) and closes 1 h after check-out (11:00)
        self::assertSame('14:00', (new \DateTimeImmutable($codes[0]['validFrom']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'));
        self::assertSame('12:00', (new \DateTimeImmutable($codes[0]['validUntil']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'));

        // Nothing is sent to Nuki in demo mode; a started stay is never touched
        $this->api('POST', '/api/codes/5', null, $this->user);
        $this->assertStatus(400);
        $this->api('POST', '/api/codes/3', null, $this->user);
        $this->assertStatus(404);

        // Only an admin links a lock to a property
        $this->api('PUT', '/api/locks/90001', ['property' => null], $this->user);
        $this->assertStatus(403);
        $this->api('PUT', '/api/locks/90001', ['property' => null], $this->admin);
        $this->assertStatus(200);
        self::assertSame([], $this->api('GET', "/api/properties/$port/locks", null, $this->user)['locks']);
    }

    public function testTimelineAndDashboard(): void
    {
        $port = $this->seed();
        $events = $this->api('GET', "/api/properties/$port/timeline?past=5&future=15", null, $this->user)['events'];
        $titles = array_column($events, 'title');
        self::assertContains('Arrivée · Sofia Rossi', $titles);
        self::assertContains('Départ · Alex Martin', $titles);
        self::assertNotEmpty(array_filter($titles, static fn (string $t) => str_starts_with($t, 'Code ')));
        self::assertSame(array_column($events, 'at'), array_values(array_column($events, 'at')), 'sorted by date');

        $dashboard = $this->api('GET', '/api/dashboard', null, $this->admin);
        $kpis = array_column($dashboard['kpis'], 'value', 'id');
        self::assertSame(1, $kpis['arrivals_today']);
        self::assertArrayHasKey('occupancy', $kpis);
    }

    /** Demo properties and locks, as the demo seeder does; returns the id of "Le port". */
    private function seed(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $properties = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name');
        $this->api('PUT', '/api/locks/90001', ['property' => $properties['Le port']], $this->admin);
        $this->api('PUT', '/api/locks/90002', ['property' => $properties['Les vignes']], $this->admin);

        return $properties['Le port'];
    }
}
