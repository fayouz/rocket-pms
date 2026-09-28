<?php

namespace App\Tests\Functional;

use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Bilan of a property: Lodgify revenue night by night (demo Lodgify) + accounting entries (CRUD, admin only), CSV export. No network. */
final class BilanTest extends WebTestCase
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

    public function testRevenueAndEntries(): void
    {
        $port = $this->port();
        $year = (int) (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y');

        $bilan = $this->api('GET', "/api/properties/$port/bilan?year=$year", null, $this->user);
        $this->assertStatus(200);
        self::assertSame($year, $bilan['year']);
        self::assertCount(12, $bilan['months']);
        self::assertEqualsWithDelta($bilan['revenue'], array_sum(array_column($bilan['months'], 'revenue')), 0.02);
        if ((int) date('n') < 12) { // demo stays of "Le port": 3 + 3 + 4 nights around today, 210 + 205 + 420 €
            self::assertSame(10, $bilan['nights']);
            self::assertSame(3, $bilan['stays']);
            self::assertEqualsWithDelta(835.0, $bilan['revenue'], 0.01);
        }
        self::assertSame(0.0, (float) $bilan['chargesTotal']);

        // entries: admin only
        $this->api('POST', "/api/properties/$port/expenses", ['date' => "$year-03-10", 'amount' => 120, 'category' => 'menage'], $this->user);
        $this->assertStatus(403);
        $a = $this->api('POST', "/api/properties/$port/expenses", ['date' => "$year-03-10", 'amount' => '120,50', 'category' => 'menage', 'note' => 'Ménage mars', 'documentRef' => 'file:abc-123'], $this->admin);
        $this->assertStatus(201);
        self::assertSame(120.5, $a['amount']);
        self::assertSame('Ménage et blanchisserie', $a['categoryLabel']);
        $this->api('POST', "/api/properties/$port/expenses", ['date' => "$year-03-20", 'amount' => 80, 'category' => 'menage'], $this->admin);
        $this->api('POST', "/api/properties/$port/expenses", ['date' => "$year-05-02", 'amount' => 50, 'category' => 'autre_recette', 'note' => '=cmd'], $this->admin);
        $this->api('POST', "/api/properties/$port/expenses", ['date' => ($year - 1).'-12-31', 'amount' => 999, 'category' => 'travaux'], $this->admin);
        foreach ([['amount' => -5], ['category' => 'nope'], ['date' => "$year-02-30"]] as $bad) {
            $this->api('POST', "/api/properties/$port/expenses", $bad + ['date' => "$year-01-01", 'amount' => 10, 'category' => 'menage'], $this->admin);
            $this->assertStatus(422);
        }

        $bilan = $this->api('GET', "/api/properties/$port/bilan?year=$year", null, $this->user);
        self::assertSame(200.5, (float) $bilan['chargesTotal']);
        self::assertSame(50.0, (float) $bilan['otherIncome']);
        self::assertSame(3, $bilan['entries']);
        self::assertSame(200.5, (float) $bilan['months'][2]['charges']);
        self::assertSame(['menage', 'autre_recette'], array_column($bilan['categories'], 'key'));
        self::assertSame(2, $bilan['categories'][0]['count']);
        self::assertEqualsWithDelta($bilan['revenue'] + 50 - 200.5, $bilan['result'], 0.01);
        self::assertContains($year - 1, $bilan['years']);

        $updated = $this->api('PATCH', "/api/expenses/{$a['id']}", ['amount' => 100], $this->admin);
        $this->assertStatus(200);
        self::assertSame(100, (int) $updated['amount']);
        $this->api('DELETE', "/api/expenses/{$a['id']}", null, $this->user);
        $this->assertStatus(403);
        $this->api('DELETE', "/api/expenses/{$a['id']}", null, $this->admin);
        $this->assertStatus(204);
        self::assertCount(2, $this->api('GET', "/api/properties/$port/expenses?year=$year", null, $this->user)['items']);

        $this->client->request('GET', "/api/properties/$port/bilan.csv?year=$year", server: ['HTTP_AUTHORIZATION' => $this->user]);
        $this->assertStatus(200);
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}Bilan;", $csv);
        self::assertStringContainsString('Mars;', $csv);
        self::assertStringContainsString("'=cmd", $csv, 'formula injection neutralised');
        self::assertStringContainsString('bilan-le-port-'.$year.'.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    private function port(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);

        return array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')['Le port'];
    }
}
