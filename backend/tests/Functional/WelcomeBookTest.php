<?php

namespace App\Tests\Functional;

use App\Mailer\DemoMailer;
use App\Place\DemoPlace;
use App\Place\PlaceClient;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Welcome book and TV screen: editing (admin only), per-booking guest link (signature, validity window, rotation),
 * the keypad code shown only once sent to the lock, the kiosk TV page, no personal data beyond the first name, rate
 * limit. Demo Lodgify + demo Rocket Place: no network call.
 */
final class WelcomeBookTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoPlace::class)->reset();
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

    public function testEditingTheBook(): void
    {
        $port = $this->port();
        $book = $this->api('GET', "/api/properties/$port/welcome-book", null, $this->user);
        $this->assertStatus(200);
        self::assertSame('', $book['content']['wifiSsid']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $book['tvToken']);

        $this->api('PUT', "/api/properties/$port/welcome-book", ['content' => ['wifiSsid' => 'Port']], $this->user);
        $this->assertStatus(403);
        $this->api('GET', "/api/properties/$port/welcome-book");
        $this->assertStatus(401);

        $saved = $this->api('PUT', "/api/properties/$port/welcome-book", ['content' => ['wifiSsid' => "  Port\x07 ", 'houseRules' => str_repeat('a', 5000), 'bogus' => 'x']], $this->admin);
        $this->assertStatus(200);
        self::assertSame('Port', $saved['content']['wifiSsid']);
        self::assertSame(4000, mb_strlen($saved['content']['houseRules']));
        self::assertArrayNotHasKey('bogus', $saved['content']);

        $rotated = $this->api('POST', "/api/properties/$port/welcome-book/rotate", ['link' => 'tv'], $this->admin);
        self::assertNotSame($book['tvToken'], $rotated['tvToken']);
        $this->api('GET', '/api/public/tv/'.$book['tvToken']);
        $this->assertStatus(404);
    }

    public function testGuestLinkIsPersonalisedAndBoundToItsStay(): void
    {
        $port = $this->port();
        $this->api('PUT', "/api/properties/$port/welcome-book", ['content' => ['welcomeText' => 'Bienvenue {{guest}} !', 'wifiPassword' => 'secret-wifi']], $this->admin);

        // Booking 3 (Sofia Rossi) arrives today
        $link = $this->api('GET', "/api/properties/$port/bookings/3/guest-link", null, $this->user);
        $this->assertStatus(200);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{54}$/', $link['token']);
        $page = $this->api('GET', '/api/public/guest/'.$link['token']);
        $this->assertStatus(200);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('Sofia', $page['guest']['firstName']);
        self::assertSame('Bienvenue Sofia !', $page['content']['welcomeText']);
        self::assertSame('secret-wifi', $page['content']['wifiPassword']);
        self::assertNull($page['access']);
        $json = json_encode($page);
        self::assertStringNotContainsString('Rossi', $json);
        self::assertStringNotContainsString('@', $json);

        // Booking of another property, or unknown: 404
        $this->api('GET', "/api/properties/$port/bookings/2/guest-link", null, $this->user);
        $this->assertStatus(404);

        // Tampered signature
        $tampered = substr($link['token'], 0, -2).('AA' === substr($link['token'], -2) ? 'BB' : 'AA');
        $this->api('GET', '/api/public/guest/'.$tampered);
        $this->assertStatus(404);

        // Booking 5 arrives in 8 days: link not open yet
        $later = $this->api('GET', "/api/properties/$port/bookings/5/guest-link", null, $this->user);
        $body = $this->api('GET', '/api/public/guest/'.$later['token']);
        $this->assertStatus(404);
        self::assertStringContainsString('deux jours', (string) $body['detail']);

        // Booking 1 left today: still open until tomorrow
        $past = $this->api('GET', "/api/properties/$port/bookings/1/guest-link", null, $this->user);
        $this->api('GET', '/api/public/guest/'.$past['token']);
        $this->assertStatus(200);

        // Rotating the guest salt revokes every guest link
        $this->api('POST', "/api/properties/$port/welcome-book/rotate", ['link' => 'guest'], $this->admin);
        $this->api('GET', '/api/public/guest/'.$link['token']);
        $this->assertStatus(404);
    }

    public function testKeypadCodeOnlyOnceSentToTheLock(): void
    {
        $port = $this->port();
        $this->api('PUT', "/api/properties/$port/place", ['placeId' => DemoPlace::PORT], $this->admin);
        $grant = static::getContainer()->get(PlaceClient::class)->request('POST', '/api/places/'.DemoPlace::PORT.'/access-grants', [
            'lockId' => 90001, 'label' => 'test', 'validFrom' => (new \DateTimeImmutable('today'))->format(\DATE_ATOM),
            'validUntil' => (new \DateTimeImmutable('+3 days'))->format(\DATE_ATOM), 'externalRef' => '3',
        ]);
        $token = $this->api('GET', "/api/properties/$port/bookings/3/guest-link", null, $this->user)['token'];

        // Planned, not sent: never shown
        self::assertNull($this->api('GET', '/api/public/guest/'.$token)['access']);

        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/demo-place-test.json';
        $state = json_decode((string) file_get_contents($file), true);
        $state['grants'][$grant['id']]['status'] = 'created';
        file_put_contents($file, json_encode($state));

        $access = $this->api('GET', '/api/public/guest/'.$token)['access'];
        self::assertSame($grant['code'], $access['code']);

        // The TV screen never shows it
        $tv = $this->api('GET', '/api/public/tv/'.$this->api('GET', "/api/properties/$port/welcome-book", null, $this->user)['tvToken']);
        self::assertStringNotContainsString($grant['code'], json_encode($tv));
    }

    public function testTvScreen(): void
    {
        $port = $this->port();
        $book = $this->api('PUT', "/api/properties/$port/welcome-book", ['content' => ['welcomeText' => 'Bonjour {{guest}}', 'accessDirections' => 'Boîte à clés derrière le pot', 'checkoutInfo' => 'Avant 11 h']], $this->admin);
        $tv = $this->api('GET', '/api/public/tv/'.$book['tvToken']);
        $this->assertStatus(200);
        self::assertSame('Le port', $tv['property']);
        self::assertSame('Sofia', $tv['guest']['firstName']);
        self::assertSame('Bonjour Sofia', $tv['content']['welcomeText']);
        self::assertSame('Avant 11 h', $tv['content']['checkoutInfo']);
        self::assertArrayNotHasKey('accessDirections', $tv['content']);
        self::assertNotNull($tv['nextArrival']);
        self::assertStringNotContainsString('Rossi', json_encode($tv));

        $this->api('GET', '/api/public/tv/'.str_repeat('a', 43));
        $this->assertStatus(404);
    }

    public function testTranslationsAndLanguageChoice(): void
    {
        $port = $this->port();
        $book = $this->api('PUT', "/api/properties/$port/welcome-book", [
            'content' => ['welcomeText' => 'Bienvenue {{guest}}', 'checkoutInfo' => 'Avant 11 h'],
            'translations' => ['en' => ['welcomeText' => 'Welcome {{guest}}'], 'xx' => ['welcomeText' => 'nope'], 'de' => ['bogus' => 'x']],
        ], $this->admin);
        $this->assertStatus(200);
        self::assertSame(['welcomeText' => 'Welcome {{guest}}'], $book['translations']['en']);
        self::assertArrayNotHasKey('xx', $book['translations']);
        self::assertSame([], $book['translations']['de']);

        $token = $this->api('GET', "/api/properties/$port/bookings/3/guest-link", null, $this->user)['token'];
        $fr = $this->api('GET', '/api/public/guest/'.$token, null, null, ['Accept-Language' => 'pt-BR,pt;q=0.9']);
        self::assertSame('fr', $fr['lang']);
        self::assertSame(['fr', 'en'], $fr['languages']);
        self::assertSame('Bienvenue Sofia', $fr['content']['welcomeText']);

        $en = $this->api('GET', '/api/public/guest/'.$token, null, null, ['Accept-Language' => 'en-GB,en;q=0.9']);
        self::assertSame('en', $en['lang']);
        self::assertSame('Welcome Sofia', $en['content']['welcomeText']);
        self::assertSame('Avant 11 h', $en['content']['checkoutInfo'], 'untranslated section falls back to French');

        $forced = $this->api('GET', '/api/public/guest/'.$token.'?lang=fr', null, null, ['Accept-Language' => 'en']);
        self::assertSame('fr', $forced['lang']);
        $unknown = $this->api('GET', '/api/public/guest/'.$token.'?lang=it', null, null, ['Accept-Language' => 'es']);
        self::assertSame('fr', $unknown['lang'], 'Italian not translated: French');

        $tv = $this->api('GET', '/api/public/tv/'.$book['tvToken'].'?lang=en');
        self::assertSame('Welcome Sofia', $tv['content']['welcomeText']);
    }

    public function testStyleIsValidated(): void
    {
        $port = $this->port();
        $book = $this->api('GET', "/api/properties/$port/welcome-book", null, $this->user);
        self::assertSame(['accent' => '#0f766e', 'coverUrl' => null, 'coverDocumentRef' => null, 'layout' => 'tabs'], $book['style']);

        foreach ([['accent' => 'red'], ['coverUrl' => 'http://example.org/a.jpg'], ['coverUrl' => 'javascript:alert(1)'], ['layout' => 'grid'], ['coverDocumentRef' => '../etc']] as $bad) {
            $this->api('PUT', "/api/properties/$port/welcome-book", ['style' => $bad], $this->admin);
            $this->assertStatus(422);
        }
        // A document cover needs the property's place
        $this->api('PUT', "/api/properties/$port/welcome-book", ['style' => ['coverDocumentRef' => 'file:demo-welcome-0001']], $this->admin);
        $this->assertStatus(409);

        $saved = $this->api('PUT', "/api/properties/$port/welcome-book", ['style' => ['accent' => '#AA3300', 'coverUrl' => 'https://images.example.org/cover.jpg', 'layout' => 'columns']], $this->admin);
        $this->assertStatus(200);
        self::assertSame(['accent' => '#aa3300', 'coverUrl' => 'https://images.example.org/cover.jpg', 'coverDocumentRef' => null, 'layout' => 'columns'], $saved['style']);
        $tv = $this->api('GET', '/api/public/tv/'.$saved['tvToken']);
        self::assertSame(['accent' => '#aa3300', 'layout' => 'columns', 'coverUrl' => 'https://images.example.org/cover.jpg', 'documentCover' => false, 'coverPath' => null], $tv['style']);

        // Document of the place as cover: served only when it is an image (the demo document is text: 404)
        $this->api('PUT', "/api/properties/$port/place", ['placeId' => DemoPlace::PORT], $this->admin);
        $this->api('GET', "/api/properties/$port/documents", null, $this->user);
        $saved = $this->api('PUT', "/api/properties/$port/welcome-book", ['style' => ['coverDocumentRef' => 'file:demo-welcome-0001']], $this->admin);
        $this->assertStatus(200);
        self::assertNull($saved['style']['coverUrl']);
        $tv = $this->api('GET', '/api/public/tv/'.$saved['tvToken']);
        self::assertSame('/api/public/tv/'.$saved['tvToken'].'/cover', $tv['style']['coverPath']);
        $this->client->request('GET', $tv['style']['coverPath']);
        $this->assertStatus(404);
    }

    public function testVisitStatisticsWithoutVisitorData(): void
    {
        $port = $this->port();
        $book = $this->api('GET', "/api/properties/$port/welcome-book", null, $this->user);
        $token = $this->api('GET', "/api/properties/$port/bookings/3/guest-link", null, $this->user)['token'];
        $this->api('GET', '/api/public/guest/'.$token);
        $this->api('GET', '/api/public/guest/'.$token);
        $this->api('GET', '/api/public/tv/'.$book['tvToken']);

        $stats = $this->api('GET', "/api/properties/$port/welcome-book/stats", null, $this->user);
        $this->assertStatus(200);
        self::assertSame(3, $stats['total']);
        $links = array_column($stats['links'], null, 'link');
        self::assertSame(2, $links['guest']['total']);
        self::assertSame(3, $links['guest']['bookingId']);
        self::assertSame('Sofia Rossi', $links['guest']['guest']);
        self::assertSame(1, $links['tv']['total']);
        self::assertCount(2, $stats['daily']);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM welcome_book_visit LIMIT 1');
        self::assertSame(['id', 'link', 'booking_id', 'day', 'count', 'book_id'], array_keys($row), 'no IP nor user agent column');
    }

    public function testTvReloadsBeforeTheNextArrival(): void
    {
        $port = $this->port();
        $book = $this->api('GET', "/api/properties/$port/welcome-book", null, $this->user);
        $tv = $this->api('GET', '/api/public/tv/'.$book['tvToken']);
        // Next check-in of Le port: booking 3 (Sofia) today at 15:00 if still to come, else booking 5 (Anna) in 8 days
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $arrival = $today->modify('15:00') > new \DateTimeImmutable() ? $today->modify('15:00') : $today->modify('+8 days 15:00');
        self::assertEquals($arrival, new \DateTimeImmutable($tv['nextArrivalAt']));
        self::assertEquals($arrival->modify('-30 minutes'), new \DateTimeImmutable($tv['reloadAt']));
    }

    public function testSendingTheGuestLinkOnlyOnExplicitRequest(): void
    {
        static::getContainer()->get(DemoMailer::class)->reset();
        $this->api('POST', '/api/properties/sync', [], $this->admin);
        $vignes = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')['Les vignes'];
        $link = $this->api('GET', "/api/properties/$vignes/bookings/4/guest-link", null, $this->user);
        self::assertStringStartsWith('http://localhost:3700/g/', $link['url']);
        self::assertStringContainsString('Bonjour Paul', $link['message']);
        self::assertSame([], static::getContainer()->get(DemoMailer::class)->sent(), 'getting the link sends nothing');

        $this->api('POST', "/api/properties/$vignes/bookings/4/guest-link/send", ['channel' => 'email', 'messageId' => 'nope'], $this->user);
        $this->assertStatus(422);
        $this->api('POST', "/api/properties/$vignes/bookings/4/guest-link/send", ['channel' => 'sms', 'messageId' => '0192f7c4-0000-7000-8000-00000000abcd'], $this->user);
        $this->assertStatus(422);
        $this->api('POST', "/api/properties/$vignes/bookings/4/guest-link/send", ['channel' => 'email'], null);
        $this->assertStatus(401);

        $body = ['channel' => 'email', 'messageId' => '0192f7c4-0000-7000-8000-00000000abcd', 'lang' => 'en'];
        $sent = $this->api('POST', "/api/properties/$vignes/bookings/4/guest-link/send", $body, $this->user);
        $this->assertStatus(200);
        self::assertFalse($sent['duplicate']);
        self::assertTrue($this->api('POST', "/api/properties/$vignes/bookings/4/guest-link/send", $body, $this->user)['duplicate'], 'a double click sends once');
        $mails = static::getContainer()->get(DemoMailer::class)->sent();
        self::assertCount(1, $mails);
        self::assertSame(['paul.demo@example.org'], $mails[0]['to']);
        self::assertStringStartsWith('Your welcome book', $mails[0]['subject']);

        // Lodgify in demo mode refuses to send (never a silent success); no e-mail for a guest without address
        $port = array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')['Le port'];
        $this->api('POST', "/api/properties/$port/bookings/3/guest-link/send", ['channel' => 'lodgify', 'messageId' => '0192f7c4-0000-7000-8000-00000000abce'], $this->user);
        $this->assertStatus(400);
        $this->api('POST', "/api/properties/$port/bookings/3/guest-link/send", ['channel' => 'email', 'messageId' => '0192f7c4-0000-7000-8000-00000000abcf'], $this->user);
        $this->assertStatus(422);
    }

    public function testInvalidTokensAreRateLimited(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->api('GET', '/api/public/tv/'.str_repeat('b', 43));
            $this->assertStatus(404);
        }
        $this->api('GET', '/api/public/tv/'.str_repeat('b', 43));
        $this->assertStatus(429);
    }

    private function port(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);

        return array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')['Le port'];
    }
}
