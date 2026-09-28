<?php

namespace App\Place;

use App\Entity\Property;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Place (rocket-apps/rocket-place), the owner of everything physical about a property: locks and
 * access grants, connectors/domotique, documents, stock. PMS holds one application token (ROCKET_PLACE_TOKEN, prefix
 * rpl_) and a Property only keeps the id of its place (Property::$placeId). The browser never talks to Rocket Place:
 * App\Controller\PlaceProxyController forwards the calls scoped to the property's place.
 * Without ROCKET_PLACE_URL/TOKEN: App\Place\DemoPlace answers (no network call, keeps tests offline).
 *
 * Responses are streamed and capped (MAX_BYTES), errors mapped: 4xx of Place (404, 409, 422...) are passed through
 * with their message, token refused / 5xx / unreachable become a 502 with a clear French message.
 *
 * Suite mode (ROCKET_AUTH_URL + ROCKET_AUTH_CLIENT_SECRET): calls carry an access token of Rocket Auth obtained with
 * the client credentials grant for the audience "rocket-place" (rocket-core ServiceTokenProvider); ROCKET_PLACE_TOKEN
 * stays the fallback (standalone mode, or Rocket Auth unreachable).
 */
final class PlaceClient
{
    private const TIMEOUT = 8;
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_CONTENT_BYTES = 50 * 1024 * 1024;
    private const AUDIENCE = 'rocket-place';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly DemoPlace $demo,
        private readonly string $placeUrl,
        private readonly string $placeToken,
        private readonly ?ServiceTokenProvider $serviceTokens = null,
    ) {
    }

    /** Place of a property, or a clear 409 when an admin has not linked it yet. */
    public static function placeIdOf(Property $property): string
    {
        return $property->getPlaceId() ?? throw new HttpException(409, 'Ce logement n’est lié à aucun lieu Rocket Place : choisis son lieu dans l’onglet « Infos ».');
    }

    public function isDemo(): bool
    {
        return '' === trim($this->placeUrl) || ('' === trim($this->placeToken) && !$this->usesSuiteTokens());
    }

    /** Whether calls use tokens of Rocket Auth (suite mode) rather than the static ROCKET_PLACE_TOKEN. */
    public function usesSuiteTokens(): bool
    {
        return null !== $this->serviceTokens && $this->serviceTokens->isAvailable();
    }

    /** Bearer of the next call: a token of Rocket Auth in suite mode, else (or if Rocket Auth fails) the static token. */
    private function bearer(): string
    {
        if ($this->usesSuiteTokens()) {
            try {
                return $this->serviceTokens->tokenForClient(self::AUDIENCE);
            } catch (OidcException $e) {
                if ('' === trim($this->placeToken)) {
                    throw new HttpException(502, 'Rocket Auth ne délivre pas de jeton pour Rocket Place : '.$e->getMessage());
                }
            }
        }

        return $this->placeToken;
    }

    /**
     * JSON call to Rocket Place. $path starts with /api/.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>      $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        if ($this->isDemo()) {
            return $this->demo->handle($method, $path, $json, $query);
        }
        $options = ['headers' => ['Accept' => 'application/json']];
        if ([] !== $query) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['body'] = json_encode($json, \JSON_THROW_ON_ERROR);
            $options['headers']['Content-Type'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json';
        }

        return $this->decode($this->send($method, $path, $options, self::MAX_BYTES));
    }

    /** @return array<mixed> */
    public function upload(string $path, UploadedFile $file, ?string $folder): array
    {
        if ($this->isDemo()) {
            return $this->demo->handle('POST', $path, ['name' => $file->getClientOriginalName(), 'size' => $file->getSize() ?: 0, 'folder' => $folder], []);
        }
        $fields = ['file' => DataPart::fromPath($file->getPathname(), $file->getClientOriginalName())];
        if (null !== $folder && '' !== $folder) {
            $fields['folder'] = $folder;
        }
        $form = new FormDataPart($fields);

        return $this->decode($this->send('POST', $path, ['headers' => $form->getPreparedHeaders()->toArray() + ['Accept' => 'application/json'], 'body' => $form->bodyToIterable()], self::MAX_BYTES));
    }

    /** Raw bytes (document content), capped at MAX_CONTENT_BYTES. */
    public function content(string $path): string
    {
        if ($this->isDemo()) {
            return $this->demo->content($path);
        }

        return $this->send('GET', $path, [], self::MAX_CONTENT_BYTES);
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options, int $maxBytes): string
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer '.$this->bearer()];
        $options['timeout'] = self::TIMEOUT;
        try {
            $response = $this->http->request($method, rtrim($this->placeUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new HttpException(502, 'Réponse de Rocket Place trop volumineuse.');
                }
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(502, 'Rocket Place ne répond pas ou est injoignable depuis le serveur.');
        }
        if (401 === $status) {
            if ($this->usesSuiteTokens()) {
                $this->serviceTokens->forget(self::AUDIENCE);
                throw new HttpException(502, 'Jeton Rocket Auth refusé par Rocket Place (client rocket-pms lié à une application ?).');
            }
            throw new HttpException(502, 'Jeton Rocket Place refusé (ROCKET_PLACE_TOKEN).');
        }
        if (403 === $status) {
            throw new HttpException(502, 'Rocket Place refuse cette action à l’application Rocket PMS.');
        }
        if ($status >= 400 && $status < 500) {
            throw new HttpException($status, self::message($body) ?? \sprintf('Rocket Place a répondu avec l’erreur %d.', $status));
        }
        if ($status >= 500) {
            throw new HttpException(502, \sprintf('Rocket Place a répondu avec l’erreur %d.', $status));
        }

        return $body;
    }

    /** @return array<mixed> */
    private function decode(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(502, 'Réponse illisible de Rocket Place.');
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
