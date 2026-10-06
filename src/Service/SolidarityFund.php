<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FundContributionRepository;

/**
 * Solidarity fund balance: the configured opening amount (SOLIDARITY_FUND_AMOUNT,
 * e.g. contributions collected outside the site) plus every validated test drive and
 * every amount added by hand in the back-office.
 *
 * Every public figure (home page counter, cagnotte page, live endpoint, back-office)
 * comes from snapshot(), so they can never disagree.
 */
final class SolidarityFund
{
    public function __construct(
        private readonly int $solidarityFundAmount,
        private readonly FundContributionRepository $contributions,
    ) {
    }

    public function snapshot(): FundSnapshot
    {
        $totals = $this->contributions->totals();

        return new FundSnapshot(
            total: $this->solidarityFundAmount + $totals['amount'],
            testDrives: $totals['count'],
            clientAmount: $totals['clientAmount'],
            alphaFordAmount: $totals['alphaFordAmount'],
            otherAmount: $this->solidarityFundAmount + $totals['manualAmount'],
        );
    }

    public function total(): int
    {
        return $this->snapshot()->total;
    }
}
