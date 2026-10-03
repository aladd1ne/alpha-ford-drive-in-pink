<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    private string $teaser = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /** URL or bare filename inside public/images/categories/ */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $heroImage = null;

    /** URL or bare filename inside public/images/categories/ */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $panelImage = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $basePrice = '0.00';

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    /** @var Collection<int, Highlight> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Highlight::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $highlights;

    /** @var Collection<int, Extra> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Extra::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $extras;

    /** @var Collection<int, IncludedItem> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: IncludedItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $includedItems;

    /** @var Collection<int, BringItem> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: BringItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $bringItems;

    /** @var Collection<int, Booking> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Booking::class)]
    private Collection $bookings;

    public function __construct()
    {
        $this->highlights    = new ArrayCollection();
        $this->extras        = new ArrayCollection();
        $this->includedItems = new ArrayCollection();
        $this->bringItems    = new ArrayCollection();
        $this->bookings      = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getTeaser(): string { return $this->teaser; }
    public function setTeaser(string $teaser): static { $this->teaser = $teaser; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }

    public function getHeroImage(): ?string { return $this->heroImage; }
    public function setHeroImage(?string $heroImage): static { $this->heroImage = $heroImage ?: null; return $this; }

    public function getPanelImage(): ?string { return $this->panelImage; }
    public function setPanelImage(?string $panelImage): static { $this->panelImage = $panelImage ?: null; return $this; }

    public function getBasePrice(): string { return $this->basePrice; }
    public function setBasePrice(string $basePrice): static { $this->basePrice = $basePrice; return $this; }

    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $archived): static { $this->archived = $archived; return $this; }

    public function __toString(): string { return $this->name; }

    /** @return Collection<int, Highlight> */
    public function getHighlights(): Collection { return $this->highlights; }

    public function addHighlight(Highlight $highlight): static
    {
        if (!$this->highlights->contains($highlight)) {
            $this->highlights->add($highlight);
            $highlight->setCategory($this);
        }
        return $this;
    }

    public function removeHighlight(Highlight $highlight): static
    {
        if ($this->highlights->removeElement($highlight) && $highlight->getCategory() === $this) {
            $highlight->setCategory(null);
        }
        return $this;
    }

    /** @return Collection<int, Extra> */
    public function getExtras(): Collection { return $this->extras; }

    public function addExtra(Extra $extra): static
    {
        if (!$this->extras->contains($extra)) {
            $this->extras->add($extra);
            $extra->setCategory($this);
        }
        return $this;
    }

    public function removeExtra(Extra $extra): static
    {
        if ($this->extras->removeElement($extra) && $extra->getCategory() === $this) {
            $extra->setCategory(null);
        }
        return $this;
    }

    /** @return Collection<int, IncludedItem> */
    public function getIncludedItems(): Collection { return $this->includedItems; }

    public function addIncludedItem(IncludedItem $item): static
    {
        if (!$this->includedItems->contains($item)) {
            $this->includedItems->add($item);
            $item->setCategory($this);
        }
        return $this;
    }

    public function removeIncludedItem(IncludedItem $item): static
    {
        if ($this->includedItems->removeElement($item) && $item->getCategory() === $this) {
            $item->setCategory(null);
        }
        return $this;
    }

    /** @return Collection<int, BringItem> */
    public function getBringItems(): Collection { return $this->bringItems; }

    public function addBringItem(BringItem $item): static
    {
        if (!$this->bringItems->contains($item)) {
            $this->bringItems->add($item);
            $item->setCategory($this);
        }
        return $this;
    }

    public function removeBringItem(BringItem $item): static
    {
        if ($this->bringItems->removeElement($item) && $item->getCategory() === $this) {
            $item->setCategory(null);
        }
        return $this;
    }

    /** @return Collection<int, Booking> */
    public function getBookings(): Collection { return $this->bookings; }
}
