<?php

namespace App\Controller;

use App\Domotique\PluginRegistry;
use App\Domotique\ConnectorSecrets;
use App\Entity\Connector;
use App\Entity\Property;
use App\Repository\ConnectorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Plugin catalogue and connectors (a plugin configured for a property). Admin-only writes; reading is open to any user. */
#[IsGranted('ROLE_USER')]
final class ConnectorController extends AbstractController
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly ConnectorRepository $connectors,
        private readonly EntityManagerInterface $em,
        private readonly ConnectorSecrets $connectorSecrets,
    ) {
    }

    #[Route('/api/plugins', name: 'api_plugins', methods: ['GET'])]
    public function catalogue(): JsonResponse
    {
        return $this->json(array_map($this->plugins->view(...), $this->plugins->all()));
    }

    #[Route('/api/properties/{id}/connectors', name: 'api_property_connectors', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function ofProperty(#[MapEntity] Property $property): JsonResponse
    {
        return $this->json(array_map($this->view(...), $this->connectors->forProperty($property)));
    }

    #[Route('/api/properties/{id}/connectors', name: 'api_property_connectors_create', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(#[MapEntity] Property $property, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $pluginId = (string) ($body['pluginId'] ?? '');
        $plugin = $this->plugins->get($pluginId);
        $connector = new Connector($property, $pluginId);
        $connector->setName((string) ($body['name'] ?? $plugin->name()));
        $connector->setConfig($plugin->validate($this->cleanConfig($plugin, $body['config'] ?? []), $property->getId()->toRfc4122(), null));
        $this->em->persist($connector);
        $this->em->flush();

        return $this->json($this->view($connector), 201);
    }

    #[Route('/api/connectors/{id}', name: 'api_connector_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(#[MapEntity] Connector $connector, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $plugin = $this->plugins->get($connector->getPluginId());
        if (\array_key_exists('name', $body)) {
            $connector->setName((string) $body['name']);
        }
        if (\array_key_exists('enabled', $body)) {
            $connector->setEnabled((bool) $body['enabled']);
        }
        if (\array_key_exists('config', $body)) {
            $connector->setConfig($plugin->validate($this->cleanConfig($plugin, $body['config']), $connector->getProperty()->getId()->toRfc4122(), $connector->getId()->toRfc4122()));
        }
        $this->em->flush();

        return $this->json($this->view($connector));
    }

    #[Route('/api/connectors/{id}', name: 'api_connector_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(#[MapEntity] Connector $connector): JsonResponse
    {
        $this->em->remove($connector);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/api/connectors/{id}/test', name: 'api_connector_test', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function test(#[MapEntity] Connector $connector): JsonResponse
    {
        $plugin = $this->plugins->get($connector->getPluginId());
        try {
            $result = $plugin->test($connector->getConfig());
        } catch (HttpException $e) {
            $connector->recordRun('Erreur : '.$e->getMessage());
            $this->em->flush();
            throw $e;
        }
        $connector->recordRun($result);
        $this->em->flush();

        return $this->json(['result' => $result]);
    }

    /** @param mixed $raw @return array<string, string> */
    private function cleanConfig(\App\Domotique\PluginInterface $plugin, mixed $raw): array
    {
        $body = \is_array($raw) ? $raw : [];
        $out = [];
        foreach ($plugin->fields() as $f) {
            $v = \is_string($body[$f['key']] ?? null) ? trim($body[$f['key']]) : '';
            if (($f['secret'] ?? false) && '' !== $v && !ConnectorSecrets::isValidName($v)) {
                throw new HttpException(400, \sprintf('« %s » : choisissez un secret du coffre (Administration → Secrets) ; les secrets propres à l’application sont interdits.', $f['label']));
            }
            if (($f['required'] ?? false) && '' === $v) {
                throw new HttpException(400, \sprintf('« %s » est requis.', $f['label']));
            }
            if ('' !== $v) {
                $out[$f['key']] = mb_substr($v, 0, 500);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function view(Connector $connector): array
    {
        $plugin = $this->plugins->get($connector->getPluginId());
        $config = $connector->getConfig();
        $secrets = [];
        foreach ($plugin->fields() as $f) {
            if ($f['secret'] ?? false) {
                $secrets[$f['key']] = $this->connectorSecrets->isConfigured(ConnectorSecrets::nameIn($config, $f['key']));
            }
        }

        return [
            'id' => $connector->getId()->toRfc4122(), 'propertyId' => $connector->getProperty()->getId()->toRfc4122(),
            'pluginId' => $connector->getPluginId(), 'name' => $connector->getName(), 'enabled' => $connector->isEnabled(),
            'config' => $config, 'secrets' => $secrets,
            'lastRunAt' => $connector->getLastRunAt()?->format(\DATE_ATOM), 'lastResult' => $connector->getLastResult(),
        ];
    }
}
