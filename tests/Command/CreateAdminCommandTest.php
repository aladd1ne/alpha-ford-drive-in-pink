<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AdminUser;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdminCommandTest extends KernelTestCase
{
    use DatabaseTrait;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetDatabase();
        $this->tester = new CommandTester((new Application($kernel))->find('app:create-admin'));
    }

    public function testCreatesAnAdminFromPrompts(): void
    {
        $this->tester->setInputs(['  Admin@AlphaFord.tn ', 'secret-pass', 'secret-pass']);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $admin = $this->entityManager()->getRepository(AdminUser::class)->findOneBy(['email' => 'admin@alphaford.tn']);
        self::assertNotNull($admin);
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertNotSame('secret-pass', $admin->getPassword());
    }

    public function testCreatesACommercialWithTheOption(): void
    {
        $this->tester->setInputs(['vente@alphaford.tn', 'secret-pass', 'secret-pass']);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--commercial' => true]));
        self::assertStringContainsString('Commercial user "vente@alphaford.tn" created', $this->tester->getDisplay());

        $user = $this->entityManager()->getRepository(AdminUser::class)->findOneBy(['email' => 'vente@alphaford.tn']);
        self::assertNotNull($user);
        self::assertContains('ROLE_COMMERCIAL', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testRepromptsOnInvalidAnswers(): void
    {
        // invalid e-mail, then valid; short password, then valid; wrong confirmation, then right.
        $this->tester->setInputs(['not-an-email', 'admin@alphaford.tn', 'short', 'secret-pass', 'other', 'secret-pass']);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Invalid e-mail address.', $display);
        self::assertStringContainsString('at least 8 characters', $display);
        self::assertStringContainsString('The passwords do not match.', $display);
    }

    public function testRefusesAnExistingEmail(): void
    {
        $this->tester->setInputs(['admin@alphaford.tn', 'secret-pass', 'secret-pass']);
        $this->tester->execute([]);

        $this->tester->setInputs(['admin@alphaford.tn', 'admin2@alphaford.tn', 'secret-pass', 'secret-pass']);
        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
        self::assertCount(2, $this->entityManager()->getRepository(AdminUser::class)->findAll());
    }

    public function testFailsWithoutInteraction(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([], ['interactive' => false]));
    }
}
