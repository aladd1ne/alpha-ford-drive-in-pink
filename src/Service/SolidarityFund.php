<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FundContributionRepository;

/**
 * Solidarity fund balance: the configured opening amount (SOLIDARITY_FUND_AMOUNT,
 * e.g. contributions collected outside the site) plus every validated test drive.
 */
final class SolidarityFund
{
    public function __construct(
        private readonly int $solidarityFundAmount,
        private readonly FundContributionRepository $contributions,
    ) {
    }

    public function total(): int
    {
        return $this->solidarityFundAmount + $this->contributions->sumAmounts();
    }
}
