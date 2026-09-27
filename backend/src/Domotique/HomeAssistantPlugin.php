<?php

namespace App\Domotique;

use App\HomeAssistant\HomeAssistantClient;
use App\Lock\HomeAssistantProvider;
use App\Lock\LockCapablePluginInterface;
use App\Lock\LockProviderInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Home Assistant hub: entities of the property (locks, sensors, battery) read from /api/states. Read-only like the
 * other connectors of the "Domotique" tab, and a source of smart locks ('locks.state', always) plus, only when a
 * service is configured, keypad/access codes ('locks.codes'). Important limit confirmed by research: Home
 * Assistant's own Nuki integration (and Nuki over Matter/MQTT) cannot create Nuki keypad codes — only Nuki's Web API
 * can (see App\Domotique\NukiPlugin / App\Lock\NukiProvider). The `lockService` field is meant for locks whose
 * *controller* supports a usercode service, e.g. Z-Wave (`zwave_js.set_lock_usercode`) or a "Keymaster" helper — not
 * for Nuki locks integrated into Home Assistant.
 */
final class HomeAssistantPlugin implements PluginInterface, LockCapablePluginInterface
{
    public function __construct(private readonly HomeAssistantClient $client)
    {
    }

    public function id(): string { return 'home_assistant'; }
    public function name(): string { return 'Home Assistant'; }
    public function description(): string { return 'Hub domotique Home Assistant : serrures, capteurs et batteries du logement. Lecture seule ; l’envoi de codes clavier dépend de l’intégration de la serrure (voir aide).'; }
    public function icon(): string { return 'i-lucide-house-wifi'; }
    public function category(): string { return 'domotique'; }
    public function capabilities(): array { return ['locks.state', 'locks.codes']; }

    public function lockProvider(array $config): LockProviderInterface
    {
        return new HomeAssistantProvider($this->client, $config);
    }

    public function fields(): array
    {
        return [
            ['key' => 'baseUrl', 'label' => 'Adresse de Home Assistant', 'type' => 'url', 'required' => true, 'placeholder' => 'http://homeassistant.local:8123', 'help' => 'Réseau local ou https recommandé.'],
            ['key' => 'secretVar', 'label' => 'Variable .env du jeton (accès longue durée)', 'type' => 'text', 'secret' => true, 'placeholder' => 'CONNECTOR_HA_SALON'],
            ['key' => 'lockServiceDomain', 'label' => 'Domaine du service de code clavier (optionnel)', 'type' => 'text', 'placeholder' => 'zwave_js', 'help' => 'Laisser vide si la serrure ne sait pas recevoir de code depuis Home Assistant (cas de Nuki).'],
            ['key' => 'lockService', 'label' => 'Nom du service de code clavier (optionnel)', 'type' => 'text', 'placeholder' => 'set_lock_usercode'],
        ];
    }

    public function validate(array $config, string $propertyId, ?string $connectorId): array
    {
        $url = trim($config['baseUrl'] ?? '');
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || !preg_match('#^https?://#', $url)) {
            throw new HttpException(400, 'Adresse de Home Assistant invalide.');
        }
        if ('' !== ($config['secretVar'] ?? '') && !SecretEnv::isValidName($config['secretVar'])) {
            throw new HttpException(400, 'Le nom de variable doit commencer par CONNECTOR_ (majuscules, chiffres, _).');
        }

        return $config;
    }

    public function test(array $config): string
    {
        $states = $this->client->states($config);

        return \sprintf('Home Assistant joignable (%d entité(s)).', \count($states));
    }

    public function info(array $config): array
    {
        $states = $this->client->states($config);
        $cards = [];
        foreach ($states as $s) {
            $domain = strstr($s['entity_id'], '.', true) ?: $s['entity_id'];
            if (!\in_array($domain, ['lock', 'sensor', 'binary_sensor'], true)) {
                continue;
            }
            $name = (string) ($s['attributes']['friendly_name'] ?? $s['entity_id']);
            $unit = $s['attributes']['unit_of_measurement'] ?? null;
            $value = 'lock' === $domain ? ('locked' === $s['state'] ? 'Verrouillée' : ('unlocked' === $s['state'] ? 'Déverrouillée' : $s['state'])) : $s['state'];
            $cards[] = ['title' => $name, 'icon' => 'lock' === $domain ? 'i-lucide-lock' : 'i-lucide-cpu', 'items' => [['label' => $s['entity_id'], 'value' => null !== $unit ? $value.' '.$unit : $value]]];
        }

        return \array_slice($cards, 0, 60);
    }
}
