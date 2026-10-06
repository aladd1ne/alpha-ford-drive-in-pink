<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Reservation;
use App\Service\Reservation\SlotAvailability;
use App\Service\Reservation\SlotSchedule;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidReservationValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SlotSchedule $schedule,
        private readonly SlotAvailability $availability,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidReservation) {
            throw new UnexpectedTypeException($constraint, ValidReservation::class);
        }
        if (!$value instanceof Reservation) {
            throw new UnexpectedValueException($value, Reservation::class);
        }

        $experience = $value->getExperience();
        $vehicle = $value->getVehicle();
        $date = $value->getDate();

        if (null === $vehicle) {
            $this->violation($constraint->vehicleRequiredMessage, 'vehicle');
        } elseif (!\in_array($vehicle, $experience->vehicles(), true)) {
            $this->violation($constraint->vehicleMessage, 'vehicle');
        }

        // Added by hand, or already booked and edited in the back-office (where
        // ReservationManager checks date / slot changes): no booking rule applies.
        if (null === $date || $value->isManual() || null !== $value->getId()) {
            return; // a missing date is reported by NotNull
        }

        if (!$experience->hasSlots()) {
            if ($date->format('Y-m-d') < $this->availability->today()->format('Y-m-d')) {
                $this->violation($constraint->pastDateMessage, 'date');
            } elseif (!$this->schedule->isRequestDate($date)) {
                $this->violation($constraint->requestDateMessage, 'date');
            }

            return;
        }

        if (!$this->schedule->hasDate($experience, $date)) {
            $this->violation($constraint->dateMessage, 'date');

            return;
        }

        $slot = $value->getSlot();
        if (null === $slot || '' === $slot) {
            $this->violation($constraint->slotRequiredMessage, 'slot');
        } elseif (!\array_key_exists($slot, $this->schedule->slotsFor($experience, $date))) {
            $this->violation($constraint->slotMessage, 'slot');
        } elseif ($this->availability->hasStarted($date, $slot)) {
            $this->violation($constraint->slotStartedMessage, 'slot');
        } elseif (null !== $vehicle && $this->availability->remaining($experience, $date, $vehicle, $slot) < 1) {
            $this->violation($constraint->slotFullMessage, 'slot');
        }
    }

    private function violation(string $message, string $path): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
