<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Experience;
use App\Enum\ReservationStatus;
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
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_reservation_seat', columns: ['experience', 'reservation_date', 'vehicle', 'slot_start', 'seat'])]
#[ORM\Index(name: 'idx_reservation_experience_date', columns: ['experience', 'reservation_date'])]
#[ORM\Index(name: 'idx_reservation_status', columns: ['status'])]
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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

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
}
