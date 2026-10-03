<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FundContribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FundContribution>
 */
class FundContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FundContribution::class);
    }

    /**
     * Number of validated test drives and the sum of their contributions, in DT, read together
     * so the two figures always match.
     *
     * @return array{count: int, amount: int, clientAmount: int, alphaFordAmount: int}
     */
    public function totals(): array
    {
        /** @var array<string, int|string|null> $row */
        $row = $this->createQueryBuilder('c')
            ->select(
                'COUNT(c.id) AS count',
                'COALESCE(SUM(c.amount), 0) AS amount',
                'COALESCE(SUM(c.clientAmount), 0) AS clientAmount',
                'COALESCE(SUM(c.alphaFordAmount), 0) AS alphaFordAmount',
            )
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $row['count'],
            'amount' => (int) $row['amount'],
            'clientAmount' => (int) $row['clientAmount'],
            'alphaFordAmount' => (int) $row['alphaFordAmount'],
        ];
    }
}
