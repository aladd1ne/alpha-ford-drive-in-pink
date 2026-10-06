<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\Vehicle;
use App\Service\SolidarityFund;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reservations team (ROLE_RESERVATIONS): confirm, edit, archive, October requests.
 * The cashier (ROLE_CASHIER) has the same pages but only views and validates (cashing the test drive in).
 * "Today" is 2026-10-01 (MockClock, see config/services.yaml).
 */
final class ReservationManagementTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testConfirmsAPendingReservation(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_RESERVATIONS']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.dp-row-action--confirmReservation')->form());

        self::assertResponseRedirects('/admin/reservation');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'validée');
        self::assertSame(ReservationStatus::Confirmed, $this->reload($reservation)->getStatus());
        self::assertCount(0, $crawler->filter('form.dp-row-action--confirmReservation'));
    }

    public function testArchivingHidesTheReservationAndFreesItsSlot(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_RESERVATIONS']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-row-action--archiveReservation')->form());
        $crawler = $this->client->followRedirect();

        self::assertCount(0, $crawler->filter('table.datagrid tbody tr td.field-text'), 'Hidden from the list.');
        $archived = $this->reload($reservation);
        self::assertSame(ReservationStatus::Archived, $archived->getStatus());
        self::assertNull($archived->getSeat());

        // Shown again with the status filter.
        $this->client->request('GET', '/admin/reservation?filters[status][comparison]==&filters[status][value]=archived');
        self::assertSelectorTextContains('table.datagrid', 'Client Test');

        // The slot can be booked again.
        $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        self::assertSame(2, $this->entityManager()->getRepository(Reservation::class)->count([]));
    }

    public function testEditMovesASlotBookingToAFreeSlot(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->createBooking(Experience::EverestRanger, '2026-10-10', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_RESERVATIONS']);

        // Taken by the other booking.
        $this->submitEdit($reservation, ['date' => '2026-10-10', 'slot' => '09:00', 'vehicle' => Vehicle::RangerRaptor->value]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="reservation_edit"]', 'complet');

        $this->submitEdit($reservation, ['fullName' => 'Nouveau Nom', 'date' => '2026-10-10', 'slot' => '09:45', 'vehicle' => Vehicle::EverestXlt->value]);

        self::assertResponseRedirects(sprintf('/admin/reservation/%d', $reservation->getId()));
        $moved = $this->reload($reservation);
        self::assertSame('Nouveau Nom', $moved->getFullName());
        self::assertSame('2026-10-10', $moved->getDate()->format('Y-m-d'));
        self::assertSame('09:45', $moved->getSlot());
        self::assertSame(Vehicle::EverestXlt, $moved->getVehicle());
        self::assertSame(1, $moved->getSeat());
    }

    public function testOctoberRequestGetsADateAndVehicleBeforeItCanBeConfirmed(): void
    {
        $request = (new Reservation(Experience::October))
            ->setFullName('Demande Octobre')
            ->setPhone('+216 20 000 000')
            ->setEmail('octobre@example.com')
            ->setDate(new \DateTimeImmutable('2026-10-14'))
            ->setVehicle(Vehicle::Advice);
        $em = $this->entityManager();
        $em->persist($request);
        $em->flush();
        $this->loginAs(['ROLE_RESERVATIONS']);

        // "Conseil" is not a vehicle: it must be defined first.
        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-row-action--confirmReservation')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'véhicule');

        $this->submitEdit($request, ['date' => '2026-10-20', 'vehicle' => Vehicle::Territory->value]);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-row-action--confirmReservation')->form());

        $confirmed = $this->reload($request);
        self::assertSame('2026-10-20', $confirmed->getDate()->format('Y-m-d'));
        self::assertSame(Vehicle::Territory, $confirmed->getVehicle());
        self::assertSame(ReservationStatus::Confirmed, $confirmed->getStatus());
    }

    public function testCashierConfirmsWithoutAmountWithoutCreditingTheCagnotte(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-01', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        self::assertResponseIsSuccessful();
        // The modal is prefilled with the client share: emptied, only the booking is confirmed.
        $this->client->submit($crawler->filter('form.dp-row-action--confirmAndCashIn')->form(['amount' => '']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'validée');
        $confirmed = $this->reload($reservation);
        self::assertSame(ReservationStatus::Confirmed, $confirmed->getStatus());
        self::assertFalse($confirmed->isTestDriveCompleted());
        self::assertCount(0, $this->contributions());
    }

    public function testCashierConfirmsWithTheAmountReceivedAndCreditsTheCagnotte(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-01', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-row-action--confirmAndCashIn')->form(['amount' => '35']));

        self::assertResponseRedirects('/admin/reservation');
        $crawler = $this->client->followRedirect();
        self::assertSame('+35 DT', $crawler->filter('template[data-toast]')->attr('data-badge'));
        $cashed = $this->reload($reservation);
        self::assertSame(ReservationStatus::Confirmed, $cashed->getStatus());
        self::assertTrue($cashed->isTestDriveCompleted());
        self::assertSame('resa@alphaford.tn', $cashed->getTestDriveValidatedBy());
        $contributions = $this->contributions();
        self::assertCount(1, $contributions);
        self::assertSame(15, $contributions[0]->getClientAmount());
        self::assertSame(35, $contributions[0]->getAmount());
    }

    public function testCashierCashesInAConfirmedReservationFromTheList(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-01', Vehicle::RangerRaptor, '09:00');
        $reservation->confirm();
        $this->entityManager()->flush();
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-validate-test-drive')->form(['amount' => '30']));

        self::assertResponseRedirects('/admin/reservation');
        self::assertTrue($this->reload($reservation)->isTestDriveCompleted());
        self::assertCount(1, $this->contributions());
    }

    public function testCashierCashesInAFutureReservation(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $form = $crawler->filter('form.dp-row-action--confirmAndCashIn');
        self::assertSame('30', $form->filter('input[name="amount"]')->attr('value'), '10 DT client + 20 DT Alpha Ford by default.');
        $this->client->submit($form->form());
        $this->client->followRedirect();

        $cashed = $this->reload($reservation);
        self::assertSame(ReservationStatus::Confirmed, $cashed->getStatus());
        self::assertTrue($cashed->isTestDriveCompleted());
        self::assertSame(10, $this->contributions()[0]->getClientAmount());
        self::assertSame(30, $this->contributions()[0]->getAmount());
    }

    public function testCashierCustomAmountIncrementsTheCagnotteAndItsHistory(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-01', Vehicle::RangerRaptor, '09:00')->setFullName('Sarra Ben Ali');
        $this->entityManager()->flush();
        $fund = static::getContainer()->get(SolidarityFund::class);
        $before = $fund->total();
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-row-action--confirmAndCashIn')->form(['amount' => '50']));
        $crawler = $this->client->followRedirect();

        self::assertSame('+50 DT', $crawler->filter('template[data-toast]')->attr('data-badge'));
        self::assertSame($before + 50, $fund->total());
        self::assertSame(30, $this->contributions()[0]->getClientAmount());
        self::assertSame(20, $this->contributions()[0]->getAlphaFordAmount());

        $crawler = $this->client->request('GET', '/admin/cagnotte');
        $row = $crawler->filter('table.datagrid tbody tr')->first()->text();
        self::assertStringContainsString('Test drive : Sarra Ben Ali', $row);
        self::assertStringContainsString('50', $row);
    }

    public function testCashierCannotCashInLessThanAlphaFordShare(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-01', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        $this->client->submit($crawler->filter('form.dp-row-action--confirmAndCashIn')->form(['amount' => '15']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'montant reçu');
        self::assertSame(ReservationStatus::Pending, $this->reload($reservation)->getStatus());
        self::assertCount(0, $this->contributions());
    }

    public function testCashierCanOnlyViewAndValidate(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.dp-row-action--confirmAndCashIn'));
        self::assertCount(0, $crawler->filter('form.dp-row-action--archiveReservation'));
        self::assertStringNotContainsString(sprintf('/admin/reservation/%d/modifier', $reservation->getId()), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/admin/reservation/%d', $reservation->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.dp-row-action--archiveReservation');

        $this->client->request('GET', sprintf('/admin/reservation/%d/modifier', $reservation->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/admin/reservation/%d/archiver', $reservation->getId()), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ReservationStatus::Pending, $this->reload($reservation)->getStatus());
    }

    public function testReservationsTeamCannotCashIn(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-01', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_RESERVATIONS']);

        $crawler = $this->client->request('GET', '/admin/reservation');
        self::assertCount(0, $crawler->filter('input[name="amount"]'));
        $token = $crawler->filter('form.dp-row-action--confirmReservation input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/admin/reservation/%d/confirmer', $reservation->getId()), ['_token' => $token, 'amount' => '30']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ReservationStatus::Pending, $this->reload($reservation)->getStatus());
        self::assertCount(0, $this->contributions());
    }

    public function testInvalidCsrfTokenIsRefused(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_RESERVATIONS']);

        $this->client->request('POST', sprintf('/admin/reservation/%d/archiver', $reservation->getId()), ['_token' => 'forged']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Jeton de sécurité invalide');
        self::assertSame(ReservationStatus::Pending, $this->reload($reservation)->getStatus());
    }

    /**
     * @param array<string, string> $values reservation_edit fields to change
     */
    private function submitEdit(Reservation $reservation, array $values): void
    {
        $crawler = $this->client->request('GET', sprintf('/admin/reservation/%d/modifier', $reservation->getId()));
        self::assertResponseIsSuccessful();

        $fields = [];
        foreach ($values as $name => $value) {
            $fields[sprintf('reservation_edit[%s]', $name)] = $value;
        }
        $this->client->submit($crawler->selectButton('Enregistrer')->form($fields));
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $this->client->loginUser($this->createUser('resa@alphaford.tn', $roles), 'admin');
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
}
