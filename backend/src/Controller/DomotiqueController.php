<?php

namespace App\Controller;

use App\Domotique\PluginRegistry;
use App\Entity\Property;
use App\Repository\ConnectorRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** "Domotique" tab of a property: info cards of every enabled connector (any category, e.g. Homey, Service web). Read-only. */
#[IsGranted('ROLE_USER')]
final class DomotiqueController extends AbstractController
{
    public function __construct(
        private readonly ConnectorRepository $connectors,
        private readonly PluginRegistry $plugins,
    ) {
    }

    #[Route('/api/properties/{id}/domotique', name: 'api_property_domotique', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function info(#[MapEntity] Property $property): JsonResponse
    {
        $sections = [];
        foreach ($this->connectors->forProperty($property) as $connector) {
            if (!$connector->isEnabled()) {
                continue;
            }
            $plugin = $this->plugins->get($connector->getPluginId());
            try {
                $cards = $plugin->info($connector->getConfig());
                $error = null;
            } catch (\Throwable $e) {
                $cards = [];
                $error = $e->getMessage();
            }
            $sections[] = [
                'connectorId' => $connector->getId()->toRfc4122(), 'name' => $connector->getName(),
                'pluginId' => $plugin->id(), 'pluginName' => $plugin->name(), 'icon' => $plugin->icon(),
                'cards' => $cards, 'error' => $error,
            ];
        }

        return $this->json(['sections' => $sections]);
    }
}
