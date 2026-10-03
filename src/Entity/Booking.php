<?php

namespace App\Entity;

use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Category $category = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre nom.')]
    #[Assert\Length(min: 2, minMessage: 'Le nom doit contenir au moins {{ limit }} caractères.')]
    private string $customerName = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre numéro de téléphone.')]
    private string $phone = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre adresse e-mail.')]
    #[Assert\Email(message: 'Adresse e-mail invalide.')]
    private string $email = '';

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 50, notInRangeMessage: 'Le nombre de personnes doit être entre {{ min }} et {{ max }}.')]
    private int $peopleCount = 1;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'Veuillez choisir une date.')]
    #[Assert\GreaterThanOrEqual('today', message: 'La date doit être dans le futur.')]
    private ?\DateTimeInterface $preferredDate = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $selectedExtras = [];

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $totalPrice = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $depositAmount = '50.00';

    #[ORM\Column(length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\PrePersist]
    public function initDefaults(): void
    {
        $this->createdAt ??= new \DateTime();
        if ($this->reference === '') {
            $this->reference = 'AND-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getCustomerName(): string { return $this->customerName; }
    public function setCustomerName(string $customerName): static { $this->customerName = $customerName; return $this; }

    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $phone): static { $this->phone = $phone; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getPeopleCount(): int { return $this->peopleCount; }
    public function setPeopleCount(int $peopleCount): static { $this->peopleCount = $peopleCount; return $this; }

    public function getPreferredDate(): ?\DateTimeInterface { return $this->preferredDate; }
    public function setPreferredDate(?\DateTimeInterface $preferredDate): static { $this->preferredDate = $preferredDate; return $this; }

    public function getSelectedExtras(): array { return $this->selectedExtras; }
    public function setSelectedExtras(array $selectedExtras): static { $this->selectedExtras = $selectedExtras; return $this; }

    public function getTotalPrice(): string { return $this->totalPrice; }
    public function setTotalPrice(string $totalPrice): static { $this->totalPrice = $totalPrice; return $this; }

    public function getDepositAmount(): string { return $this->depositAmount; }
    public function setDepositAmount(string $depositAmount): static { $this->depositAmount = $depositAmount; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $archived): static { $this->archived = $archived; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function __toString(): string { return $this->reference; }
}
