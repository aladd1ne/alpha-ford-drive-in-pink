<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Enum\Experience;
use App\Enum\Vehicle;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;

/**
 * Remaining places per date + vehicle + slot. Each combination is independent:
 * a full slot for one vehicle (or one day) never blocks another.
 */
final class SlotAvailability
{
    public function __construct(
        private readonly SlotSchedule $schedule,
        private readonly ReservationRepository $repository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Bookable slots (not started yet) with their remaining places.
     *
     * @return array<string, array<string, array<string, int>>> "Y-m-d" => vehicle => slot start => remaining
     */
    public function remainingMap(Experience $experience): array
    {
        if (!$experience->hasSlots()) {
            return [];
        }

        $booked = $this->repository->countBookedSeats($experience);
        $capacity = $this->schedule->capacity($experience);
        $map = [];

        foreach ($this->schedule->dates($experience) as $date) {
            foreach (array_keys($this->schedule->slotsFor($experience, $date)) as $slot) {
                if ($this->hasStarted($date, $slot)) {
                    continue;
                }
                foreach ($experience->vehicles() as $vehicle) {
                    $key = ReservationRepository::slotKey($date, $vehicle, $slot);
                    $map[$date->format('Y-m-d')][$vehicle->value][$slot] = max(0, $capacity - ($booked[$key] ?? 0));
                }
            }
        }

        return $map;
    }

    public function remaining(Experience $experience, \DateTimeInterface $date, Vehicle $vehicle, string $slot): int
    {
        return $this->remainingMap($experience)[$date->format('Y-m-d')][$vehicle->value][$slot] ?? 0;
    }

    /**
     * True when no slot is left for any date and vehicle ("Complet").
     */
    public function isFull(Experience $experience): bool
    {
        if (!$experience->hasSlots()) {
            return false;
        }

        foreach ($this->remainingMap($experience) as $vehicles) {
            foreach ($vehicles as $slots) {
                if (max($slots) > 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Pop-up text while bookings are not open yet ("…à partir du 19 octobre."), null once open.
     */
    public function openingMessage(Experience $experience): ?string
    {
        $opensAt = $this->schedule->opensAt($experience);
        if (null === $opensAt || $opensAt <= $this->clock->now()) {
            return null;
        }

        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->schedule->timezone(), null, 'd MMMM');

        return sprintf('Les inscriptions seront ouvertes à partir du %s.', $formatter->format($opensAt));
    }

    /**
     * @return array<string, string> experience slug => opening message, for the experiences not open yet
     */
    public function openingMessages(): array
    {
        $messages = [];
        foreach (Experience::cases() as $experience) {
            if (null !== $message = $this->openingMessage($experience)) {
                $messages[$experience->value] = $message;
            }
        }

        return $messages;
    }

    public function hasStarted(\DateTimeInterface $date, string $slot): bool
    {
        return $this->schedule->slotStartsAt($date, $slot) <= $this->clock->now();
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->schedule->timezone())->setTime(0, 0);
    }
}
