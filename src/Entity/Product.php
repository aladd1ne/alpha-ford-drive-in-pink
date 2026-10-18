<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A product sold for the cause (t-shirt, cap...), managed by admins. Selling one in the
 * back-office credits the cagnotte with its price times the quantity (see
 * FundContribution::productSale()); the amount is fixed at the time of the sale, so a later
 * price change does not touch the cagnotte history.
 *
 * A product already sold cannot be deleted, only deactivated (it then no longer appears
 * in the list of products to sell).
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'Un produit porte déjà ce nom.')]
class Product
{
    public const MAX_PRICE = 100000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir le nom du produit.'),
        new Assert\Length(max: 120),
    ])]
    private string $name = '';

    /** Unit price in DT. */
    #[ORM\Column]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir le prix du produit.'),
        new Assert\Range(min: 1, max: self::MAX_PRICE, notInRangeMessage: 'Le prix doit être compris entre {{ min }} et {{ max }} DT.'),
    ])]
    private ?int $price = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?\DateTimeImmutable $createdAt = null)
    {
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
