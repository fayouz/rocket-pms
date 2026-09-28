<?php

namespace App\Tests\Unit;

use App\Mailer\DemoMailer;
use App\Mailer\InboxUnavailable;
use App\Mailer\MailerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** PMS → Rocket Mailer: impersonation of the signed-in user, sending mailbox, inbox refused to the application. No network. */
final class MailerClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $requests = [];

    private function client(int $status, string $body, string $inbox = 'box-1', string $mailbox = ''): MailerClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($status, $body): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => (string) ($options['body'] ?? '')];

            return new MockResponse($body, ['http_code' => $status]);
        });

        return new MailerClient($http, new DemoMailer(sys_get_temp_dir().'/pms-demo-mailer-'.bin2hex(random_bytes(4)).'.json'), 'http://mailer.test', 'rma_static', $inbox, $mailbox);
    }

    public function testSendImpersonatesTheUserWithTheSendingMailbox(): void
    {
        $sent = $this->client(201, '{"id":"e1","status":"queued"}', mailbox: 'mb-9')->send('alice@example.org', ['to' => ['g@example.org'], 'subject' => 'S', 'htmlBody' => 'B']);
        self::assertSame('e1', $sent['id']);
        self::assertSame('http://mailer.test/api/emails', $this->requests[0]['url']);
        self::assertContains('X-Impersonate-User: alice@example.org', $this->requests[0]['headers']);
        self::assertContains('Authorization: Bearer rma_static', $this->requests[0]['headers']);
        self::assertSame('/api/mailboxes/mb-9', json_decode($this->requests[0]['body'], true)['mailbox']);
    }

    public function testInboxRefusedToTheApplicationIsUnavailable(): void
    {
        $this->expectException(InboxUnavailable::class);
        $this->client(403, '{"detail":"Access Denied."}')->conversations('alice@example.org', 'x');
    }

    public function testNoInboxConfigured(): void
    {
        $this->expectException(InboxUnavailable::class);
        $this->client(200, '[]', inbox: '')->conversations('alice@example.org', 'x');
    }

    public function testConversationsAreRead(): void
    {
        $list = $this->client(200, '{"items":[{"id":"c1","subject":"Hello"}]}')->conversations('alice@example.org', 'guest@example.org');
        self::assertSame('c1', $list[0]['id']);
        self::assertStringStartsWith('http://mailer.test/api/inbox/mailboxes/box-1/conversations?q=guest@example.org', $this->requests[0]['url']);
    }

    public function testErrorsAreMapped(): void
    {
        try {
            $this->client(500, '')->send('a@example.org', ['to' => ['g@example.org'], 'subject' => 'S', 'htmlBody' => 'B']);
            self::fail('expected 502');
        } catch (HttpException $e) {
            self::assertSame(502, $e->getStatusCode());
        }
    }
}
