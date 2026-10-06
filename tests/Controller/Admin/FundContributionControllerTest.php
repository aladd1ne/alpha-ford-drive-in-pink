<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FundContribution;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cagnotte history (adding an amount by hand was removed).
 */
final class FundContributionControllerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testAddingAnAmountByHandIsGone(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin/cagnotte');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ajouter un montant', $crawler->filter('body')->text());

        $this->client->request('GET', '/admin/cagnotte/ajouter');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHistoryNamesTheTestDriveOfEachCredit(): void
    {
        $reservation = $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00')->setFullName('Sarra Ben Ali');
        $em = $this->entityManager();
        $em->persist(FundContribution::forTestDrive($reservation, new \DateTimeImmutable(), 'commercial@alphaford.tn'));
        $em->flush();

        $this->loginAs(['ROLE_CASHIER']);
        $this->client->request('GET', '/admin/cagnotte');

        self::assertSelectorTextContains('table.datagrid', 'Test drive : Sarra Ben Ali');
    }

    public function testOnlyAdminsCanDeleteAManualAmount(): void
    {
        $em = $this->entityManager();
        $em->persist(FundContribution::manual(100, 'Erreur de saisie', new \DateTimeImmutable(), 'commercial@alphaford.tn'));
        $em->flush();

        $this->loginAs(['ROLE_CASHIER']);
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table.datagrid .action-delete'));
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $this->client->loginUser($this->createUser('commercial@alphaford.tn', $roles), 'admin');
    }
}
