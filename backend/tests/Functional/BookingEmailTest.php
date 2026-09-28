<?php

namespace App\Tests\Functional;

use App\Mailer\DemoMailer;
use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** E-mails of a booking through the demo Rocket Mailer: linked conversations, thread, send on click (idempotent). No network. */
final class BookingEmailTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoPlace::class)->reset();
        static::getContainer()->get(DemoMailer::class)->reset();
        static::getContainer()->get('cache.app')->clear();
        HttpMock::reset();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->user = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
    }

    protected function tearDown(): void
    {
        self::assertSame([], HttpMock::$requests, 'no outgoing HTTP request in demo mode');
        parent::tearDown();
    }

    public function testLinkedConversationsAndThread(): void
    {
        $vignes = $this->property('Les vignes');
        $list = $this->api('GET', "/api/properties/$vignes/bookings/2/emails", null, $this->user);
        $this->assertStatus(200);
        self::assertTrue($list['demo']);
        self::assertTrue($list['available']);
        self::assertSame('marc.demo@guest.booking.com', $list['guestEmail']);
        self::assertCount(1, $list['conversations'], 'only the conversation of the guest, not the unrelated one');
        $conv = $list['conversations'][0];
        self::assertSame('guest', $conv['matchedBy']);

        $thread = $this->api('GET', "/api/properties/$vignes/bookings/2/emails/{$conv['id']}", null, $this->user);
        $this->assertStatus(200);
        self::assertSame(['guest', 'host'], array_column($thread['messages'], 'from'));
        self::assertStringContainsString('18 h', $thread['messages'][0]['text']);

        // a conversation of another guest is not readable through this booking
        $other = $this->api('GET', "/api/properties/$vignes/bookings/4/emails", null, $this->user)['conversations'][0]['id'];
        $this->api('GET', "/api/properties/$vignes/bookings/2/emails/$other", null, $this->user);
        $this->assertStatus(404);

        $this->api('GET', "/api/properties/$vignes/bookings/2/emails");
        $this->assertStatus(401);
        $this->api('GET', "/api/properties/$vignes/bookings/1/emails", null, $this->user);
        $this->assertStatus(404);
    }

    public function testSendOnlyOnceAndOnlyWithAGuestAddress(): void
    {
        $vignes = $this->property('Les vignes');
        $payload = ['subject' => 'Votre arrivée', 'text' => "Bonjour Paul,\nÀ bientôt !", 'messageId' => '0192f7c4-1111-7000-8000-000000000001'];

        $sent = $this->api('POST', "/api/properties/$vignes/bookings/4/emails", $payload, $this->user);
        $this->assertStatus(200);
        self::assertFalse($sent['duplicate']);
        self::assertTrue($sent['demo']);
        $again = $this->api('POST', "/api/properties/$vignes/bookings/4/emails", $payload, $this->user);
        self::assertTrue($again['duplicate']);
        $recorded = static::getContainer()->get(DemoMailer::class)->sent();
        self::assertCount(1, $recorded);
        self::assertSame(['paul.demo@example.org'], $recorded[0]['to']);

        $this->api('POST', "/api/properties/$vignes/bookings/6/emails", ['messageId' => '0192f7c4-1111-7000-8000-000000000002'] + $payload, $this->user);
        $this->assertStatus(422); // Lodgify gives no e-mail for this guest
        $this->api('POST', "/api/properties/$vignes/bookings/4/emails", ['subject' => '', 'text' => 'x', 'messageId' => '0192f7c4-1111-7000-8000-000000000003'], $this->user);
        $this->assertStatus(422);
        $this->api('POST', "/api/properties/$vignes/bookings/4/emails", ['messageId' => 'nope'] + $payload, $this->user);
        $this->assertStatus(422);
    }

    private function property(string $name): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);

        return array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')[$name];
    }
}
