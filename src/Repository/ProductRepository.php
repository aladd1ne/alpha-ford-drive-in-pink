<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Products that can be sold, by name.
     */
    public function createActiveQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->orderBy('p.name', 'ASC');
    }
}
