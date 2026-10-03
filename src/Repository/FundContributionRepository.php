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
     * Total of the validated test drive contributions, in DT.
     */
    public function sumAmounts(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.amount), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
