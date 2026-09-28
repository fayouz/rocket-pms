<?php

namespace App\Command;

use App\Place\DemoPlace;
use App\Place\PlaceClient;
use App\Property\PropertySync;
use App\Repository\PropertyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Demo: the two fictitious Lodgify properties, their colours, and (demo Rocket Place only) the link to their place. */
final class PropertiesDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly PropertySync $sync,
        private readonly PropertyRepository $properties,
        private readonly PlaceClient $place,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        $this->sync->sync();
        foreach ([1001 => ['green', DemoPlace::PORT], 1002 => ['blue', DemoPlace::VIGNES]] as $lodgifyId => [$color, $placeId]) {
            $property = $this->properties->findOneBy(['lodgifyPropertyId' => $lodgifyId]);
            if (null === $property) {
                continue;
            }
            $property->setColor($color);
            if ($this->place->isDemo()) {
                $property->setPlaceId($placeId);
            }
        }
        $this->em->flush();
        $io->writeln('Logements de démo : 2, liés à leur lieu Rocket Place de démo.');
    }
}
