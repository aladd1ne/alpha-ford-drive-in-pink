<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Enum\Vehicle;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Back-office operations of the reservations team: confirm, reschedule and archive.
 *
 * A slot booking moved to another date / vehicle / slot claims a free seat there, under
 * the same unique index as an online booking; its former seat is freed by the move.
 * "Autres jours d'octobre" requests and walk-ins have no slot: they only get a date
 * and a vehicle.
 */
final class ReservationManager
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ReservationRepository $repository,
        private readonly SlotSchedule $schedule,
    ) {
    }

    /**
     * @throws ReservationChangeException when the booking cannot be confirmed yet
     */
    public function confirm(Reservation $reservation): void
    {
        if (ReservationStatus::Pending !== $reservation->getStatus()) {
            throw new ReservationChangeException(sprintf('Cette réservation est déjà « %s ».', mb_strtolower($reservation->getStatus()->label())));
        }
        if (null === $reservation->getDate()) {
            throw new ReservationChangeException('Attribuez d’abord une date à cette demande.');
        }
        if (null === $reservation->getVehicle() || Vehicle::Advice === $reservation->getVehicle()) {
            throw new ReservationChangeException('Définissez d’abord le véhicule de cette demande.');
        }

        $reservation->confirm();
        $this->flush();
    }

    public function archive(Reservation $reservation): void
    {
        if (ReservationStatus::Archived === $reservation->getStatus()) {
            return;
        }

        $reservation->archive();
        $this->flush();
    }

    /**
     * Applies a new date / vehicle / slot (the contact fields are already on the entity)
     * and saves the reservation.
     *
     * @throws ReservationChangeException with the form field it concerns
     */
    public function update(Reservation $reservation, ?\DateTimeImmutable $date, ?Vehicle $vehicle, ?string $slot): void
    {
        if (!$reservation->getStatus()->isActive()) {
            throw new ReservationChangeException('Une réservation archivée ou annulée ne peut plus être modifiée.');
        }

        $experience = $reservation->getExperience();
        if (null !== $vehicle && !\in_array($vehicle, $experience->vehicles(), true)) {
            throw new ReservationChangeException('Ce véhicule n’est pas proposé pour cette expérience.', 'vehicle');
        }

        if (!$reservation->isSlotBooking()) {
            if (null === $date) {
                throw new ReservationChangeException('Veuillez choisir une date.', 'date');
            }
            $reservation->setDate($date)->setVehicle($vehicle);
            $this->flush();

            return;
        }

        if (null === $date || !$this->schedule->hasDate($experience, $date)) {
            throw new ReservationChangeException('Cette date n’est pas une journée de l’expérience.', 'date');
        }
        if (null === $vehicle) {
            throw new ReservationChangeException('Veuillez choisir un véhicule.', 'vehicle');
        }
        if (null === $slot || !\array_key_exists($slot, $this->schedule->slotsFor($experience, $date))) {
            throw new ReservationChangeException('Ce créneau n’est pas proposé ce jour-là.', 'slot');
        }

        $unchanged = $reservation->getDate()?->format('Y-m-d') === $date->format('Y-m-d')
            && $reservation->getVehicle() === $vehicle
            && $reservation->getSlot() === $slot
            && null !== $reservation->getSeat();
        if ($unchanged) {
            $this->flush();

            return;
        }

        $capacity = $this->schedule->capacity($experience);
        $free = array_values(array_diff(range(1, $capacity), $this->repository->findTakenSeats($experience, $date, $vehicle, $slot)));
        if ([] === $free) {
            throw new ReservationChangeException('Ce créneau est complet pour ce véhicule.', 'slot');
        }

        $reservation->reschedule($date, $vehicle, $slot, $free[0]);

        try {
            $this->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Booked online in the meantime; the failed flush closed the entity manager.
            $this->registry->resetManager();

            throw new ReservationChangeException('Ce créneau vient d’être réservé. Choisissez-en un autre.', 'slot', $e);
        }
    }

    private function flush(): void
    {
        $manager = $this->registry->getManagerForClass(Reservation::class)
            ?? throw new \LogicException('No entity manager for reservations.');
        $manager->flush();
    }
}
