<?php

namespace App\Tests\Unit;

use App\Place\DemoPlace;
use App\Place\PlaceClient;
use PHPUnit\Framework\TestCase;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** PMS → Rocket Place: token of Rocket Auth in suite mode, static token (secret rocket.place.token) otherwise. No network. */
final class PlaceClientSuiteTokenTest extends TestCase
{
    /** @var list<string> */
    private array $authorizations = [];

    private function client(string $token, ?ServiceTokenProvider $tokens, int $status = 200): PlaceClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($status): MockResponse {
            foreach ($options['headers'] as $header) {
                if (str_starts_with($header, 'Authorization: ')) {
                    $this->authorizations[] = substr($header, 15);
                }
            }

            return new MockResponse('[]', ['http_code' => $status]);
        });

        return new PlaceClient($http, new DemoPlace(sys_get_temp_dir().'/pms-demo-place-'.bin2hex(random_bytes(4)).'.json'), 'http://place.test', $token, $tokens);
    }

    private function provider(bool $available, ?string $token = 'suite-token', bool $mock = false): ServiceTokenProvider
    {
        $tokens = $mock ? $this->createMock(ServiceTokenProvider::class) : $this->createStub(ServiceTokenProvider::class);
        $tokens->method('isAvailable')->willReturn($available);
        if (null === $token) {
            $tokens->method('tokenForClient')->willThrowException(new OidcException('down'));
        } else {
            $tokens->method('tokenForClient')->willReturn($token);
        }

        return $tokens;
    }

    public function testStandaloneUsesStaticToken(): void
    {
        $this->client('rpl_static', $this->provider(false))->request('GET', '/api/places');
        self::assertSame(['Bearer rpl_static'], $this->authorizations);
    }

    public function testSuiteUsesRocketAuthTokenForPlaceAudience(): void
    {
        $tokens = $this->provider(true, 'suite-token', true);
        $tokens->expects(self::once())->method('tokenForClient')->with('rocket-place');
        $client = $this->client('', $tokens);
        self::assertFalse($client->isDemo());
        $client->request('GET', '/api/places');
        self::assertSame(['Bearer suite-token'], $this->authorizations);
    }

    public function testFallsBackToStaticTokenWhenRocketAuthFails(): void
    {
        $this->client('rpl_static', $this->provider(true, null))->request('GET', '/api/places');
        self::assertSame(['Bearer rpl_static'], $this->authorizations);
    }

    public function testNoTokenAtAllIsDemo(): void
    {
        self::assertTrue($this->client('', $this->provider(false))->isDemo());
        self::assertTrue($this->client('', null)->isDemo());
    }

    public function testRefusedSuiteTokenIsForgotten(): void
    {
        $tokens = $this->provider(true, 'suite-token', true);
        $tokens->expects(self::once())->method('forget')->with('rocket-place');
        try {
            $this->client('', $tokens, 401)->request('GET', '/api/places');
            self::fail('Expected a 502.');
        } catch (HttpException $e) {
            self::assertSame(502, $e->getStatusCode());
        }
    }
}
