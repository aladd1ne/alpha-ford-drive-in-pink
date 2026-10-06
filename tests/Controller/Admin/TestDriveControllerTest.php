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
 * Cashier space ("Encaissement"). "Today" is 2026-10-01 (MockClock, see config/services.yaml).
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
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/test-drives');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.datagrid tbody tr');
        self::assertCount(1, $rows);
        $row = $rows->first()->text();
        foreach (['Sarra Ben Ali', '+216 20 000 000', 'client@example.com', 'Territory Experience', 'Territory', '09:00', 'En attente de confirmation', 'À valider'] as $expected) {
            self::assertStringContainsString($expected, $row);
        }
        self::assertSelectorTextContains('table.datagrid', 'Encaisser');
        self::assertSame('30', $crawler->filter('form.dp-validate-test-drive input[name="amount"]')->attr('value'), '10 DT client + 20 DT Alpha Ford.');
        self::assertCount(1, $crawler->filter(sprintf('form[action$="/admin/test-drives/%d/validate"]', $today->getId())));
    }

    public function testValidationAdds30DtOnceEvenWhenPostedTwice(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_CASHIER']);
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
        self::assertSelectorTextContains('.alert-info', 'déjà encaissé');
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

    public function testCashierSeesAnEventDayFromItsTabWithoutCashingItInAdvance(): void
    {
        $this->booking('2026-10-09', '09:00', 'Futur Client');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/test-drives');
        $tabs = $crawler->filter('.dp-day-tabs a')->each(static fn (Crawler $tab): string => trim($tab->text()));
        self::assertSame(['01/10 aujourd’hui', '09/10', '10/10', '23/10', '24/10'], $tabs);

        $crawler = $this->client->click($crawler->selectLink('09/10')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Encaissement du 09/10/2026');
        self::assertSelectorTextContains('table.datagrid', 'Futur Client');
        self::assertCount(1, $crawler->filter('form.dp-validate-test-drive'), 'Cashiers may cash in before the day.');
    }

    public function testAdminCashesInAFutureDayFromItsTab(): void
    {
        $future = $this->booking('2026-10-09', '09:00', 'Futur Client');
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin/test-drives?day=2026-10-09');
        $this->client->submit($crawler->filter('form.dp-validate-test-drive')->form(['amount' => '35']));

        self::assertResponseRedirects('/admin/test-drives?day=2026-10-09');
        $toast = $this->client->followRedirect()->filter('template[data-toast]');
        self::assertSame('+35 DT', $toast->attr('data-badge'));
        $contribution = $this->contributions()[0];
        self::assertSame(15, $contribution->getClientAmount());
        self::assertSame(20, $contribution->getAlphaFordAmount());
        self::assertSame(35, $contribution->getAmount());
        $future = $this->reload($future);
        self::assertSame(TestDriveStatus::Completed, $future->getTestDriveStatus());
        self::assertSame(ReservationStatus::Confirmed, $future->getStatus());
    }

    public function testCashInRequiresAValidAmount(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_CASHIER']);
        $token = $this->tokenFor($reservation);

        foreach (['', '-5', 'abc', '19', '10001'] as $amount) {
            $this->client->request('POST', sprintf('/admin/test-drives/%d/validate', $reservation->getId()), ['_token' => $token, 'amount' => $amount]);
            self::assertResponseRedirects('/admin/test-drives');
            $this->client->followRedirect();
            self::assertSelectorTextContains('.alert-danger', 'montant reçu');
        }

        self::assertCount(0, $this->contributions());
    }

    public function testReservationsRoleCannotCashIn(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_RESERVATIONS']);

        $this->client->request('GET', '/admin/test-drives');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/admin/test-drives/%d/validate', $reservation->getId()), ['_token' => 'x', 'amount' => '30']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->contributions());
    }

    public function testAdminSeesTheReservationDetail(): void
    {
        $reservation = $this->booking('2026-10-09', '09:00', 'Sarra Ben Ali');
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', sprintf('/admin/reservation/%d', $reservation->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.dp-detail__name', 'Sarra Ben Ali');
        self::assertSelectorTextContains('.dp-detail__avatar', 'SB');
        self::assertCount(1, $crawler->filter('a[href="tel:+21620000000"]'));
        self::assertCount(1, $crawler->filter('a[href="mailto:client@example.com"]'));
        $text = $crawler->filter('.dp-detail')->text();
        foreach (['Territory Experience', 'vendredi 9 octobre 2026', '09:00', 'En attente de confirmation', 'À valider'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }

        // Admins may cash in any day: "Valider" takes the amount received.
        self::assertCount(1, $crawler->filter('form.dp-row-action--confirmAndCashIn input[name="amount"]'));
    }

    public function testReturnUrlOutsideTheBackOfficeIsIgnored(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_CASHIER']);
        $token = $this->tokenFor($reservation);

        $this->client->request('POST', sprintf('/admin/test-drives/%d/validate', $reservation->getId()), [
            '_token' => $token,
            '_return' => 'https://evil.example/admin/',
            'amount' => '30',
        ]);

        self::assertResponseRedirects('/admin/test-drives');
    }

    public function testUnknownReservationReturns404(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_CASHIER']);
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
        $this->loginAs(['ROLE_CASHIER']);

        $this->postValidate($reservation->getId(), 'forged');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Jeton de sécurité invalide');
        self::assertCount(0, $this->contributions());
        self::assertSame(TestDriveStatus::ToValidate, $this->reload($reservation)->getTestDriveStatus());
    }

    public function testValidationRequiresPost(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $this->client->request('GET', sprintf('/admin/test-drives/%d/validate', $reservation->getId()));

        self::assertResponseStatusCodeSame(405);
        self::assertCount(0, $this->contributions());
    }

    public function testCashierValidatesATestDriveMovedToALaterDay(): void
    {
        $reservation = $this->booking('2026-10-01', '09:00');
        $this->loginAs(['ROLE_CASHIER']);
        $token = $this->tokenFor($reservation);
        // Moved to a later day after the list was displayed.
        $this->entityManager()->createQuery('UPDATE App\Entity\Reservation r SET r.date = :later WHERE r.id = :id')
            ->setParameter('later', new \DateTimeImmutable('2026-10-02'), 'date_immutable')
            ->setParameter('id', $reservation->getId())
            ->execute();

        $this->postValidate($reservation->getId(), $token);
        $this->client->followRedirect();

        self::assertSelectorNotExists('.alert-danger');
        self::assertCount(1, $this->contributions());
    }

    public function testCashierCannotAddAWalkInTestDrive(): void
    {
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/test-drives');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ajouter un test drive', $crawler->filter('body')->text());

        $this->client->request('GET', '/admin/test-drives/ajouter');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminAddsAWalkInTestDriveAlreadyDriven(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $this->client->request('GET', '/admin/test-drives/ajouter');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Ajouter', [
            'manual_test_drive[fullName]' => 'Walk In Client',
            'manual_test_drive[phone]' => '+216 22 333 444',
            'manual_test_drive[email]' => 'walkin@example.com',
            'manual_test_drive[experience]' => Experience::EverestRanger->value,
            'manual_test_drive[vehicle]' => Vehicle::RangerRaptor->value,
            'manual_test_drive[completed]' => true,
            'manual_test_drive[amount]' => '10',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $this->assertCagnotteToast($crawler, $this->openingAmount() + 30);
        self::assertSelectorTextContains('table.datagrid', 'Walk In Client');

        $reservation = $this->entityManager()->getRepository(Reservation::class)->findOneBy(['email' => 'walkin@example.com']);
        self::assertSame('2026-10-01', $reservation->getDate()->format('Y-m-d'));
        self::assertNull($reservation->getSlot());
        self::assertSame('commercial@alphaford.tn', $reservation->getCreatedBy());
        self::assertTrue($reservation->isTestDriveCompleted());
        self::assertCount(1, $this->contributions());
    }

    public function testWalkInLeftToValidateCreditsNothingYet(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/admin/test-drives/ajouter');
        $form = $crawler->selectButton('Ajouter')->form([
            'manual_test_drive[fullName]' => 'Walk In Client',
            'manual_test_drive[phone]' => '+216 22 333 444',
            'manual_test_drive[email]' => 'walkin@example.com',
            'manual_test_drive[experience]' => Experience::Territory->value,
            'manual_test_drive[vehicle]' => Vehicle::Territory->value,
        ]);
        $form['manual_test_drive[completed]']->untick();

        $this->client->submit($form);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $this->contributions());
        self::assertCount(1, $crawler->filter('form.dp-validate-test-drive'), 'It can be validated from the list.');
    }

    public function testWalkInVehicleMustMatchTheExperience(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', '/admin/test-drives/ajouter');

        $this->client->submitForm('Ajouter', [
            'manual_test_drive[fullName]' => 'Walk In Client',
            'manual_test_drive[phone]' => '+216 22 333 444',
            'manual_test_drive[email]' => 'walkin@example.com',
            'manual_test_drive[experience]' => Experience::Territory->value,
            'manual_test_drive[vehicle]' => Vehicle::RangerRaptor->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(Reservation::class)->count([]));
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
        return $this->client->request('POST', sprintf('/admin/test-drives/%d/validate', $reservationId), ['_token' => $token, 'amount' => '30']);
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
