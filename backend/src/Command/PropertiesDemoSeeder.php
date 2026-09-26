<?php

namespace App\Command;

use App\Property\PropertySync;
use App\Repository\PropertyRepository;
use App\Repository\SmartLockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Demo: the two fictitious Lodgify properties, their colours, and each demo lock linked to its property. */
final class PropertiesDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly PropertySync $sync,
        private readonly PropertyRepository $properties,
        private readonly SmartLockRepository $locks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        $this->sync->sync();
        foreach ([1001 => ['green', 90001], 1002 => ['blue', 90002]] as $lodgifyId => [$color, $lockId]) {
            $property = $this->properties->findOneBy(['lodgifyPropertyId' => $lodgifyId]);
            if (null === $property) {
                continue;
            }
            $property->setColor($color);
            $this->locks->find($lockId)?->setProperty($property);
        }
        $this->em->flush();
        $io->writeln('Logements de démo : 2, avec leur serrure.');
    }
}
