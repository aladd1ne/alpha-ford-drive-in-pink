<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Experience;
use App\Enum\ReservationStatus;
use App\Enum\TestDriveStatus;
use App\Enum\Vehicle;
use App\Repository\ReservationRepository;
use App\Validator\ValidReservation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A test drive request for one of the Drive in Pink journeys.
 *
 * Slot-based bookings hold a "seat" (1..capacity) for their date + vehicle + slot.
 * The unique constraint on that tuple is what prevents concurrent double bookings;
 * a request without a guaranteed slot (October) or a released booking has a null seat.
 *
 * A test drive added by hand in the back-office (a walk-in) has no slot nor seat and
 * records who added it (createdBy); see createManual().
 *
 * The booking status (status) and the test drive status (testDriveStatus) are separate
 * columns. Confirming that the drive took place completes the test drive, confirms a
 * pending booking, and credits the solidarity fund (see FundContribution).
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_reservation_seat', columns: ['experience', 'reservation_date', 'vehicle', 'slot_start', 'seat'])]
#[ORM\Index(name: 'idx_reservation_experience_date', columns: ['experience', 'reservation_date'])]
#[ORM\Index(name: 'idx_reservation_status', columns: ['status'])]
#[ORM\Index(name: 'idx_reservation_test_drive_status', columns: ['test_drive_status'])]
#[ValidReservation]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: Experience::class)]
    private Experience $experience;

    #[ORM\Column(length: 120)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir votre nom et prénom.'),
        new Assert\Length(min: 3, max: 120, minMessage: 'Le nom doit contenir au moins {{ limit }} caractères.', maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.'),
    ])]
    private string $fullName = '';

    #[ORM\Column(length: 30)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir votre numéro de téléphone.'),
        new Assert\Regex(pattern: self::PHONE_PATTERN, message: 'Numéro de téléphone invalide.'),
    ])]
    private string $phone = '';

    #[ORM\Column(length: 180)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir votre adresse e-mail.'),
        new Assert\Length(max: 180),
        new Assert\Email(message: 'Adresse e-mail invalide.'),
    ])]
    private string $email = '';

    #[ORM\Column(name: 'reservation_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Veuillez choisir une date.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 30, nullable: true, enumType: Vehicle::class)]
    private ?Vehicle $vehicle = null;

    /** Slot start time ("09:00"); null for requests without a slot. */
    #[ORM\Column(name: 'slot_start', length: 5, nullable: true)]
    private ?string $slot = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $seat = null;

    #[ORM\Column(length: 20, enumType: ReservationStatus::class)]
    private ReservationStatus $status = ReservationStatus::Pending;

    #[ORM\Column(length: 20, enumType: TestDriveStatus::class, options: ['default' => 'to_validate'])]
    private TestDriveStatus $testDriveStatus = TestDriveStatus::ToValidate;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $testDriveCompletedAt = null;

    /** E-mail of the commercial who confirmed the test drive. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $testDriveValidatedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** E-mail of the back-office user who added this test drive by hand; null for online bookings. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy = null;

    /** 8 to 15 digits, optional leading "+", spaces, dots or dashes allowed. */
    public const PHONE_PATTERN = '/^\+?(?:[\s.\-]?\d){8,15}$/';

    public function __construct(Experience $experience, ?\DateTimeImmutable $createdAt = null)
    {
        $this->experience = $experience;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();

        // Single-model experiences (Territory) don't ask for the vehicle.
        if (!$experience->hasVehicleChoice()) {
            $this->vehicle = $experience->vehicles()[0];
        }
    }

    /**
     * Test drive added by hand in the back-office for the given day (no slot, no seat):
     * the online booking rules (event dates, slots, capacity) do not apply to it.
     */
    public static function createManual(Experience $experience, \DateTimeImmutable $date, string $createdBy, ?\DateTimeImmutable $createdAt = null): self
    {
        $reservation = new self($experience, $createdAt);
        $reservation->date = $date;
        $reservation->createdBy = $createdBy;

        return $reservation;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExperience(): Experience
    {
        return $this->experience;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = trim((string) $fullName);

        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = trim((string) $phone);

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string) $email));

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(?Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getSlot(): ?string
    {
        return $this->slot;
    }

    public function setSlot(?string $slot): static
    {
        $this->slot = $slot;

        return $this;
    }

    public function getSeat(): ?int
    {
        return $this->seat;
    }

    public function assignSeat(int $seat): static
    {
        $this->seat = $seat;

        return $this;
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function isManual(): bool
    {
        return null !== $this->createdBy;
    }

    public function getTestDriveStatus(): TestDriveStatus
    {
        return $this->testDriveStatus;
    }

    public function isTestDriveCompleted(): bool
    {
        return TestDriveStatus::Completed === $this->testDriveStatus;
    }

    public function getTestDriveCompletedAt(): ?\DateTimeImmutable
    {
        return $this->testDriveCompletedAt;
    }

    public function getTestDriveValidatedBy(): ?string
    {
        return $this->testDriveValidatedBy;
    }

    /**
     * Whether this test drive may be confirmed on the given (event-local) day: not yet
     * validated, not cancelled or archived, and not planned for a later day unless $allowFutureDate
     * (admin override).
     */
    public function canValidateTestDrive(\DateTimeInterface $today, bool $allowFutureDate = false): bool
    {
        return !$this->isTestDriveCompleted()
            && $this->status->isActive()
            && null !== $this->date
            && ($allowFutureDate || $this->date->format('Y-m-d') <= $today->format('Y-m-d'));
    }

    /**
     * Slot booking of an event day: its date, vehicle and slot hold a seat. Walk-ins and
     * "autres jours d'octobre" requests only have a date (and a vehicle).
     */
    public function isSlotBooking(): bool
    {
        return $this->experience->hasSlots() && !$this->isManual();
    }

    public function confirm(): static
    {
        if (ReservationStatus::Pending !== $this->status) {
            throw new \LogicException('Only a pending reservation can be confirmed.');
        }

        $this->status = ReservationStatus::Confirmed;

        return $this;
    }

    /**
     * Hides the reservation from the active lists and frees its seat for a new booking.
     */
    public function archive(): static
    {
        $this->status = ReservationStatus::Archived;
        $this->seat = null;

        return $this;
    }

    /**
     * Moves a slot booking to another date / vehicle / slot, on the given free seat.
     */
    public function reschedule(\DateTimeImmutable $date, Vehicle $vehicle, string $slot, int $seat): static
    {
        $this->date = $date;
        $this->vehicle = $vehicle;
        $this->slot = $slot;
        $this->seat = $seat;

        return $this;
    }

    public function markTestDriveCompleted(\DateTimeImmutable $at, string $validatedBy): static
    {
        if ($this->isTestDriveCompleted()) {
            throw new \LogicException('This test drive is already validated.');
        }

        $this->testDriveStatus = TestDriveStatus::Completed;
        $this->testDriveCompletedAt = $at;
        $this->testDriveValidatedBy = $validatedBy;

        // The customer came: a booking still awaiting confirmation is now confirmed.
        if (ReservationStatus::Pending === $this->status) {
            $this->status = ReservationStatus::Confirmed;
        }

        return $this;
    }
}
