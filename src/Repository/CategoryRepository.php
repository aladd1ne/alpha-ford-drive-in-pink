<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.archived = 0')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
