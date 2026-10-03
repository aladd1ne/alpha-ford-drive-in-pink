<?php

declare(strict_types=1);

namespace App\Service\TestDrive;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Service\Reservation\SlotSchedule;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * Commercial confirmation that a test drive took place: marks the reservation as
 * driven and credits the solidarity fund (10 DT client + 20 DT Alpha Ford), once.
 *
 * Idempotent: validating an already validated reservation changes nothing. The row
 * lock serialises concurrent calls on MySQL, and the unique reservation column of
 * fund_contribution is the last line of defence against a double credit.
 */
final class TestDriveValidator
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly SlotSchedule $schedule,
    ) {
    }

    /**
     * @throws ReservationNotFoundException
     * @param bool $allowFutureDate admin override: accept a test drive planned for a later day
     *
     * @throws TestDriveNotEligibleException when the reservation is cancelled, or for a later day without the override
     */
    public function validate(int $reservationId, string $validatedBy, bool $allowFutureDate = false): ValidationOutcome
    {
        $em = $this->manager();
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $reservation = $em->find(Reservation::class, $reservationId, LockMode::PESSIMISTIC_WRITE)
                ?? throw new ReservationNotFoundException($reservationId);

            // A copy loaded earlier by this entity manager may be stale.
            $em->refresh($reservation);

            if ($reservation->isTestDriveCompleted()) {
                $connection->commit();

                return ValidationOutcome::AlreadyValidated;
            }

            $this->assertEligible($reservation, $allowFutureDate);

            $now = $this->clock->now();
            $reservation->markTestDriveCompleted($now, $validatedBy);
            $em->persist(FundContribution::forTestDrive($reservation, $now, $validatedBy));
            $em->flush();
            $connection->commit();

            return ValidationOutcome::Validated;
        } catch (UniqueConstraintViolationException) {
            // A concurrent request credited this reservation first; the failed flush
            // closed the entity manager.
            $connection->rollBack();
            $this->registry->resetManager();

            return ValidationOutcome::AlreadyValidated;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    private function assertEligible(Reservation $reservation, bool $allowFutureDate): void
    {
        if (ReservationStatus::Cancelled === $reservation->getStatus()) {
            throw new TestDriveNotEligibleException('Cette réservation est annulée.');
        }

        if (null === $reservation->getDate()) {
            throw new TestDriveNotEligibleException('Cette réservation n’a pas de date.');
        }

        $today = $this->schedule->dayOf($this->clock->now())->format('Y-m-d');
        if (!$allowFutureDate && $reservation->getDate()->format('Y-m-d') > $today) {
            throw new TestDriveNotEligibleException('Ce test drive est prévu pour une date ultérieure.');
        }
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Reservation::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for reservations.');
        }

        return $manager;
    }
}
