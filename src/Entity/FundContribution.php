<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FundContributionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One credit to the solidarity fund, recorded when a commercial confirms that a
 * test drive took place. It is also the audit trail of that validation (which
 * reservation, when, by whom, how much).
 *
 * An amount added by hand in the back-office (see manual()) has no reservation and
 * carries a note explaining where it comes from.
 *
 * The unique reservation column lets the database refuse a second contribution
 * for the same test drive, whatever happens in the application layer.
 */
#[ORM\Entity(repositoryClass: FundContributionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fund_contribution_reservation', columns: ['reservation_id'])]
class FundContribution
{
    /** Amounts in DT: the client pays 10 (the cashier enters what was actually received), Alpha Ford adds 20. */
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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

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
     * @param int $clientAmount amount actually received from the client at the checkout
     */
    public static function forTestDrive(Reservation $reservation, \DateTimeImmutable $at, string $validatedBy, int $clientAmount = self::CLIENT_SHARE): self
    {
        if ($clientAmount < 0) {
            throw new \InvalidArgumentException('The amount received cannot be negative.');
        }

        return new self($reservation, $clientAmount, self::ALPHA_FORD_SHARE, $validatedBy, $at);
    }

    /**
     * Amount added by hand (donation, cash collected on site...), outside any test drive.
     */
    public static function manual(int $amount, string $note, \DateTimeImmutable $at, string $addedBy): self
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A manual contribution must be positive.');
        }

        $contribution = new self(null, 0, 0, $addedBy, $at);
        $contribution->amount = $amount;
        $contribution->note = trim($note);

        return $contribution;
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

    public function getValidatedBy(): string
    {
        return $this->validatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
