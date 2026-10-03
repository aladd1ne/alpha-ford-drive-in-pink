<?php

namespace App\Entity;

use App\Repository\IncludedItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IncludedItemRepository::class)]
class IncludedItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'includedItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Category $category = null;

    #[ORM\Column(length: 100)]
    private string $icon = 'check-circle';

    #[ORM\Column(length: 255)]
    private string $label = '';

    public function getId(): ?int { return $this->id; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $icon): static { $this->icon = $icon; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }
}
