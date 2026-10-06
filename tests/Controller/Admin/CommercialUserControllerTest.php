<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommercialUserControllerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testAdminCreatesACommercialWhoCanLogIn(): void
    {
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $this->createCommercial(' Vente@AlphaFord.tn ', 'secret-pass', 'secret-pass');
        self::assertResponseRedirects();

        $user = $this->findUser('vente@alphaford.tn');
        self::assertNotNull($user);
        self::assertSame([AdminUser::ROLE_CASHIER, 'ROLE_USER'], $user->getRoles());
        self::assertTrue($user->isCommercialOnly());
        self::assertNotSame('secret-pass', $user->getPassword());
        self::assertTrue(static::getContainer()->get('security.user_password_hasher')->isPasswordValid($user, 'secret-pass'));

        // The new account works on the real login form and reaches the commercial page.
        $this->client->request('GET', '/admin/logout');
        $crawler = $this->client->request('GET', '/admin/login');
        $this->client->submit($crawler->filter('form')->form(), ['_username' => 'vente@alphaford.tn', '_password' => 'secret-pass']);
        self::assertResponseRedirects('http://localhost/admin');
        $this->client->request('GET', '/admin/test-drives');
        self::assertResponseIsSuccessful();
    }

    public function testListShowsOnlyCommercials(): void
    {
        $this->createUser('vente@alphaford.tn', [AdminUser::ROLE_COMMERCIAL]);
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/admin/commerciaux');

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('table.datagrid')->text();
        self::assertStringContainsString('vente@alphaford.tn', $table);
        self::assertStringNotContainsString('admin@alphaford.tn', $table);
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->createUser('vente@alphaford.tn', [AdminUser::ROLE_COMMERCIAL]);
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $this->createCommercial('vente@alphaford.tn', 'secret-pass', 'secret-pass');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form#new-AdminUser-form', 'Un compte existe déjà avec cette adresse e-mail.');

        $this->createCommercial('nouveau@alphaford.tn', 'short', 'short');
        self::assertSelectorTextContains('form#new-AdminUser-form', 'au moins 8 caractères');

        $this->createCommercial('nouveau@alphaford.tn', 'secret-pass', 'other-pass');
        self::assertSelectorTextContains('form#new-AdminUser-form', 'Les mots de passe ne correspondent pas.');

        $this->createCommercial('nouveau@alphaford.tn', '', '');
        self::assertSelectorTextContains('form#new-AdminUser-form', 'Veuillez saisir un mot de passe.');

        self::assertNull($this->findUser('nouveau@alphaford.tn'));
    }

    public function testEditKeepsThePasswordWhenLeftBlank(): void
    {
        $commercial = $this->createUser('vente@alphaford.tn', [AdminUser::ROLE_CASHIER]);
        $hash = $commercial->getPassword();
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', sprintf('/admin/commerciaux/%d/edit', $commercial->getId()));
        $this->client->submit($crawler->filter('form#edit-AdminUser-form')->form(), [
            'AdminUser[email]' => 'ventes@alphaford.tn',
        ]);

        self::assertResponseRedirects();
        $user = $this->findUser('ventes@alphaford.tn');
        self::assertNotNull($user);
        self::assertSame($hash, $user->getPassword());
        self::assertTrue($user->isCommercialOnly());
    }

    public function testAdminGivesBothStaffRoles(): void
    {
        $member = $this->createUser('vente@alphaford.tn', [AdminUser::ROLE_CASHIER]);
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', sprintf('/admin/commerciaux/%d/edit', $member->getId()));
        $form = $crawler->filter('form#edit-AdminUser-form')->form();
        $form['AdminUser[staffRoles]'][0]->tick();
        $form['AdminUser[staffRoles]'][1]->tick();
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertEqualsCanonicalizing([AdminUser::ROLE_RESERVATIONS, AdminUser::ROLE_CASHIER], $this->findUser('vente@alphaford.tn')->getStaffRoles());
    }

    public function testAStaffRoleIsRequired(): void
    {
        $member = $this->createUser('vente@alphaford.tn', [AdminUser::ROLE_CASHIER]);
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', sprintf('/admin/commerciaux/%d/edit', $member->getId()));
        $form = $crawler->filter('form#edit-AdminUser-form')->form();
        $form['AdminUser[staffRoles]'][1]->untick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#edit-AdminUser-form', 'Choisissez au moins un rôle.');
    }

    public function testAdminAccountsCannotBeEditedOrDeletedHere(): void
    {
        $otherAdmin = $this->createUser('boss@alphaford.tn', [AdminUser::ROLE_ADMIN]);
        $this->loginAs('admin@alphaford.tn', [AdminUser::ROLE_ADMIN]);

        $this->client->request('GET', sprintf('/admin/commerciaux/%d/edit', $otherAdmin->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/commerciaux/%d/delete', $otherAdmin->getId()));
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull($this->findUser('boss@alphaford.tn'));
    }

    public function testCommercialCannotManageUsers(): void
    {
        $this->loginAs('vente@alphaford.tn', [AdminUser::ROLE_COMMERCIAL]);

        $this->client->request('GET', '/admin/commerciaux');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/commerciaux/new');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(0, $crawler->filter('a[href*="/admin/commerciaux"]'), 'No menu link for commercials.');
    }

    public function testAnonymousUserIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/admin/commerciaux/new');

        self::assertResponseRedirects('http://localhost/admin/login');
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(string $email, array $roles): void
    {
        $this->client->loginUser($this->createUser($email, $roles), 'admin');
    }

    private function createCommercial(string $email, string $password, string $confirmation): void
    {
        $crawler = $this->client->request('GET', '/admin/commerciaux/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form#new-AdminUser-form')->form(), [
            'AdminUser[email]' => $email,
            'AdminUser[plainPassword][first]' => $password,
            'AdminUser[plainPassword][second]' => $confirmation,
        ]);
    }

    private function findUser(string $email): ?AdminUser
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
    }
}
