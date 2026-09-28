<?php

namespace App\Rocket;

use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared HTTP plumbing of the clients of the Rocket bricks (Place, Clean, Stock): Bearer token (Rocket Auth client
 * credentials for the brick's audience in suite mode, static application token otherwise or as fallback), streamed and
 * capped responses, errors mapped (4xx relayed with their message, token refused / 5xx / unreachable → 502 in French).
 * Without URL/token, the brick's demo handler answers (no network call).
 */
abstract class BrickClient
{
    protected const TIMEOUT = 8;
    protected const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        protected readonly HttpClientInterface $http,
        private readonly string $brickUrl,
        private readonly string $brickToken,
        protected readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    /** Human name of the brick, in messages ("Rocket Place"). */
    abstract protected function brickName(): string;

    /** Rocket Auth audience of the brick ("rocket-place"). */
    abstract protected function audience(): string;

    /** Environment variable of the static token, in messages. */
    abstract protected function tokenEnv(): string;

    /**
     * Demo answer when the brick is not configured.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>      $query
     *
     * @return array<mixed>
     */
    abstract protected function demo(string $method, string $path, ?array $json, array $query): array;

    public function isDemo(): bool
    {
        return '' === trim($this->brickUrl) || ('' === trim($this->brickToken) && !$this->usesSuiteTokens());
    }

    /** Whether calls use tokens of Rocket Auth (suite mode) rather than the static token. */
    public function usesSuiteTokens(): bool
    {
        return null !== $this->serviceTokens && $this->serviceTokens->isAvailable();
    }

    /**
     * JSON call to the brick. $path starts with /api/.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>      $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        if ($this->isDemo()) {
            return $this->demo($method, $path, $json, $query);
        }
        $options = ['headers' => ['Accept' => 'application/json']];
        if ([] !== $query) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['body'] = json_encode($json, \JSON_THROW_ON_ERROR);
            $options['headers']['Content-Type'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json';
        }

        return $this->decode($this->send($method, $path, $options, static::MAX_BYTES));
    }

    /** Bearer of the next call: a token of Rocket Auth in suite mode, else (or if Rocket Auth fails) the static token. */
    private function bearer(): string
    {
        if ($this->usesSuiteTokens()) {
            try {
                return $this->serviceTokens->tokenForClient($this->audience());
            } catch (OidcException $e) {
                if ('' === trim($this->brickToken)) {
                    throw new HttpException(502, \sprintf('Rocket Auth ne délivre pas de jeton pour %s : %s', $this->brickName(), $e->getMessage()));
                }
            }
        }

        return $this->brickToken;
    }

    /** @param array<string, mixed> $options */
    protected function send(string $method, string $path, array $options, int $maxBytes): string
    {
        $name = $this->brickName();
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer '.$this->bearer()];
        $options['timeout'] = static::TIMEOUT;
        try {
            $response = $this->http->request($method, rtrim($this->brickUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new HttpException(502, "Réponse de $name trop volumineuse.");
                }
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(502, "$name ne répond pas ou est injoignable depuis le serveur.");
        }
        if (401 === $status) {
            if ($this->usesSuiteTokens()) {
                $this->serviceTokens->forget($this->audience());
                throw new HttpException(502, "Jeton Rocket Auth refusé par $name (client rocket-pms lié à une application ?).");
            }
            throw new HttpException(502, \sprintf('Jeton %s refusé (%s).', $name, $this->tokenEnv()));
        }
        if (403 === $status) {
            throw new HttpException(502, "$name refuse cette action à l’application Rocket PMS.");
        }
        if ($status >= 400 && $status < 500) {
            throw new HttpException($status, self::message($body) ?? \sprintf('%s a répondu avec l’erreur %d.', $name, $status));
        }
        if ($status >= 500) {
            throw new HttpException(502, \sprintf('%s a répondu avec l’erreur %d.', $name, $status));
        }

        return $body;
    }

    /** @return array<mixed> */
    protected function decode(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(502, 'Réponse illisible de '.$this->brickName().'.');
        }

        return \is_array($data) ? $data : [];
    }

    private static function message(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            return null;
        }
        $m = $data['detail'] ?? $data['hydra:description'] ?? $data['message'] ?? null;

        return \is_string($m) && '' !== $m ? mb_substr($m, 0, 300) : null;
    }
}
