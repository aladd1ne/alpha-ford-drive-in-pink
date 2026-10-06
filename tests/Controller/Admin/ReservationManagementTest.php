<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reservations team (ROLE_RESERVATIONS): confirm, edit, archive, October requests.
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

    public function testCashierCannotManageReservations(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->loginAs(['ROLE_CASHIER']);

        $this->client->request('GET', '/admin/reservation');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/admin/reservation/%d/archiver', $reservation->getId()), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ReservationStatus::Pending, $this->reload($reservation)->getStatus());
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
}
