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
 * The unique reservation column lets the database refuse a second contribution
 * for the same test drive, whatever happens in the application layer.
 */
#[ORM\Entity(repositoryClass: FundContributionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fund_contribution_reservation', columns: ['reservation_id'])]
class FundContribution
{
    /** Amounts in DT: the client pays 10, Alpha Ford adds 20. */
    public const CLIENT_SHARE = 10;
    public const ALPHA_FORD_SHARE = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Reservation $reservation;

    #[ORM\Column]
    private int $clientAmount;

    #[ORM\Column]
    private int $alphaFordAmount;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 180)]
    private string $validatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Reservation $reservation, int $clientAmount, int $alphaFordAmount, string $validatedBy, \DateTimeImmutable $createdAt)
    {
        $this->reservation = $reservation;
        $this->clientAmount = $clientAmount;
        $this->alphaFordAmount = $alphaFordAmount;
        $this->amount = $clientAmount + $alphaFordAmount;
        $this->validatedBy = $validatedBy;
        $this->createdAt = $createdAt;
    }

    public static function forTestDrive(Reservation $reservation, \DateTimeImmutable $at, string $validatedBy): self
    {
        return new self($reservation, self::CLIENT_SHARE, self::ALPHA_FORD_SHARE, $validatedBy, $at);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
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

    public function getValidatedBy(): string
    {
        return $this->validatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
