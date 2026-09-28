<?php

namespace App\Repository;

use App\Entity\Expense;
use App\Entity\Property;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Expense> */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /** @return list<Expense> entries of the property in [from, to), by date */
    public function between(Property $property, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.property = :p')->andWhere('e.date >= :from')->andWhere('e.date < :to')
            ->setParameter('p', $property->getId(), 'uuid')->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->orderBy('e.date', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<int> years having at least one entry for the property */
    public function years(Property $property): array
    {
        $years = [];
        foreach ($this->createQueryBuilder('e')->select('e.date')->andWhere('e.property = :p')->setParameter('p', $property->getId(), 'uuid')->getQuery()->getArrayResult() as $row) {
            $years[(int) $row['date']->format('Y')] = true;
        }

        return array_keys($years);
    }
}
