<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Commercial space. "Today" is 2026-10-01 (MockClock, see config/services.yaml).
 */
final class TestDriveControllerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testCommercialSeesTodaysRegistrationsToValidate(): void
    {
        $today = $this->booking('2026-10-01', '09:00', 'Sarra Ben Ali');
        $this->booking('2026-10-02', '09:00', 'Demain Client');
        $this->loginAs(['ROLE_COMMERCIAL']);

        $crawler = $this->client->request('GET', '/admin/test-drives');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.datagrid tbody tr');
        self::assertCount(1, $rows);
        $row = $rows->first()->text();
        foreach (['Sarra Ben Ali', '+216 20 000 000', 'client@example.com', 'Territory Experience', 'Territory', '09:00', 'En attente de confirmation', 'À valider'] as $expected) {
            self::assertStringContainsString($expected, $row);
        }
        self::assertSelectorTextContains('table.datagrid', 'Test drive effectué');
        self::assertCount(1, $crawler->filter(sprintf('form[action$="/admin/test-drives/%d/validate"]', $today->getId())));
    }

    public function testValidationAdds30DtOnceEvenWhenPostedTwice(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_COMMERCIAL']);
        $crawler = $this->client->request('GET', '/admin/test-drives');
        $form = $crawler->filter('form.dp-validate-test-drive')->form();

        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $this->assertCagnotteToast($crawler, $this->openingAmount() + 30);
        self::assertCount(0, $crawler->filter('form.dp-validate-test-drive'), 'The action disappears once validated.');
        self::assertStringContainsString('Effectué', $crawler->filter('table.datagrid tbody tr')->text());

        // Same POST again (double click / resubmission).
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'déjà validé');
        self::assertCount(0, $crawler->filter('template[data-toast]'), 'No toast when nothing was added.');

        self::assertCount(1, $this->contributions());
        self::assertSame(30, $this->contributions()[0]->getAmount());
        self::assertSame('commercial@alphaford.tn', $this->reload($reservation)->getTestDriveValidatedBy());
    }

    public function testAdminCanValidateToo(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_ADMIN']);

        $this->postValidate($reservation->getId(), $this->tokenFor($reservation));

        self::assertResponseRedirects();
        self::assertCount(1, $this->contributions());
    }

    public function testAdminValidatesAnyDateFromTheReservationList(): void
    {
        $this->booking('2026-09-30', '09:00', 'Hier Client');
        $future = $this->booking('2026-10-09', '09:00', 'Futur Client');
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('form.dp-validate-test-drive'), 'Admins can validate past and future test drives.');
        $form = $crawler->filter(sprintf('form[action$="/admin/test-drives/%d/validate"]', $future->getId()));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/admin/reservation');
        $this->assertCagnotteToast($this->client->followRedirect(), $this->openingAmount() + 30);
        self::assertCount(1, $this->contributions());
        $future = $this->reload($future);
        self::assertSame(TestDriveStatus::Completed, $future->getTestDriveStatus());
        self::assertSame(ReservationStatus::Confirmed, $future->getStatus());
        self::assertStringContainsString('Confirmée', $this->client->getCrawler()->filter('table.datagrid')->text());
    }

    public function testReturnUrlOutsideTheBackOfficeIsIgnored(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_COMMERCIAL']);
        $token = $this->tokenFor($reservation);

        $this->client->request('POST', sprintf('/admin/test-drives/%d/validate', $reservation->getId()), [
            '_token' => $token,
            '_return' => 'https://evil.example/admin/',
        ]);

        self::assertResponseRedirects('/admin/test-drives');
    }

    public function testUnknownReservationReturns404(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_COMMERCIAL']);
        $token = $this->tokenFor($reservation);
        $this->entityManager()->createQuery('DELETE FROM App\Entity\Reservation')->execute();

        $this->postValidate($reservation->getId(), $token);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->contributions());
    }

    public function testAnonymousUserIsSentToTheLoginPage(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');

        $this->postValidate($reservation->getId(), 'whatever');
        self::assertResponseRedirects('http://localhost/admin/login');

        $this->client->request('GET', '/admin/test-drives');
        self::assertResponseRedirects('http://localhost/admin/login');

        self::assertCount(0, $this->contributions());
    }

    public function testUserWithoutCommercialRoleIsForbidden(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_USER']);

        $this->postValidate($reservation->getId(), 'whatever');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/test-drives');
        self::assertResponseStatusCodeSame(403);

        self::assertCount(0, $this->contributions());
    }

    public function testInvalidCsrfTokenIsRefused(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_COMMERCIAL']);

        $this->postValidate($reservation->getId(), 'forged');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Jeton de sécurité invalide');
        self::assertCount(0, $this->contributions());
        self::assertSame(TestDriveStatus::ToValidate, $this->reload($reservation)->getTestDriveStatus());
    }

    public function testValidationRequiresPost(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_COMMERCIAL']);

        $this->client->request('GET', sprintf('/admin/test-drives/%d/validate', $reservation->getId()));

        self::assertResponseStatusCodeSame(405);
        self::assertCount(0, $this->contributions());
    }

    public function testCommercialCannotValidateAFutureTestDrive(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_COMMERCIAL']);
        $token = $this->tokenFor($reservation);
        // Moved to a later day after the list was displayed.
        $this->entityManager()->createQuery('UPDATE App\Entity\Reservation r SET r.date = :later WHERE r.id = :id')
            ->setParameter('later', new \DateTimeImmutable('2026-10-02'), 'date_immutable')
            ->setParameter('id', $reservation->getId())
            ->execute();

        $this->postValidate($reservation->getId(), $token);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'date ultérieure');
        self::assertCount(0, $this->contributions());
    }

    public function testCommercialCannotOpenTheFullReservationList(): void
    {
        $this->loginAs(['ROLE_COMMERCIAL']);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/reservation');
        self::assertResponseStatusCodeSame(403);
    }

    private function booking(string $date, string $slot, string $name = 'Client Test'): Reservation
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
        $this->client->loginUser($this->createUser('commercial@alphaford.tn', $roles), 'admin');
    }

    /**
     * Reads the CSRF token from the commercial list, as the browser would.
     */
    private function tokenFor(Reservation $reservation): string
    {
        $crawler = $this->client->request('GET', '/admin/test-drives');
        $form = $crawler->filter(sprintf('form[action$="/admin/test-drives/%d/validate"]', $reservation->getId()));
        self::assertCount(1, $form, 'The validation form is listed.');

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    private function postValidate(int $reservationId, string $token): Crawler
    {
        return $this->client->request('POST', sprintf('/admin/test-drives/%d/validate', $reservationId), ['_token' => $token]);
    }

    private function reload(Reservation $reservation): Reservation
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->find(Reservation::class, $reservation->getId());
    }

    /**
     * @return list<FundContribution>
     */
    private function contributions(): array
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->getRepository(FundContribution::class)->findAll();
    }

    /**
     * Toast rendered for js/toast.js after a credit: +30 DT and the new fund total.
     */
    private function assertCagnotteToast(Crawler $crawler, int $expectedTotal): void
    {
        $toast = $crawler->filter('template[data-toast]');
        self::assertCount(1, $toast);
        self::assertSame('+30 DT', $toast->attr('data-badge'));
        self::assertStringContainsString('Test drive validé', (string) $toast->attr('data-title'));
        self::assertStringContainsString(
            sprintf('Nouveau total de la cagnotte : %s DT', number_format($expectedTotal, 0, ',', ' ')),
            $toast->html(),
        );
        self::assertCount(0, $crawler->filter('.alert-success'));
    }

    private function openingAmount(): int
    {
        return (int) static::getContainer()->getParameter('app.solidarity_fund_amount');
    }
}
