<?php

namespace App\Property;

use App\Entity\Property;
use App\Lodgify\BookingProviderRegistry;
use App\Repository\PropertyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a property for every Lodgify property not linked yet (named after its short "internal name") and refreshes
 * the Lodgify name and coordinates. Locks are no longer synced here: they belong to Rocket Place (link a property to
 * its place, see App\Controller\PlaceLinkController). Always uses the legacy, global LODGIFY_API_KEY account
 * (App\Lodgify\BookingProviderRegistry::legacy), since it runs before any property (and so any connector) exists.
 */
final class PropertySync
{
    public function __construct(
        private readonly BookingProviderRegistry $bookingProviders,
        private readonly PropertyRepository $properties,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{created: int} */
    public function sync(): array
    {
        $created = 0;
        foreach ($this->bookingProviders->legacy()->properties() as $p) {
            $property = $this->properties->findOneBy(['lodgifyPropertyId' => $p['id']]);
            if (null === $property) {
                $property = (new Property())->setName($p['internalName'] ?? $p['name'])->setLodgifyPropertyId($p['id']);
                $this->em->persist($property);
                ++$created;
            }
            $property->setLodgifyName($p['name'])->setCoordinates($p['latitude'], $p['longitude']);
        }
        $this->em->flush();

        return ['created' => $created];
    }
}
