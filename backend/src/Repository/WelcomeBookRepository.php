<?php

namespace App\Repository;

use App\Entity\Property;
use App\Entity\WelcomeBook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WelcomeBook> */
class WelcomeBookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WelcomeBook::class);
    }

    /** The book of the property, created (and persisted, not flushed) on first access. */
    public function forProperty(Property $property): WelcomeBook
    {
        $book = $this->findOneBy(['property' => $property]);
        if (null === $book) {
            $book = new WelcomeBook($property);
            $this->getEntityManager()->persist($book);
        }

        return $book;
    }
}
