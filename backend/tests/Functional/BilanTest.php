<?php

namespace App\Tests\Functional;

use App\Place\DemoPlace;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    public function testImportOfPlatformStatements(): void
    {
        $port = $this->port();
        $csv = "externalId;date;kind;amount;label;bookingRef\n"
            ."A1;2026-03-02;fee;-18,50;Commission Airbnb;HM123\n"
            ."A2;05/03/2026;tourist_tax;7.20;Taxe de séjour;HM123\n"
            ."A3;2026-03-06;payout;300;Versement;HM123\n"
            ."A4;2026-13-01;fee;3;Date fausse;\n"
            ."A5;2026-03-07;bogus;3;Type inconnu;\n"
            .";2026-03-07;fee;3;Sans id;\n";

        $this->import($port, 'airbnb', $csv, $this->user);
        $this->assertStatus(403);
        $result = $this->import($port, 'airbnb', $csv, $this->admin);
        $this->assertStatus(200);
        self::assertSame(2, $result['inserted']);
        self::assertSame(1, $result['skipped'], 'payouts are already counted from Lodgify');
        self::assertSame([5, 6, 7], array_column($result['invalid'], 'line'));

        $again = $this->import($port, 'airbnb', $csv, $this->admin);
        self::assertSame(0, $again['inserted']);
        self::assertSame(2, $again['duplicates'], 'same statement twice: nothing added');
        self::assertSame(1, $again['skipped']);

        $items = $this->api('GET', "/api/properties/$port/expenses?year=2026", null, $this->user)['items'];
        $byId = array_column($items, null, 'externalId');
        self::assertSame(18.5, $byId['A1']['amount']);
        self::assertSame('frais_plateformes', $byId['A1']['category']);
        self::assertSame('taxe_sejour', $byId['A2']['category']);
        self::assertSame('2026-03-05', $byId['A2']['date']);
        self::assertSame('airbnb', $byId['A2']['source']);
        self::assertStringContainsString('HM123', $byId['A1']['note']);

        // Another source may reuse the same ids; bad source and missing column are refused
        self::assertSame(2, $this->import($port, 'booking', $csv, $this->admin)['inserted']);
        $this->import($port, 'Airbnb!', $csv, $this->admin);
        $this->assertStatus(422);
        $this->import($port, 'airbnb', "id;date;amount\n1;2026-01-01;3\n", $this->admin);
        $this->assertStatus(422);
    }

    /** @return array<mixed>|null */
    private function import(string $property, string $source, string $csv, string $authorization): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);
        $this->client->request('POST', "/api/properties/$property/expenses/import", ['source' => $source], ['file' => new UploadedFile($path, 'releve.csv', 'text/csv', null, true)], ['HTTP_AUTHORIZATION' => $authorization, 'HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function port(): string
    {
        $this->api('POST', '/api/properties/sync', [], $this->admin);

        return array_column($this->api('GET', '/api/properties', null, $this->admin), 'id', 'name')['Le port'];
    }
}
