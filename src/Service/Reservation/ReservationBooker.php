<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Saves a reservation. Slot-based bookings claim a free seat (1..capacity); the
 * unique index on experience + date + vehicle + slot + seat lets the database reject
 * a concurrent booking of the same seat, in which case the next free seat is tried.
 */
final class ReservationBooker
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ReservationRepository $repository,
        private readonly SlotSchedule $schedule,
    ) {
    }

    /**
     * @throws SlotUnavailableException when the slot has no seat left
     */
    public function book(Reservation $reservation): void
    {
        $experience = $reservation->getExperience();

        if (!$experience->hasSlots()) {
            $this->save($reservation);

            return;
        }

        $date = $reservation->getDate();
        $vehicle = $reservation->getVehicle();
        $slot = $reservation->getSlot();
        if (null === $date || null === $vehicle || null === $slot) {
            throw new \LogicException('A slot booking needs a date, a vehicle and a slot.');
        }

        $capacity = $this->schedule->capacity($experience);

        for ($attempt = 0; $attempt < $capacity; ++$attempt) {
            $taken = $this->repository->findTakenSeats($experience, $date, $vehicle, $slot);
            $free = array_values(array_diff(range(1, $capacity), $taken));
            if ([] === $free) {
                break;
            }

            $reservation->assignSeat($free[0]);

            try {
                $this->save($reservation);

                return;
            } catch (UniqueConstraintViolationException) {
                // Someone took this seat in the meantime: the failed flush closed the
                // entity manager, so reset it and retry with the next free seat.
                $this->registry->resetManager();
            }
        }

        throw new SlotUnavailableException();
    }

    private function save(Reservation $reservation): void
    {
        $manager = $this->registry->getManagerForClass(Reservation::class)
            ?? throw new \LogicException('No entity manager for reservations.');

        $manager->persist($reservation);
        $manager->flush();
    }
}
