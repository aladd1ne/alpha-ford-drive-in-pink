<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Service\TestDrive\TestDriveValidator;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Public cagnotte page and live state endpoint. "Today" is 2026-10-01 (MockClock).
 * SOLIDARITY_FUND_AMOUNT is 0, so the fund only holds validated test drives.
 */
final class FundControllerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testEmptyFund(): void
    {
        $crawler = $this->client->request('GET', '/cagnotte');

        self::assertResponseIsSuccessful();
        self::assertSame('0DT', preg_replace('/\s+/', '', $crawler->filter('.dp-cagnotte__value')->text()));
        self::assertSelectorTextContains('[data-fund="testDrives"]', '0');
        self::assertSame(['total' => 0, 'testDrives' => 0, 'clientAmount' => 0, 'alphaFordAmount' => 0, 'otherAmount' => 0], $this->state());
    }

    public function testValidatedTestDrivesShowOnThePageTheEndpointAndTheHomePage(): void
    {
        $this->validate($this->booking('09:00'));
        $this->validate($this->booking('09:45'));

        $crawler = $this->client->request('GET', '/cagnotte');
        self::assertResponseIsSuccessful();
        self::assertSame('60DT', preg_replace('/\s+/', '', $crawler->filter('.dp-cagnotte__value')->text()));
        self::assertSelectorTextContains('.dp-cagnotte__stat--main', '2 test drives validés');
        self::assertSelectorTextContains('[data-fund="clientAmount"]', '20');
        self::assertSelectorTextContains('[data-fund="alphaFordAmount"]', '40');
        self::assertSame('/cagnotte/etat', $crawler->filter('[data-fund-live]')->attr('data-fund-live'));

        self::assertSame(['total' => 60, 'testDrives' => 2, 'clientAmount' => 20, 'alphaFordAmount' => 40, 'otherAmount' => 0], $this->state());

        $crawler = $this->client->request('GET', '/');
        self::assertSame('60', $crawler->filter('.dp-fund [data-fund="total"]')->attr('data-count'));
        self::assertSelectorTextContains('.dp-fund__meta', '2 test drives validés');
        self::assertCount(1, $crawler->filter('.dp-fund a[href="/cagnotte"]'));
        self::assertSame($this->state(), json_decode((string) $crawler->filter('.dp-fund')->attr('data-fund-state'), true), 'Home page and endpoint agree.');
    }

    public function testValidatingTwiceAddsOnly30Dt(): void
    {
        $reservation = $this->booking('09:00');
        $this->validate($reservation);
        $this->validate($reservation);

        self::assertSame(30, $this->state()['total']);
        self::assertSame(1, $this->state()['testDrives']);
    }

    public function testUnchangedStateAnswers304UntilATestDriveIsValidated(): void
    {
        $this->client->request('GET', '/cagnotte/etat');
        $etag = (string) $this->client->getResponse()->getEtag();
        self::assertNotSame('', $etag);
        self::assertStringContainsString('no-cache', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', '/cagnotte/etat', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);

        $this->validate($this->booking('09:00'));

        $this->client->request('GET', '/cagnotte/etat', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseIsSuccessful();
        self::assertSame(30, $this->state(false)['total']);
    }

    public function testStateIsReadOnly(): void
    {
        $this->client->request('POST', '/cagnotte/etat');

        self::assertResponseStatusCodeSame(405);
    }

    private function booking(string $slot): Reservation
    {
        return $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, $slot);
    }

    private function validate(Reservation $reservation): void
    {
        static::getContainer()->get(TestDriveValidator::class)->validate((int) $reservation->getId(), 'commercial@alphaford.tn');
    }

    /**
     * @return array<string, int>
     */
    private function state(bool $request = true): array
    {
        if ($request) {
            $this->client->request('GET', '/cagnotte/etat');
        }
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
