<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContributionSource;
use App\Repository\FundContributionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One credit to the solidarity fund, recorded when a commercial confirms that a
 * test drive took place. It is also the audit trail of that validation (which
 * reservation, when, by whom, how much).
 *
 * An amount added by hand in the back-office has no reservation: it is a donation made by
 * someone who registered on the site (see donation(), the donor), or the sale of products
 * for the cause (see productSale(), the amount is the product price times the quantity),
 * with an optional note. Older ones have a note only (see manual()).
 *
 * An admin can correct the amounts afterwards (see correctTestDrive() and correctManual()):
 * the last correction is kept (who, when, why) next to the original validation.
 *
 * The unique reservation column lets the database refuse a second contribution
 * for the same test drive, whatever happens in the application layer.
 */
#[ORM\Entity(repositoryClass: FundContributionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fund_contribution_reservation', columns: ['reservation_id'])]
class FundContribution
{
    /** Amounts in DT, fixed for every cashed-in test drive: the client pays 10, Alpha Ford adds 20. */
    public const CLIENT_SHARE = 10;
    public const ALPHA_FORD_SHARE = 20;
    public const TEST_DRIVE_AMOUNT = self::CLIENT_SHARE + self::ALPHA_FORD_SHARE;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $reservation;

    #[ORM\Column]
    private int $clientAmount;

    #[ORM\Column]
    private int $alphaFordAmount;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 180)]
    private string $validatedBy;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ContributionSource::class)]
    private ?ContributionSource $source = null;

    /** Registration of the person who made the donation. */
    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $donor = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $correctedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $correctedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $correctionReason = null;

    private function __construct(?Reservation $reservation, int $clientAmount, int $alphaFordAmount, string $validatedBy, \DateTimeImmutable $createdAt)
    {
        $this->reservation = $reservation;
        $this->clientAmount = $clientAmount;
        $this->alphaFordAmount = $alphaFordAmount;
        $this->amount = $clientAmount + $alphaFordAmount;
        $this->validatedBy = $validatedBy;
        $this->createdAt = $createdAt;
    }

    /**
     * Cashed-in test drive: always the fixed CLIENT_SHARE + ALPHA_FORD_SHARE (an admin can
     * correct it afterwards, see correctTestDrive()).
     */
    public static function forTestDrive(Reservation $reservation, \DateTimeImmutable $at, string $validatedBy): self
    {
        return new self($reservation, self::CLIENT_SHARE, self::ALPHA_FORD_SHARE, $validatedBy, $at);
    }

    /**
     * Amount added by hand (donation, purchase of a product for the cause...), outside any test drive.
     */
    public static function manual(int $amount, ?string $note, \DateTimeImmutable $at, string $addedBy, ?ContributionSource $source = null): self
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A manual contribution must be positive.');
        }

        $contribution = new self(null, 0, 0, $addedBy, $at);
        $contribution->amount = $amount;
        $note = trim((string) $note);
        $contribution->note = '' === $note ? null : $note;
        $contribution->source = $source;

        return $contribution;
    }

    /**
     * Donation from someone who registered on the site.
     */
    public static function donation(Reservation $donor, int $amount, ?string $note, \DateTimeImmutable $at, string $addedBy): self
    {
        $contribution = self::manual($amount, $note, $at, $addedBy, ContributionSource::Donation);
        $contribution->donor = $donor;

        return $contribution;
    }

    /**
     * Sale of products for the cause: the amount is the current product price times the quantity.
     */
    public static function productSale(Product $product, int $quantity, ?string $note, \DateTimeImmutable $at, string $addedBy): self
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('The quantity must be positive.');
        }

        $contribution = self::manual((int) $product->getPrice() * $quantity, $note, $at, $addedBy, ContributionSource::Product);
        $contribution->product = $product;
        $contribution->quantity = $quantity;

        return $contribution;
    }

    /**
     * Admin correction of a test drive credit: both what was received from the client and
     * what Alpha Ford gives can be fixed, the total follows.
     */
    public function correctTestDrive(int $clientAmount, int $alphaFordAmount, ?string $reason, \DateTimeImmutable $at, string $correctedBy): void
    {
        if ($this->isManual()) {
            throw new \LogicException('A manual contribution has no client / Alpha Ford split.');
        }
        if ($clientAmount < 0 || $alphaFordAmount < 0) {
            throw new \InvalidArgumentException('The amounts cannot be negative.');
        }

        $this->clientAmount = $clientAmount;
        $this->alphaFordAmount = $alphaFordAmount;
        $this->amount = $clientAmount + $alphaFordAmount;
        $this->recordCorrection($reason, $at, $correctedBy);
    }

    /**
     * Admin correction of an amount added by hand.
     */
    public function correctManual(int $amount, ?string $reason, \DateTimeImmutable $at, string $correctedBy): void
    {
        if (!$this->isManual()) {
            throw new \LogicException('A test drive credit is corrected with correctTestDrive().');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A manual contribution must be positive.');
        }

        $this->amount = $amount;
        $this->recordCorrection($reason, $at, $correctedBy);
    }

    /**
     * @param string|null $reason optional, null or blank when none was given
     */
    private function recordCorrection(?string $reason, \DateTimeImmutable $at, string $correctedBy): void
    {
        $reason = trim((string) $reason);
        $this->correctionReason = '' === $reason ? null : $reason;
        $this->correctedAt = $at;
        $this->correctedBy = $correctedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function getClientAmount(): int
    {
        return $this->clientAmount;
    }

    public function getAlphaFordAmount(): int
    {
        return $this->alphaFordAmount;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function isManual(): bool
    {
        return null === $this->reservation;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getSource(): ?ContributionSource
    {
        return $this->source;
    }

    public function getDonor(): ?Reservation
    {
        return $this->donor;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    /**
     * Where the amount comes from, as shown in the back-office: "Test drive : <client>",
     * "Don : <donor name> (<e-mail>) : <note>", "Achat d’un produit : <product> × <quantity> : <note>"
     * or the note alone (older manual amounts).
     */
    public function origin(): string
    {
        if (!$this->isManual()) {
            return sprintf('Test drive : %s', $this->reservation?->getFullName());
        }

        $detail = match (true) {
            null !== $this->donor => sprintf('%s (%s)', $this->donor->getFullName(), $this->donor->getEmail()),
            null !== $this->product => sprintf('%s × %d', $this->product->getName(), $this->quantity),
            default => null,
        };

        return implode(' : ', array_filter([$this->source?->label(), $detail, $this->note], static fn (?string $part): bool => null !== $part && '' !== $part));
    }

    public function getValidatedBy(): string
    {
        return $this->validatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isCorrected(): bool
    {
        return null !== $this->correctedAt;
    }

    public function getCorrectedBy(): ?string
    {
        return $this->correctedBy;
    }

    public function getCorrectedAt(): ?\DateTimeImmutable
    {
        return $this->correctedAt;
    }

    public function getCorrectionReason(): ?string
    {
        return $this->correctionReason;
    }
}
