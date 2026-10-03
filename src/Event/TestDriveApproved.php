<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\FundContribution;
use App\Entity\Reservation;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by TestDriveValidator when a commercial or an admin confirms that a test
 * drive took place, once per reservation (an already validated one dispatches nothing).
 *
 * It is dispatched inside the validation transaction, before the flush: whatever the
 * listeners persist is committed together with the approval, or not at all. The cagnotte
 * listener (CreditFundOnTestDriveApproved) records the contribution it credited here.
 */
final class TestDriveApproved extends Event
{
    private ?FundContribution $contribution = null;
    private bool $newlyCredited = false;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly \DateTimeImmutable $approvedAt,
        public readonly string $approvedBy,
    ) {
    }

    /**
     * @param bool $newlyCredited false when the reservation already had a contribution
     */
    public function setContribution(FundContribution $contribution, bool $newlyCredited): void
    {
        $this->contribution = $contribution;
        $this->newlyCredited = $newlyCredited;
    }

    public function getContribution(): ?FundContribution
    {
        return $this->contribution;
    }

    public function isNewlyCredited(): bool
    {
        return $this->newlyCredited;
    }
}
