<?php

namespace App\Tests\Unit;

use App\Clean\CleanClient;
use App\Clean\DemoClean;
use App\Place\DemoPlace;
use App\Stock\DemoStock;
use App\Stock\StockClient;
use PHPUnit\Framework\TestCase;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** PMS → Rocket Clean / Rocket Stock: URLs, bodies, tokens (static or Rocket Auth audience), errors. No network. */
final class BrickClientsTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string, auth: string}> */
    private array $calls = [];

    private function http(int $status = 200, string $body = '[]'): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($status, $body): MockResponse {
            $auth = '';
            foreach ($options['headers'] as $h) {
                if (str_starts_with($h, 'Authorization: ')) {
                    $auth = substr($h, 15);
                }
            }
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? ''), 'auth' => $auth];

            return new MockResponse($body, ['http_code' => $status]);
        });
    }

    private function demo(): DemoPlace
    {
        return new DemoPlace(sys_get_temp_dir().'/pms-demo-'.bin2hex(random_bytes(4)).'.json');
    }

    public function testCleanClientCallsRocketClean(): void
    {
        $clean = new CleanClient($this->http(), new DemoClean($this->demo()), 'http://clean.test/', 'rcl_static');
        self::assertFalse($clean->isDemo());
        $clean->createCleaning('p1', ['scheduledAt' => '2026-10-01T11:00:00+02:00', 'externalRef' => 'booking:3:checkout', 'type' => 'rental', 'origin' => 'pms']);
        $clean->putOccupancy('p1', [['from' => '2026-10-01T15:00:00+02:00', 'until' => '2026-10-03T11:00:00+02:00', 'externalRef' => 'booking:3']]);
        $clean->updateCleaning('c1', ['status' => 'cancelled']);
        $clean->cleaningLink('c1');

        self::assertSame(['POST http://clean.test/api/places/p1/cleanings', 'PUT http://clean.test/api/places/p1/occupancy', 'PATCH http://clean.test/api/cleanings/c1', 'GET http://clean.test/api/cleanings/c1/link'],
            array_map(static fn (array $c) => $c['method'].' '.$c['url'], $this->calls));
        self::assertSame('rental', json_decode($this->calls[0]['body'], true)['type']);
        self::assertSame('booking:3', json_decode($this->calls[1]['body'], true)[0]['externalRef']);
        self::assertSame('Bearer rcl_static', $this->calls[0]['auth']);
    }

    public function testSuiteTokensUseTheBrickAudience(): void
    {
        $tokens = $this->createMock(ServiceTokenProvider::class);
        $tokens->method('isAvailable')->willReturn(true);
        $tokens->expects(self::exactly(2))->method('tokenForClient')->willReturnCallback(static fn (string $aud) => 'tok-'.$aud);
        (new CleanClient($this->http(), new DemoClean($this->demo()), 'http://clean.test', '', $tokens))->cleanings('p1');
        (new StockClient($this->http(), new DemoStock($this->demo()), 'http://stock.test', '', $tokens))->levels('p1');
        self::assertSame(['Bearer tok-rocket-clean', 'Bearer tok-rocket-stock'], array_column($this->calls, 'auth'));
        self::assertSame('http://stock.test/api/stock-levels?place=/api/places/p1', $this->calls[1]['url']);
    }

    public function testStockClientErrorsAreClear(): void
    {
        $stock = new StockClient($this->http(401), new DemoStock($this->demo()), 'http://stock.test', 'rst_x');
        try {
            $stock->items();
            self::fail('401 expected to become a 502');
        } catch (HttpException $e) {
            self::assertSame(502, $e->getStatusCode());
            self::assertStringContainsString('rocket.stock.token', $e->getMessage());
        }
    }

    public function testWithoutConfigurationTheDemoAnswers(): void
    {
        $demo = $this->demo();
        $stock = new StockClient($this->http(), new DemoStock($demo), '', '');
        $clean = new CleanClient($this->http(), new DemoClean($demo), '', '');
        self::assertTrue($stock->isDemo());
        self::assertNotSame([], $stock->items());
        $clean->putOccupancy(DemoPlace::PORT, [['from' => '2026-10-01T15:00:00+02:00', 'until' => '2026-10-03T11:00:00+02:00', 'externalRef' => 'booking:3']]);
        self::assertCount(1, $demo->handle('GET', '/api/places/'.DemoPlace::PORT.'/occupancy', null, []));
        self::assertSame([], $this->calls);
    }
}
