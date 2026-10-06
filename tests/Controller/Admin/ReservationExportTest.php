<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Exporter Excel" on the admin reservation list and on the commercial test drive list.
 * "Today" is 2026-10-01 (MockClock, see config/services.yaml).
 */
final class ReservationExportTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testAdminExportsAllReservations(): void
    {
        $this->booking('2026-10-01', '09:00', 'Sarra Ben Ali');
        $this->booking('2026-10-02', '10:00', 'Demain Client');
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $button = $crawler->filter('a.btn-success:contains("Exporter Excel")');
        self::assertCount(1, $button);

        $rows = $this->download((string) $button->attr('href'), 'reservations-');

        self::assertSame(['Expérience', 'Date', 'Créneau', 'Véhicule', 'Nom et prénom', 'Téléphone', 'E-mail', 'Statut', 'Test drive', 'Test drive validé le', 'Validé par', 'Reçue le'], $rows[0]);
        self::assertCount(3, $rows);
        self::assertSame(['Sarra Ben Ali', 'Demain Client'], [$rows[1][4], $rows[2][4]]);
        self::assertSame('+216 20 000 000', $rows[1][5]);
        self::assertSame('01/10/2026', $rows[1][1]);
    }

    public function testAdminExportKeepsTheSearch(): void
    {
        $this->booking('2026-10-01', '09:00', 'Sarra Ben Ali');
        $this->booking('2026-10-02', '10:00', 'Demain Client');
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin/reservation?query=Sarra');
        $href = (string) $crawler->filter('a.btn-success:contains("Exporter Excel")')->attr('href');

        $rows = $this->download($href, 'reservations-');
        self::assertCount(2, $rows);
        self::assertSame('Sarra Ben Ali', $rows[1][4]);
    }

    public function testCommercialExportsTodaysTestDrives(): void
    {
        $this->booking('2026-10-01', '09:00', 'Sarra Ben Ali');
        $this->booking('2026-10-02', '10:00', 'Demain Client');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/test-drives');
        $button = $crawler->filter('a.btn-success:contains("Exporter Excel")');
        self::assertCount(1, $button);

        $rows = $this->download((string) $button->attr('href'), 'encaissement-2026-10-01-');
        self::assertCount(2, $rows);
        self::assertSame('Sarra Ben Ali', $rows[1][4]);
    }

    public function testCashierCanExportTheFullReservationList(): void
    {
        $this->loginAs(['ROLE_CASHIER']);

        $this->client->request('GET', '/admin/reservation/export');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<list<mixed>>
     */
    private function download(string $url, string $filenamePrefix): array
    {
        $this->client->request('GET', $url);
        // The browser captures the streamed body.
        $content = $this->client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('attachment; filename=' . $filenamePrefix, (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $file = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($file, $content);
        try {
            return IOFactory::load($file)->getActiveSheet()->toArray();
        } finally {
            unlink($file);
        }
    }

    private function booking(string $date, string $slot, string $name): Reservation
    {
        $reservation = $this->createBooking(Experience::Territory, $date, Vehicle::Territory, $slot)->setFullName($name);
        $this->entityManager()->flush();

        return $reservation;
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $this->client->loginUser($this->createUser('user@alphaford.tn', $roles), 'admin');
    }
}
