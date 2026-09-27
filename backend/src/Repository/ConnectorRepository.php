<?php

namespace App\Repository;

use App\Entity\Connector;
use App\Entity\Property;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Connector> */
class ConnectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Connector::class);
    }

    /** @return list<Connector> */
    public function forProperty(Property $property): array
    {
        return $this->findBy(['property' => $property], ['name' => 'ASC']);
    }

}
