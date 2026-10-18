<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FundContribution;
use App\Entity\Product;
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
     * Number of validated test drives and the sum of all contributions, in DT, read together
     * so the figures always match. manualAmount is the part added by hand (no reservation).
     *
     * @return array{count: int, amount: int, clientAmount: int, alphaFordAmount: int, manualAmount: int}
     */
    public function totals(): array
    {
        /** @var array<string, int|string|null> $row */
        $row = $this->createQueryBuilder('c')
            ->select(
                'COUNT(IDENTITY(c.reservation)) AS count',
                'COALESCE(SUM(c.amount), 0) AS amount',
                'COALESCE(SUM(c.clientAmount), 0) AS clientAmount',
                'COALESCE(SUM(c.alphaFordAmount), 0) AS alphaFordAmount',
                'COALESCE(SUM(CASE WHEN c.reservation IS NULL THEN c.amount ELSE 0 END), 0) AS manualAmount',
            )
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $row['count'],
            'amount' => (int) $row['amount'],
            'clientAmount' => (int) $row['clientAmount'],
            'alphaFordAmount' => (int) $row['alphaFordAmount'],
            'manualAmount' => (int) $row['manualAmount'],
        ];
    }

    /**
     * The latest amounts added by hand (no reservation), newest first.
     *
     * @return list<FundContribution>
     */
    public function latestManual(int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.reservation IS NULL')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a sale of this product was already recorded (it can then no longer be deleted).
     */
    public function isProductSold(Product $product): bool
    {
        return null !== $this->findOneBy(['product' => $product]);
    }
}
