<?php

namespace App\Property;

use App\Entity\Connector;
use App\Entity\Property;
use App\Entity\SmartLock;
use App\Lodgify\LodgifyClient;
use App\Nuki\NukiClient;
use App\Repository\PropertyRepository;
use App\Repository\SmartLockRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a property for every Lodgify property not linked yet (named after its short "internal name"), refreshes the
 * Lodgify name and coordinates, and registers every Nuki lock (the link lock → property is chosen by an admin).
 */
final class PropertySync
{
    public function __construct(
        private readonly LodgifyClient $lodgify,
        private readonly NukiClient $nuki,
        private readonly PropertyRepository $properties,
        private readonly SmartLockRepository $locks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{created: int, locks: int} */
    public function sync(): array
    {
        $created = 0;
        foreach ($this->lodgify->properties() as $p) {
            $property = $this->properties->findOneBy(['lodgifyPropertyId' => $p['id']]);
            if (null === $property) {
                $property = (new Property())->setName($p['internalName'] ?? $p['name'])->setLodgifyPropertyId($p['id']);
                $this->em->persist($property);
                // Demo Homey connector (no address/key configured yet: read-only demo devices, see App\Homey\HomeyClient)
                $this->em->persist((new Connector($property, 'homey'))->setName('Homey (démo)'));
                ++$created;
            }
            $property->setLodgifyName($p['name'])->setCoordinates($p['latitude'], $p['longitude']);
        }
        $newLocks = 0;
        foreach ($this->nuki->locks() as $l) {
            $lock = $this->locks->find($l['id']);
            if (null === $lock) {
                $this->em->persist($lock = new SmartLock($l['id']));
                ++$newLocks;
            }
            $lock->setName($l['name']);
        }
        $this->em->flush();

        return ['created' => $created, 'locks' => $newLocks];
    }
}
