<?php

declare(strict_types=1);

namespace App\Service;

/**
 * State of the solidarity fund at one point in time, in DT.
 */
final class FundSnapshot
{
    public function __construct(
        public readonly int $total,
        /** Cashed-in test drives (one contribution each: amount received + Alpha Ford share). */
        public readonly int $testDrives,
        public readonly int $clientAmount,
        public readonly int $alphaFordAmount,
        /** Opening amount collected outside the site (SOLIDARITY_FUND_AMOUNT) plus amounts added by hand. */
        public readonly int $otherAmount,
    ) {
    }

    /**
     * Changes whenever a figure changes: used as the ETag of the live endpoint.
     */
    public function version(): string
    {
        return hash('xxh3', implode(':', [$this->total, $this->testDrives, $this->clientAmount, $this->alphaFordAmount, $this->otherAmount]));
    }

    /**
     * @return array{total: int, testDrives: int, clientAmount: int, alphaFordAmount: int, otherAmount: int}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'testDrives' => $this->testDrives,
            'clientAmount' => $this->clientAmount,
            'alphaFordAmount' => $this->alphaFordAmount,
            'otherAmount' => $this->otherAmount,
        ];
    }
}
