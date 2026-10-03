<?php

declare(strict_types=1);

namespace App\Tests\Service\TestDrive;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Service\Reservation\ReservationBooker;
use App\Service\SolidarityFund;
use App\Service\TestDrive\ReservationNotFoundException;
use App\Service\TestDrive\TestDriveNotEligibleException;
use App\Service\TestDrive\TestDriveValidator;
use App\Service\TestDrive\ValidationOutcome;
use App\Tests\DatabaseTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Today" is 2026-10-01 (MockClock, see config/services.yaml).
 */
final class TestDriveValidatorTest extends KernelTestCase
{
    use DatabaseTrait;

    private const COMMERCIAL = 'commercial@alphaford.tn';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testValidationCompletesTheTestDriveAndAdds30Dt(): void
    {
        $reservation = $this->todayBooking();

        self::assertSame(ValidationOutcome::Validated, $this->validator()->validate($reservation->getId(), self::COMMERCIAL));

        $reservation = $this->reload($reservation);
        self::assertSame(TestDriveStatus::Completed, $reservation->getTestDriveStatus());
        self::assertSame('2026-10-01 08:00', $reservation->getTestDriveCompletedAt()?->format('Y-m-d H:i'));
        self::assertSame(self::COMMERCIAL, $reservation->getTestDriveValidatedBy());
        self::assertSame(ReservationStatus::Confirmed, $reservation->getStatus(), 'A pending booking becomes confirmed.');

        $contributions = $this->contributions();
        self::assertCount(1, $contributions);
        self::assertSame($reservation->getId(), $contributions[0]->getReservation()->getId());
        self::assertSame(10, $contributions[0]->getClientAmount());
        self::assertSame(20, $contributions[0]->getAlphaFordAmount());
        self::assertSame(30, $contributions[0]->getAmount());
        self::assertSame(self::COMMERCIAL, $contributions[0]->getValidatedBy());
        self::assertSame(30, $this->fundTotal());
    }

    public function testDoubleValidationAddsTheAmountOnlyOnce(): void
    {
        $reservation = $this->todayBooking();

        self::assertSame(ValidationOutcome::Validated, $this->validator()->validate($reservation->getId(), self::COMMERCIAL));
        self::assertSame(ValidationOutcome::AlreadyValidated, $this->validator()->validate($reservation->getId(), 'other@alphaford.tn'));

        self::assertCount(1, $this->contributions());
        self::assertSame(30, $this->fundTotal());
        self::assertSame(self::COMMERCIAL, $this->reload($reservation)->getTestDriveValidatedBy(), 'The first validation is kept.');
    }

    public function testAlreadyValidatedReservationIsLeftUnchanged(): void
    {
        $reservation = $this->todayBooking();
        $reservation->markTestDriveCompleted(new \DateTimeImmutable('2026-10-01 07:00:00'), 'earlier@alphaford.tn');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        self::assertSame(ValidationOutcome::AlreadyValidated, $this->validator()->validate($reservation->getId(), self::COMMERCIAL));
        self::assertCount(0, $this->contributions());
        self::assertSame('earlier@alphaford.tn', $this->reload($reservation)->getTestDriveValidatedBy());
    }

    public function testUnknownReservationIsRejected(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        $this->validator()->validate(999, self::COMMERCIAL);
    }

    public function testCancelledReservationIsNotEligible(): void
    {
        $reservation = $this->todayBooking();
        $this->entityManager()->createQuery('UPDATE App\Entity\Reservation r SET r.status = :cancelled WHERE r.id = :id')
            ->setParameter('cancelled', ReservationStatus::Cancelled)
            ->setParameter('id', $reservation->getId())
            ->execute();

        try {
            $this->validator()->validate($reservation->getId(), self::COMMERCIAL);
            self::fail('A cancelled reservation must not be validated.');
        } catch (TestDriveNotEligibleException) {
        }

        self::assertCount(0, $this->contributions());
        self::assertSame(TestDriveStatus::ToValidate, $this->reload($reservation)->getTestDriveStatus());
    }

    public function testFutureTestDriveIsNotEligible(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');

        try {
            $this->validator()->validate($reservation->getId(), self::COMMERCIAL);
            self::fail('A test drive planned for a later day must not be validated.');
        } catch (TestDriveNotEligibleException) {
        }

        self::assertCount(0, $this->contributions());
    }

    public function testAdminOverrideValidatesAFutureTestDrive(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');

        self::assertSame(ValidationOutcome::Validated, $this->validator()->validate($reservation->getId(), self::COMMERCIAL, allowFutureDate: true));
        self::assertSame(ValidationOutcome::AlreadyValidated, $this->validator()->validate($reservation->getId(), self::COMMERCIAL, allowFutureDate: true));
        self::assertCount(1, $this->contributions());
    }

    public function testAdminOverrideStillRefusesACancelledReservation(): void
    {
        $reservation = $this->createBooking(Experience::EverestRanger, '2026-10-09', Vehicle::RangerRaptor, '09:00');
        $this->entityManager()->createQuery('UPDATE App\Entity\Reservation r SET r.status = :cancelled WHERE r.id = :id')
            ->setParameter('cancelled', ReservationStatus::Cancelled)
            ->setParameter('id', $reservation->getId())
            ->execute();

        $this->expectException(TestDriveNotEligibleException::class);
        $this->validator()->validate($reservation->getId(), self::COMMERCIAL, allowFutureDate: true);
    }

    public function testPastTestDriveCanStillBeValidated(): void
    {
        $reservation = $this->createBooking(Experience::Territory, '2026-09-30', Vehicle::Territory, '09:00');

        self::assertSame(ValidationOutcome::Validated, $this->validator()->validate($reservation->getId(), self::COMMERCIAL));
    }

    public function testBookingAloneAddsNothingToTheFund(): void
    {
        $reservation = (new Reservation(Experience::EverestRanger))
            ->setFullName('Client Test')
            ->setPhone('+216 20 000 000')
            ->setEmail('client@example.com')
            ->setDate(new \DateTimeImmutable('2026-10-09'))
            ->setVehicle(Vehicle::RangerRaptor)
            ->setSlot('09:00');

        static::getContainer()->get(ReservationBooker::class)->book($reservation);

        self::assertSame(TestDriveStatus::ToValidate, $reservation->getTestDriveStatus());
        self::assertCount(0, $this->contributions());
        self::assertSame(0, $this->fundTotal());
    }

    public function testDatabaseRefusesASecondContributionForTheSameReservation(): void
    {
        $reservation = $this->todayBooking();
        $em = $this->entityManager();
        $em->persist(FundContribution::forTestDrive($reservation, new \DateTimeImmutable(), self::COMMERCIAL));
        $em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $em->persist(FundContribution::forTestDrive($reservation, new \DateTimeImmutable(), self::COMMERCIAL));
        $em->flush();
    }

    public function testFundTotalAddsValidatedTestDrivesToTheOpeningAmount(): void
    {
        $first = $this->todayBooking('09:00');
        $second = $this->todayBooking('09:45');
        $this->validator()->validate($first->getId(), self::COMMERCIAL);
        $this->validator()->validate($second->getId(), self::COMMERCIAL);
        $this->validator()->validate($second->getId(), self::COMMERCIAL);

        $opening = (int) static::getContainer()->getParameter('app.solidarity_fund_amount');
        self::assertSame($opening + 60, static::getContainer()->get(SolidarityFund::class)->total());
    }

    public function testEligibilityRuleUsedByTheLists(): void
    {
        $today = new \DateTimeImmutable('2026-10-01');
        $reservation = $this->todayBooking();

        self::assertTrue($reservation->canValidateTestDrive($today));
        self::assertTrue($reservation->canValidateTestDrive(new \DateTimeImmutable('2026-10-02')), 'A past test drive can still be validated.');
        self::assertFalse($reservation->canValidateTestDrive(new \DateTimeImmutable('2026-09-30')), 'Not before its day.');
        self::assertTrue($reservation->canValidateTestDrive(new \DateTimeImmutable('2026-09-30'), allowFutureDate: true), 'Unless admin override.');

        $reservation->markTestDriveCompleted(new \DateTimeImmutable(), self::COMMERCIAL);
        self::assertFalse($reservation->canValidateTestDrive($today), 'Not twice.');
    }

    private function todayBooking(string $slot = '09:00'): Reservation
    {
        return $this->createBooking(Experience::Territory, '2026-10-01', Vehicle::Territory, $slot);
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
        return $this->entityManager()->getRepository(FundContribution::class)->findAll();
    }

    /**
     * Amount credited by validated test drives (fund total minus the opening amount).
     */
    private function fundTotal(): int
    {
        return static::getContainer()->get(SolidarityFund::class)->total()
            - (int) static::getContainer()->getParameter('app.solidarity_fund_amount');
    }

    private function validator(): TestDriveValidator
    {
        return static::getContainer()->get(TestDriveValidator::class);
    }
}
