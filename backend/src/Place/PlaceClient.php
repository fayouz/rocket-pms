<?php

namespace App\Place;

use App\Entity\Property;
use App\Rocket\BrickClient;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Rocket Place (rocket-apps/rocket-place), the owner of the places and of their locks and access grants,
 * connectors/domotique and documents (cleanings are Rocket Clean's: App\Clean\CleanClient; stock is Rocket Stock's:
 * App\Stock\StockClient). PMS holds one application token (ROCKET_PLACE_TOKEN, prefix rpl_) and a Property only keeps
 * the id of its place (Property::$placeId). The browser never talks to Rocket Place: App\Controller\PlaceProxyController
 * forwards the calls scoped to the property's place.
 * Without ROCKET_PLACE_URL/TOKEN: App\Place\DemoPlace answers (no network call, keeps tests offline).
 *
 * Suite mode (ROCKET_AUTH_URL + ROCKET_AUTH_CLIENT_SECRET): Rocket Auth token for the audience "rocket-place"
 * (App\Rocket\BrickClient), ROCKET_PLACE_TOKEN stays the fallback.
 */
final class PlaceClient extends BrickClient
{
    private const MAX_CONTENT_BYTES = 50 * 1024 * 1024;

    public function __construct(
        HttpClientInterface $http,
        private readonly DemoPlace $demoPlace,
        string $placeUrl,
        string $placeToken,
        ?ServiceTokenProvider $serviceTokens = null,
    ) {
        parent::__construct($http, $placeUrl, $placeToken, $serviceTokens);
    }

    /** Place of a property, or a clear 409 when an admin has not linked it yet. */
    public static function placeIdOf(Property $property): string
    {
        return $property->getPlaceId() ?? throw new HttpException(409, 'Ce logement n’est lié à aucun lieu Rocket Place : choisis son lieu dans l’onglet « Infos ».');
    }

    protected function brickName(): string
    {
        return 'Rocket Place';
    }

    protected function audience(): string
    {
        return 'rocket-place';
    }

    protected function tokenEnv(): string
    {
        return 'ROCKET_PLACE_TOKEN';
    }

    protected function demo(string $method, string $path, ?array $json, array $query): array
    {
        return $this->demoPlace->handle($method, $path, $json, $query);
    }

    /** @return array<mixed> */
    public function upload(string $path, UploadedFile $file, ?string $folder): array
    {
        if ($this->isDemo()) {
            return $this->demoPlace->handle('POST', $path, ['name' => $file->getClientOriginalName(), 'size' => $file->getSize() ?: 0, 'folder' => $folder], []);
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
            return $this->demoPlace->content($path);
        }

        return $this->send('GET', $path, [], self::MAX_CONTENT_BYTES);
    }
}
