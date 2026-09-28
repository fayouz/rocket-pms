<?php

namespace App\Command;

use App\Place\DemoPlace;
use App\Place\PlaceClient;
use App\Property\PropertySync;
use App\Repository\PropertyRepository;
use App\Repository\WelcomeBookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Demo: the two fictitious Lodgify properties, their colours and the link to their place. With the demo Rocket Place
 * (no ROCKET_PLACE_URL/TOKEN) the fixed DemoPlace ids; with a real (local) Rocket Place, the place of the same name
 * (seeded by Place's own app:demo:seed), created from the property when missing. Never overwrites an existing link.
 */
final class PropertiesDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly PropertySync $sync,
        private readonly PropertyRepository $properties,
        private readonly PlaceClient $place,
        private readonly EntityManagerInterface $em,
        private readonly WelcomeBookRepository $books,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        $this->sync->sync();
        $demo = [1001 => ['green', DemoPlace::PORT, 'Le port'], 1002 => ['blue', DemoPlace::VIGNES, 'Les vignes']];
        foreach ($demo as $lodgifyId => [$color, $demoPlaceId, $placeName]) {
            $property = $this->properties->findOneBy(['lodgifyPropertyId' => $lodgifyId]);
            if (null === $property) {
                continue;
            }
            $property->setColor($color);
            $book = $this->books->forProperty($property);
            if ('' === $book->getContent()['welcomeText']) {
                $book->updateContent([
                    'welcomeText' => "Bienvenue {{guest}} ! Toute l'équipe te souhaite un excellent séjour à ".$placeName.'.',
                    'wifiSsid' => 'Demo-'.str_replace(' ', '', $placeName), 'wifiPassword' => 'bienvenue2026',
                    'checkinInfo' => 'Arrivée à partir de 15 h. Le code de la porte apparaît ici dès qu’il est activé.',
                    'checkoutInfo' => 'Départ avant 11 h : lave-vaisselle lancé, poubelles sorties, clés sur la table.',
                    'houseRules' => "Non-fumeur. Pas de fête. Calme après 22 h.",
                    'contacts' => 'Hôte : via la messagerie de ta réservation. Urgences : 112.',
                    'localTips' => 'Boulangerie au coin de la rue, marché le samedi matin.',
                ]);
            }
            if ($this->place->isDemo()) {
                $property->setPlaceId($demoPlaceId);
            } elseif (null === $property->getPlaceId() || \in_array($property->getPlaceId(), [DemoPlace::PORT, DemoPlace::VIGNES], true)) {
                try {
                    $property->setPlaceId($this->realPlaceId($placeName, $color));
                } catch (HttpException $e) {
                    $io->warning(\sprintf('%s : lien Rocket Place impossible (%s).', $property->getName(), $e->getMessage()));
                }
            }
        }
        $this->em->flush();
        $io->writeln($this->place->isDemo() ? 'Logements de démo : 2, liés à leur lieu Rocket Place de démo.' : 'Logements de démo : 2, liés à leur lieu Rocket Place (ROCKET_PLACE_URL).');
    }

    /** Id of the place named $name in the real Rocket Place, created when missing. */
    private function realPlaceId(string $name, string $color): string
    {
        foreach ($this->place->request('GET', '/api/places') as $place) {
            if (\is_array($place) && ($place['name'] ?? null) === $name && \is_string($place['id'] ?? null)) {
                return $place['id'];
            }
        }

        return (string) $this->place->request('POST', '/api/places', ['name' => $name, 'color' => $color])['id'];
    }
}
