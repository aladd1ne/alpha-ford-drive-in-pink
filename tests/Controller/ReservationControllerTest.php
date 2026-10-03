<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReservationControllerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testHubOffersTheThreeJourneys(): void
    {
        $crawler = $this->client->request('GET', '/reservation');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/reservation/everest-ranger"]'));
        self::assertCount(1, $crawler->filter('a[href="/reservation/territory"]'));
        self::assertCount(1, $crawler->filter('a[href="/reservation/octobre"]'));
    }

    public function testEverestRangerBookingIsConfirmed(): void
    {
        $this->submit('everest-ranger', 'Réserver mon créneau', [
            'reservation[date]' => '2026-10-09',
            'reservation[vehicle]' => 'ranger_raptor',
            'reservation[slot]' => '15:45',
        ]);

        self::assertResponseRedirects('/reservation/everest-ranger/confirmation');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Votre demande de participation à l’Everest & Ranger Experience a bien été enregistrée.');
        self::assertSelectorTextContains('main', 'Un conseiller Alpha Ford vous contactera pour confirmer votre créneau du 9 ou 10 octobre.');
        self::assertSelectorTextContains('main a[href="/"]', 'Retour à l’accueil');

        $saved = $this->entityManager()->getRepository(Reservation::class)->findAll();
        self::assertCount(1, $saved);
        self::assertSame(Vehicle::RangerRaptor, $saved[0]->getVehicle());
        self::assertSame('15:45', $saved[0]->getSlot());
        self::assertSame(1, $saved[0]->getSeat());
    }

    public function testFullSlotIsRefused(): void
    {
        $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');

        $this->submit('everest-ranger', 'Réserver mon créneau', [
            'reservation[date]' => '2026-10-09',
            'reservation[vehicle]' => 'ranger_raptor',
            'reservation[slot]' => '09:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.dp-form', 'Ce créneau est complet pour ce véhicule.');
        self::assertCount(1, $this->entityManager()->getRepository(Reservation::class)->findAll());
    }

    public function testSaturdaySlotAfterClosingIsRefused(): void
    {
        $this->submit('everest-ranger', 'Réserver mon créneau', [
            'reservation[date]' => '2026-10-10',
            'reservation[vehicle]' => 'everest_xlt',
            'reservation[slot]' => '14:15',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.dp-form', 'Ce créneau n’est pas proposé ce jour-là.');
    }

    public function testContactDetailsAreValidated(): void
    {
        $this->submit('everest-ranger', 'Réserver mon créneau', [
            'reservation[fullName]' => '',
            'reservation[phone]' => 'abc',
            'reservation[email]' => 'not-an-email',
            'reservation[date]' => '2026-10-09',
            'reservation[vehicle]' => 'ranger_raptor',
            'reservation[slot]' => '09:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.dp-form', 'Veuillez saisir votre nom et prénom.');
        self::assertSelectorTextContains('.dp-form', 'Numéro de téléphone invalide.');
        self::assertSelectorTextContains('.dp-form', 'Adresse e-mail invalide.');
    }

    public function testTerritoryBookingNeedsNoVehicleChoice(): void
    {
        $crawler = $this->client->request('GET', '/reservation/territory');
        self::assertCount(0, $crawler->filter('input[name="reservation[vehicle]"]'));

        $this->submit('territory', 'Réserver mon créneau', [
            'reservation[date]' => '2026-10-24',
            'reservation[slot]' => '13:30',
        ]);

        self::assertResponseRedirects('/reservation/territory/confirmation');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Votre demande de participation à la Territory Experience a bien été enregistrée.');
        self::assertSelectorTextContains('main', 'créneau du 23 ou 24 octobre');
        self::assertSame(Vehicle::Territory, $this->entityManager()->getRepository(Reservation::class)->findAll()[0]->getVehicle());
    }

    public function testOctoberRequestIsConfirmed(): void
    {
        $this->submit('octobre', 'Envoyer ma demande', [
            'reservation[date]' => '2026-10-15',
            'reservation[vehicle]' => 'advice',
        ]);

        self::assertResponseRedirects('/reservation/octobre/confirmation');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Merci, votre demande a bien été enregistrée.');
        self::assertSelectorTextContains('main', 'Un conseiller Alpha Ford vous contactera afin de confirmer votre rendez-vous.');
    }

    public function testOctoberRequestRefusesEventDaysAndOtherMonths(): void
    {
        foreach (['2026-10-23', '2026-11-05'] as $day) {
            $this->submit('octobre', 'Envoyer ma demande', [
                'reservation[date]' => $day,
                'reservation[vehicle]' => 'territory',
            ]);

            self::assertResponseStatusCodeSame(422, $day);
            self::assertSelectorTextContains('.dp-form', 'Choisissez un jour d’octobre en dehors des journées');
        }

        $this->submit('octobre', 'Envoyer ma demande', [
            'reservation[date]' => '2026-09-30',
            'reservation[vehicle]' => 'territory',
        ]);
        self::assertSelectorTextContains('.dp-form', 'Veuillez choisir une date à partir d’aujourd’hui.');

        self::assertCount(0, $this->entityManager()->getRepository(Reservation::class)->findAll());
    }

    public function testFullExperienceShowsCompleteButton(): void
    {
        $this->fillExperience(Experience::Territory);

        $crawler = $this->client->request('GET', '/reservation');
        self::assertCount(0, $crawler->filter('a[href="/reservation/territory"]'));
        self::assertSelectorTextContains('button[disabled]', 'Complet');
        self::assertCount(1, $crawler->filter('a[href="/reservation/everest-ranger"]'));

        $crawler = $this->client->request('GET', '/reservation/territory');
        self::assertCount(0, $crawler->filter('form.dp-form'));
        self::assertSelectorTextContains('.dp-res-full button[disabled]', 'Complet');

        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('.dp-event button[disabled]', 'Complet');
    }

    public function testConfirmationPageNeedsASubmission(): void
    {
        $this->client->request('GET', '/reservation/territory/confirmation');

        self::assertResponseRedirects('/reservation/territory');
    }

    /**
     * @param array<string, string> $values
     */
    private function submit(string $experience, string $button, array $values): void
    {
        $crawler = $this->client->request('GET', '/reservation/' . $experience);
        $form = $crawler->selectButton($button)->form();

        $this->client->submit($form, $values + [
            'reservation[fullName]' => 'Sarra Ben Ali',
            'reservation[phone]' => '+216 22 123 456',
            'reservation[email]' => 'sarra@example.com',
        ]);
    }
}
