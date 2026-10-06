<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FundContribution;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Service\SolidarityFund;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cagnotte history and amounts added by hand.
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

    public function testCommercialAddsAnAmountByHand(): void
    {
        $this->loginAs(['ROLE_CASHIER']);
        $before = $this->fund()->snapshot();

        $this->client->request('GET', '/admin/cagnotte/ajouter');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Ajouter', [
            'manual_contribution[amount]' => '250',
            'manual_contribution[note]' => 'Don sur place',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $toast = $crawler->filter('template[data-toast]');
        self::assertSame('+250 DT', $toast->attr('data-badge'));
        self::assertStringContainsString('Montant ajouté', (string) $toast->attr('data-title'));
        self::assertSelectorTextContains('table.datagrid', 'Don sur place');

        $after = $this->fund()->snapshot();
        self::assertSame($before->total + 250, $after->total);
        self::assertSame($before->otherAmount + 250, $after->otherAmount);
        self::assertSame($before->testDrives, $after->testDrives, 'A manual amount is not a test drive.');

        $contribution = $this->entityManager()->getRepository(FundContribution::class)->findOneBy([]);
        self::assertTrue($contribution->isManual());
        self::assertSame('commercial@alphaford.tn', $contribution->getValidatedBy());
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

    public function testInvalidAmountIsRefused(): void
    {
        $this->loginAs(['ROLE_CASHIER']);
        $this->client->request('GET', '/admin/cagnotte/ajouter');

        $this->client->submitForm('Ajouter', [
            'manual_contribution[amount]' => '0',
            'manual_contribution[note]' => 'Erreur',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(FundContribution::class)->count([]));
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

    private function fund(): SolidarityFund
    {
        return static::getContainer()->get(SolidarityFund::class);
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $this->client->loginUser($this->createUser('commercial@alphaford.tn', $roles), 'admin');
    }
}
