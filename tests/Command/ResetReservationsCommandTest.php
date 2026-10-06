<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetReservationsCommandTest extends KernelTestCase
{
    use DatabaseTrait;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetDatabase();
        $this->tester = new CommandTester((new Application($kernel))->find('app:reservations:reset'));
    }

    public function testDeletesReservationsAndTheirCreditsAndRestartsIds(): void
    {
        $this->seed();
        $this->tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $em = $this->entityManager();
        $em->clear();
        self::assertSame(0, $em->getRepository(Reservation::class)->count([]));
        $kept = $em->getRepository(FundContribution::class)->findAll();
        self::assertCount(1, $kept, 'The amount added by hand is kept.');
        self::assertTrue($kept[0]->isManual());

        $next = $this->createBooking(Experience::Territory, '2026-10-10', Vehicle::Territory, '09:00');
        self::assertSame(1, $next->getId());
    }

    public function testWithFundEmptiesTheCagnotteToo(): void
    {
        $this->seed();

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--with-fund' => true, '--force' => true]));

        $this->entityManager()->clear();
        self::assertSame(0, $this->entityManager()->getRepository(FundContribution::class)->count([]));
    }

    public function testDeclinedConfirmationDeletesNothing(): void
    {
        $this->seed();
        $this->tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $this->entityManager()->clear();
        self::assertSame(2, $this->entityManager()->getRepository(Reservation::class)->count([]));
    }

    public function testRefusesToRunWithoutPromptUnlessForced(): void
    {
        $this->seed();

        self::assertSame(Command::FAILURE, $this->tester->execute([], ['interactive' => false]));

        $this->entityManager()->clear();
        self::assertSame(2, $this->entityManager()->getRepository(Reservation::class)->count([]));
    }

    /**
     * Two reservations (one with its test drive credit) and one amount added by hand.
     */
    private function seed(): void
    {
        $driven = $this->createBooking(Experience::Territory, '2026-10-10', Vehicle::Territory, '09:00');
        $this->createBooking(Experience::Territory, '2026-10-10', Vehicle::Territory, '10:00');

        $em = $this->entityManager();
        $now = new \DateTimeImmutable('2026-10-01 10:00');
        $em->persist(FundContribution::forTestDrive($driven, $now, 'commercial@alphaford.tn'));
        $em->persist(FundContribution::manual(150, 'Don sur place', $now, 'commercial@alphaford.tn'));
        $em->flush();
    }
}
