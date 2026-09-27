<?php

namespace App\Domotique;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Service web": plug any read-only HTTP/JSON API by configuration. GET of a base address + a path, JSON response
 * flattened for display (40 values at most). https only, unless the address is on the local network.
 */
final class WebServicePlugin implements PluginInterface
{
    private const TIMEOUT = 15;
    private const MAX_BYTES = 1024 * 1024;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function id(): string { return 'webservice'; }
    public function name(): string { return 'Service web'; }
    public function description(): string { return 'Brancher n’importe quelle API web (REST/JSON) en lecture seule : affiche des informations dans l’onglet Domotique.'; }
    public function icon(): string { return 'i-lucide-globe'; }
    public function category(): string { return 'general'; }
    public function capabilities(): array { return []; }

    public function fields(): array
    {
        return [
            ['key' => 'baseUrl', 'label' => 'Adresse de base', 'type' => 'url', 'required' => true, 'placeholder' => 'https://api.exemple.fr/v1'],
            ['key' => 'infoPath', 'label' => 'Chemin des informations (GET, JSON)', 'type' => 'text', 'placeholder' => '/status'],
            ['key' => 'secretVar', 'label' => 'Variable .env du jeton (Bearer)', 'type' => 'text', 'secret' => true, 'placeholder' => 'CONNECTOR_MON_SERVICE'],
        ];
    }

    public function validate(array $config, string $propertyId, ?string $connectorId): array
    {
        $this->checkUrl($config['baseUrl'] ?? '', 'Adresse de base');
        if ('' !== ($config['secretVar'] ?? '') && !SecretEnv::isValidName($config['secretVar'])) {
            throw new HttpException(400, 'Le nom de variable doit commencer par CONNECTOR_ (majuscules, chiffres, _).');
        }

        return $config;
    }

    public function test(array $config): string
    {
        $url = $this->target($config, $config['infoPath'] ?? '/');
        $this->request($config, $url);

        return \sprintf('Le service répond (%s).', parse_url($url, \PHP_URL_HOST));
    }

    public function info(array $config): array
    {
        if ('' === ($config['infoPath'] ?? '')) {
            return [];
        }
        $url = $this->target($config, $config['infoPath']);
        $json = json_decode($this->request($config, $url), true);
        if (!\is_array($json)) {
            throw new HttpException(502, 'La réponse du service n’est pas du JSON.');
        }

        return [['title' => 'Informations du service', 'icon' => 'i-lucide-info', 'items' => $this->flatten($json)]];
    }

    /** @param array<string, string> $config */
    private function target(array $config, string $path): string
    {
        $base = rtrim($config['baseUrl'] ?? '', '/');
        $path = '/'.ltrim($path, '/');

        return $base.$path;
    }

    /** @param array<string, string> $config */
    private function request(array $config, string $url): string
    {
        $headers = ['Accept' => 'application/json'];
        $secretVar = trim($config['secretVar'] ?? '');
        if ('' !== $secretVar) {
            $u = parse_url($url);
            if (('http' === ($u['scheme'] ?? '')) && !SecretEnv::isPrivateHost((string) ($u['host'] ?? ''))) {
                throw new HttpException(400, 'Authentification refusée en http vers Internet : utilise une adresse https.');
            }
            $headers['Authorization'] = 'Bearer '.SecretEnv::read($secretVar, 'Jeton du service');
        }
        try {
            $response = $this->http->request('GET', $url, ['headers' => $headers, 'timeout' => self::TIMEOUT, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            throw new HttpException(502, 'Service injoignable (adresse ou réseau à vérifier).');
        }
        if ($status >= 400) {
            throw new HttpException(502, \sprintf('Le service a répondu avec l’erreur %d.', $status));
        }
        $content = $response->getContent(false);
        if (\strlen($content) > self::MAX_BYTES) {
            throw new HttpException(502, 'Réponse trop volumineuse.');
        }

        return $content;
    }

    private function checkUrl(string $raw, string $label): void
    {
        if ('' === $raw || !preg_match('#^https?://#', $raw)) {
            throw new HttpException(400, \sprintf('« %s » : adresse invalide.', $label));
        }
    }

    /** @param array<mixed> $v @return list<array{label: string, value: string}> */
    private function flatten(array $v, string $prefix = '', array &$out = [], int $depth = 0): array
    {
        if (\count($out) >= 40 || $depth > 10) {
            return $out;
        }
        foreach ($v as $k => $x) {
            if (\count($out) >= 40) {
                break;
            }
            $label = '' === $prefix ? (string) $k : $prefix.'.'.$k;
            if (\is_array($x)) {
                $this->flatten($x, $label, $out, $depth + 1);
            } elseif (\is_scalar($x) || null === $x) {
                $out[] = ['label' => $label, 'value' => mb_substr((string) $x, 0, 200)];
            }
        }

        return $out;
    }
}
