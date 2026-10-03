<?php

namespace App\Entity;

use App\Repository\ExtraRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExtraRepository::class)]
class Extra
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'extras')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Category $category = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price = '0.00';

    public function getId(): ?int { return $this->id; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }
}
