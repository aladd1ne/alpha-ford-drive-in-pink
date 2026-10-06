<?php

namespace App\Entity;

use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public const ROLE_ADMIN = 'ROLE_ADMIN';
    /** Back-office access, implied by the staff roles below (see security.yaml). */
    public const ROLE_COMMERCIAL = 'ROLE_COMMERCIAL';
    public const ROLE_RESERVATIONS = 'ROLE_RESERVATIONS';
    public const ROLE_CASHIER = 'ROLE_CASHIER';

    /** Staff roles an admin can give, with their labels. */
    public const STAFF_ROLES = [
        self::ROLE_RESERVATIONS => 'Gestion des réservations',
        self::ROLE_CASHIER => 'Encaissement',
    ];
    public const MIN_PASSWORD_LENGTH = 8;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir une adresse e-mail.'),
        new Assert\Email(message: 'Adresse e-mail invalide.'),
    ])]
    private string $email = '';

    /** New password typed in the back-office form; hashed into $password, never stored. */
    private ?string $plainPassword = null;

    #[ORM\Column(length: 255)]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\PrePersist]
    public function initCreatedAt(): void
    {
        $this->createdAt ??= new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = mb_strtolower(trim((string) $email)); return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }
    /** @param list<string> $roles */
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function getPlainPassword(): ?string { return $this->plainPassword; }
    public function setPlainPassword(?string $plainPassword): static { $this->plainPassword = $plainPassword; return $this; }

    /**
     * Staff account (reservations and/or cashier, or the former commercial role) without any admin role.
     */
    public function isCommercialOnly(): bool
    {
        return [] !== array_intersect([self::ROLE_COMMERCIAL, ...array_keys(self::STAFF_ROLES)], $this->roles)
            && !\in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    /**
     * The staff roles held (form field of the back-office user page).
     *
     * @return list<string>
     */
    public function getStaffRoles(): array
    {
        return array_values(array_intersect($this->roles, array_keys(self::STAFF_ROLES)));
    }

    /**
     * @param list<string> $staffRoles
     */
    public function setStaffRoles(array $staffRoles): static
    {
        $this->roles = array_values(array_intersect($staffRoles, array_keys(self::STAFF_ROLES)));

        return $this;
    }

    public function eraseCredentials(): void { $this->plainPassword = null; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
