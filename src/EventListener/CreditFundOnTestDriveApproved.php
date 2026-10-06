<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\FundContribution;
use App\Event\TestDriveApproved;
use App\Repository\FundContributionRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Cagnotte side of a test drive approval: adds one contribution for the reservation,
 * the amount received from the client plus Alpha Ford's 20 DT.
 *
 * Idempotent: a reservation that already has a contribution is not credited again
 * (and the unique reservation column of fund_contribution refuses it anyway).
 * The public counters pick the new total up through /cagnotte/etat (FundController).
 */
#[AsEventListener]
final class CreditFundOnTestDriveApproved
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly FundContributionRepository $contributions,
    ) {
    }

    public function __invoke(TestDriveApproved $event): void
    {
        $existing = $this->contributions->findOneBy(['reservation' => $event->reservation]);
        if (null !== $existing) {
            $event->setContribution($existing, newlyCredited: false);

            return;
        }

        $contribution = FundContribution::forTestDrive($event->reservation, $event->approvedAt, $event->approvedBy, $event->clientAmount);
        $manager = $this->registry->getManagerForClass(FundContribution::class)
            ?? throw new \LogicException('No entity manager for fund contributions.');
        $manager->persist($contribution);
        $event->setContribution($contribution, newlyCredited: true);
    }
}
