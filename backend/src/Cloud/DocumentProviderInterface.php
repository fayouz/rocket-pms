<?php

namespace App\Cloud;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A store of a property's documents (Rocket Cloud today). Implemented by App\Cloud\CloudClient; resolved per
 * property by App\Cloud\DocumentProviderRegistry from the property's own connector (App\Domotique\RocketCloudPlugin),
 * falling back to the single legacy ROCKET_CLOUD_URL/ROCKET_CLOUD_TOKEN.
 */
interface DocumentProviderInterface
{
    public function isDemo(): bool;

    public function ensureFolder(string $propertyId, string $existingFolderId, string $propertyName): string;

    /** @return list<array{id: string, kind: string, name: string, size: ?int, updatedAt: string}> */
    public function list(string $folderId): array;

    /** @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string} */
    public function createFolder(string $parentId, string $name): array;

    /** @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string} */
    public function upload(string $folderId, UploadedFile $file): array;

    public function remove(string $folderId, string $itemId, string $kind): void;

    public function rename(string $itemId, string $kind, string $name): void;

    public function move(string $itemId, string $kind, ?string $targetFolderId): void;

    public function belongsToProperty(string $itemId, string $kind, string $rootFolderId): bool;

    public function content(string $fileId): string;
}
