<?php

declare(strict_types=1);

namespace App\Service\TestDrive;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Event\TestDriveApproved;
use App\Service\Reservation\SlotSchedule;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Commercial confirmation that a test drive took place: marks the reservation as
 * driven and dispatches TestDriveApproved, which the cagnotte listens to in order to
 * credit the solidarity fund (amount received from the client + 20 DT Alpha Ford), once.
 *
 * The event is dispatched inside the transaction, so the approval and the credit are
 * committed together; an approval that no listener credited is rolled back.
 *
 * Idempotent: validating an already validated reservation changes nothing and
 * dispatches nothing. The row lock serialises concurrent calls on MySQL, and the unique
 * reservation column of fund_contribution is the last line of defence against a double credit.
 */
final class TestDriveValidator
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly SlotSchedule $schedule,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @throws ReservationNotFoundException
     * @param bool $allowFutureDate admin override: accept a test drive planned for a later day
     * @param int  $clientAmount    amount received from the client at the checkout, in DT
     *
     * @throws TestDriveNotEligibleException when the reservation is cancelled, or for a later day without the override
     */
    public function validate(int $reservationId, string $validatedBy, bool $allowFutureDate = false, int $clientAmount = FundContribution::CLIENT_SHARE): ValidationOutcome
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

            $event = $this->dispatcher->dispatch(new TestDriveApproved($reservation, $now, $validatedBy, $clientAmount));
            if (null === $event->getContribution()) {
                throw new \LogicException(sprintf('Test drive #%d was approved but the cagnotte was not credited.', $reservationId));
            }

            $em->flush();
            $connection->commit();

            return $event->isNewlyCredited() ? ValidationOutcome::Validated : ValidationOutcome::AlreadyValidated;
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

        if (ReservationStatus::Archived === $reservation->getStatus()) {
            throw new TestDriveNotEligibleException('Cette réservation est archivée.');
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
