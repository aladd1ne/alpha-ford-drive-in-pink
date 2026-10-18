<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\FundContributionCrudController;
use App\Entity\FundContribution;
use App\Entity\Product;
use App\Enum\ContributionSource;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cagnotte history, admin corrections, the "Ajouter à la cagnotte" page (donations from
 * registered people and product sales), open to every profile, and the admin product list.
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

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function profiles(): iterable
    {
        yield 'admin' => [['ROLE_ADMIN']];
        yield 'cashier' => [['ROLE_CASHIER']];
        yield 'reservations' => [['ROLE_RESERVATIONS']];
        yield 'commercial' => [['ROLE_COMMERCIAL']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('profiles')]
    public function testEveryProfileAddsADonation(array $roles): void
    {
        $donor = $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00')
            ->setFullName('Karim Ben Salah')
            ->setEmail('karim@example.com');
        $this->entityManager()->flush();
        $this->loginAs($roles);

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href*="routeName=admin_fund_add"]'), 'Menu link for every profile.');

        $crawler = $this->client->request('GET', '/admin/ajouter-a-la-cagnotte');
        self::assertResponseIsSuccessful();
        $option = $crawler->filter('[data-donor-list] li[data-email="karim@example.com"]');
        self::assertStringContainsString('Karim Ben Salah', $option->text(), 'Registered people are listed with their name and phone.');
        self::assertStringContainsString('+216 20 000 000', $option->text());
        self::assertSame('karim ben salah +216 20 000 000 21620000000 karim@example.com', $option->attr('data-search'));
        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Donation->value,
            'fund_contribution[donor]' => ' Karim@Example.com ',
            'fund_contribution[amount]' => '150',
            'fund_contribution[note]' => 'Chèque',
        ]);

        self::assertResponseRedirects('/admin/ajouter-a-la-cagnotte');
        $crawler = $this->client->followRedirect();
        self::assertSame('+150 DT', $crawler->filter('template[data-toast]')->attr('data-badge'));
        self::assertSelectorTextContains('table', 'Don : Karim Ben Salah (karim@example.com) : Chèque');

        $contribution = $this->entityManager()->getRepository(FundContribution::class)->findOneBy([]);
        self::assertTrue($contribution->isManual());
        self::assertSame(150, $contribution->getAmount());
        self::assertSame(ContributionSource::Donation, $contribution->getSource());
        self::assertSame($donor->getId(), $contribution->getDonor()?->getId());
        self::assertSame('commercial@alphaford.tn', $contribution->getValidatedBy());
    }

    public function testADonationNeedsARegisteredDonor(): void
    {
        $this->loginAs(['ROLE_CASHIER']);

        $this->client->request('GET', '/admin/ajouter-a-la-cagnotte');
        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Donation->value,
            'fund_contribution[donor]' => 'inconnu@example.com',
            'fund_contribution[amount]' => '150',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Aucune inscription avec cette adresse e-mail.');

        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Donation->value,
            'fund_contribution[donor]' => '',
            'fund_contribution[amount]' => '150',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Choisissez la personne parmi les inscrits.');
        self::assertCount(0, $this->entityManager()->getRepository(FundContribution::class)->findAll());
    }

    public function testAProductSaleCreditsItsPriceTimesTheQuantity(): void
    {
        $buyer = $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00')
            ->setFullName('Karim Ben Salah')
            ->setEmail('karim@example.com');
        $em = $this->entityManager();
        $em->persist($product = (new Product())->setName('T-shirt Drive in Pink')->setPrice(25));
        $em->persist((new Product())->setName('Casquette')->setPrice(15)->setActive(false));
        $em->flush();
        $this->loginAs(['ROLE_CASHIER']);

        $crawler = $this->client->request('GET', '/admin/ajouter-a-la-cagnotte');
        self::assertSame('25', $crawler->filter(sprintf('select#fund_contribution_product option[value="%d"]', $product->getId()))->attr('data-price'));
        self::assertStringNotContainsString('Casquette', $crawler->filter('select#fund_contribution_product')->text(), 'Inactive products are not sold.');

        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Product->value,
            'fund_contribution[donor]' => 'karim@example.com',
            'fund_contribution[product]' => (string) $product->getId(),
            'fund_contribution[quantity]' => '3',
            // Ignored for a sale: the amount comes from the product price.
            'fund_contribution[amount]' => '1',
        ]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSame('+75 DT', $crawler->filter('template[data-toast]')->attr('data-badge'));

        $contribution = $em->getRepository(FundContribution::class)->findOneBy([]);
        self::assertSame(75, $contribution->getAmount());
        self::assertSame(3, $contribution->getQuantity());
        self::assertSame(ContributionSource::Product, $contribution->getSource());
        self::assertSame($buyer->getId(), $contribution->getDonor()?->getId());

        // A later price change does not touch the sale.
        $product->setPrice(30);
        $em->flush();
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        $row = $crawler->filter('table.datagrid tbody tr')->first()->text();
        self::assertStringContainsString('Achat d’un produit : T-shirt Drive in Pink × 3 — Karim Ben Salah (karim@example.com)', $row);
        self::assertStringContainsString('75', $row);
    }

    public function testAProductSaleNeedsAProductAndAQuantity(): void
    {
        $em = $this->entityManager();
        $em->persist($product = (new Product())->setName('T-shirt Drive in Pink')->setPrice(25));
        $em->flush();
        $this->loginAs(['ROLE_CASHIER']);

        $this->client->request('GET', '/admin/ajouter-a-la-cagnotte');
        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Product->value,
            'fund_contribution[product]' => '',
            'fund_contribution[quantity]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Choisissez le produit vendu.');

        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Product->value,
            'fund_contribution[product]' => (string) $product->getId(),
            'fund_contribution[quantity]' => '0',
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Product->value,
            'fund_contribution[donor]' => '',
            'fund_contribution[product]' => (string) $product->getId(),
            'fund_contribution[quantity]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Choisissez la personne parmi les inscrits.');
        self::assertCount(0, $em->getRepository(FundContribution::class)->findAll());
    }

    public function testAFreeProductSaleIsRecordedForZero(): void
    {
        $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00')->setEmail('karim@example.com');
        $em = $this->entityManager();
        $em->persist($product = (new Product())->setName('Badge')->setPrice(0));
        $em->flush();
        $this->loginAs(['ROLE_CASHIER']);

        $this->client->request('GET', '/admin/ajouter-a-la-cagnotte');
        $this->client->submitForm('Ajouter', [
            'fund_contribution[source]' => ContributionSource::Product->value,
            'fund_contribution[donor]' => 'karim@example.com',
            'fund_contribution[product]' => (string) $product->getId(),
            'fund_contribution[quantity]' => '2',
        ]);

        self::assertResponseRedirects();
        $contribution = $em->getRepository(FundContribution::class)->findOneBy([]);
        self::assertSame(0, $contribution->getAmount());
        self::assertSame(2, $contribution->getQuantity());
    }

    public function testAdminManagesProducts(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(1, $crawler->filter('a[href*="/admin/produits"]'));

        $this->client->request('GET', '/admin/produits/new');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Créer', [
            'Product[name]' => 'Mug Drive in Pink',
            'Product[price]' => '20',
        ]);
        self::assertResponseRedirects();

        $product = $this->entityManager()->getRepository(Product::class)->findOneBy(['name' => 'Mug Drive in Pink']);
        self::assertSame(20, $product->getPrice());
        self::assertTrue($product->isActive());

        foreach (['Badge' => '0', 'Autocollant' => ''] as $name => $price) {
            $this->client->request('GET', '/admin/produits/new');
            $this->client->submitForm('Créer', ['Product[name]' => $name, 'Product[price]' => $price]);
            self::assertResponseRedirects();
            self::assertSame(0, $this->entityManager()->getRepository(Product::class)->findOneBy(['name' => $name])->getPrice(), 'A free product: 0 or left empty.');
        }
    }

    public function testASoldProductCannotBeDeleted(): void
    {
        $em = $this->entityManager();
        $em->persist($sold = (new Product())->setName('T-shirt')->setPrice(25));
        $em->persist((new Product())->setName('Mug')->setPrice(20));
        $buyer = $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00');
        $em->persist(FundContribution::productSale($buyer, $sold, 1, null, new \DateTimeImmutable(), 'caisse@alphaford.tn'));
        $em->flush();

        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/admin/produits');
        self::assertCount(1, $crawler->filter('table.datagrid .action-delete'), 'Only the unsold product can be deleted.');
        self::assertStringContainsString('Mug', $crawler->filter('table.datagrid .action-delete')->closest('tr')->text());
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffProfiles')]
    public function testOnlyAdminsManageProducts(array $roles): void
    {
        $this->loginAs($roles);

        $this->client->request('GET', '/admin/produits');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staffProfiles(): iterable
    {
        yield 'cashier' => [['ROLE_CASHIER']];
        yield 'reservations' => [['ROLE_RESERVATIONS']];
    }

    public function testAnAmountNeedsASourceAndAValidAmount(): void
    {
        $this->loginAs(['ROLE_RESERVATIONS']);

        $this->client->request('GET', '/admin/ajouter-a-la-cagnotte');
        $this->client->submitForm('Ajouter', ['fund_contribution[amount]' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager()->getRepository(FundContribution::class)->findAll());
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

    public function testAdminCorrectsATestDriveCredit(): void
    {
        $reservation = $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00')->setFullName('Sarra Ben Ali');
        $em = $this->entityManager();
        $em->persist($contribution = FundContribution::forTestDrive($reservation, new \DateTimeImmutable(), 'caisse@alphaford.tn'));
        $em->flush();
        $id = $contribution->getId();

        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        $form = $crawler->filter('form.dp-row-action--correctContribution')->form();
        $form['client_amount'] = '10';
        $form['alpha_ford_amount'] = '25';
        $form['reason'] = 'Erreur de saisie : 10 DT reçus';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $em->clear();
        $corrected = $em->find(FundContribution::class, $id);
        self::assertSame(35, $corrected->getAmount());
        self::assertSame(10, $corrected->getClientAmount());
        self::assertSame(25, $corrected->getAlphaFordAmount());
        self::assertSame('Erreur de saisie : 10 DT reçus', $corrected->getCorrectionReason());
        self::assertSame('commercial@alphaford.tn', $corrected->getCorrectedBy());
        self::assertSame('caisse@alphaford.tn', $corrected->getValidatedBy());

        $this->client->followRedirect();
        self::assertSelectorTextContains('table.datagrid', 'Erreur de saisie : 10 DT reçus');
        self::assertSelectorTextContains('table.datagrid', 'commercial@alphaford.tn');
    }

    public function testAdminCorrectsAManualAmount(): void
    {
        $em = $this->entityManager();
        $em->persist($contribution = FundContribution::manual(100, 'Don', new \DateTimeImmutable(), 'caisse@alphaford.tn'));
        $em->flush();
        $id = $contribution->getId();

        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        $form = $crawler->filter('form.dp-row-action--correctContribution')->form();
        $form['amount'] = '150';
        $form['reason'] = '';
        $this->client->submit($form);

        $em->clear();
        $corrected = $em->find(FundContribution::class, $id);
        self::assertSame(150, $corrected->getAmount());
        self::assertTrue($corrected->isCorrected());
        self::assertNull($corrected->getCorrectionReason());
        self::assertSame('commercial@alphaford.tn', $corrected->getCorrectedBy());
    }

    public function testACorrectionNeedsAValidAmount(): void
    {
        $em = $this->entityManager();
        $em->persist($contribution = FundContribution::manual(100, 'Don', new \DateTimeImmutable(), 'caisse@alphaford.tn'));
        $em->flush();
        $id = $contribution->getId();

        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        $form = $crawler->filter('form.dp-row-action--correctContribution')->form();

        $form['amount'] = '0';
        $form['reason'] = 'Erreur';
        $this->client->submit($form);
        $form['amount'] = '50';
        $form['reason'] = str_repeat('x', FundContributionCrudController::REASON_MAX_LENGTH + 1);
        $this->client->submit($form);

        $em->clear();
        $unchanged = $em->find(FundContribution::class, $id);
        self::assertSame(100, $unchanged->getAmount());
        self::assertFalse($unchanged->isCorrected());
    }

    public function testATestDriveCorrectionNeedsBothAmounts(): void
    {
        $reservation = $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, '09:00')->setFullName('Sarra Ben Ali');
        $em = $this->entityManager();
        $em->persist($contribution = FundContribution::forTestDrive($reservation, new \DateTimeImmutable(), 'caisse@alphaford.tn'));
        $em->flush();
        $id = $contribution->getId();

        $this->loginAs(['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        $form = $crawler->filter('form.dp-row-action--correctContribution')->form();
        $form['client_amount'] = '10';
        $form['alpha_ford_amount'] = '';
        $form['reason'] = 'Erreur';
        $this->client->submit($form);

        $em->clear();
        $unchanged = $em->find(FundContribution::class, $id);
        self::assertSame(30, $unchanged->getAmount());
        self::assertFalse($unchanged->isCorrected());
    }

    public function testOnlyAdminsCanCorrect(): void
    {
        $em = $this->entityManager();
        $em->persist($contribution = FundContribution::manual(100, 'Don', new \DateTimeImmutable(), 'caisse@alphaford.tn'));
        $em->flush();
        $id = $contribution->getId();

        $this->loginAs(['ROLE_CASHIER']);
        $crawler = $this->client->request('GET', '/admin/cagnotte');
        self::assertCount(0, $crawler->filter('form.dp-row-action--correctContribution'));

        $this->client->request('POST', sprintf('/admin/cagnotte/%d/correct', $id), ['amount' => '1', 'reason' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $em->clear();
        self::assertSame(100, $em->find(FundContribution::class, $id)->getAmount());
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $this->client->loginUser($this->createUser('commercial@alphaford.tn', $roles), 'admin');
    }
}
