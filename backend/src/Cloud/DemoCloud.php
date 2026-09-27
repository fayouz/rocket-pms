<?php

namespace App\Cloud;

/**
 * Rocket Cloud used whenever ROCKET_CLOUD_URL / ROCKET_CLOUD_TOKEN are not configured: keeps the "Documents" tab
 * (and functional tests) fully offline, one fictitious folder per property. State is kept in a small JSON file
 * (var/demo-cloud.json) rather than in memory: like a real HTTP call to Rocket Cloud, it must survive across
 * requests/workers (the app itself has no long-lived process holding this).
 */
final class DemoCloud
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public function ensureFolder(string $propertyId, string $name): string
    {
        $folderId = 'demo-folder-'.$propertyId;
        $state = $this->load();
        if (!isset($state[$folderId])) {
            $state[$folderId] = [
                ['id' => 'demo-file-'.$propertyId, 'kind' => 'file', 'name' => 'Bienvenue.pdf', 'size' => 12345, 'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)],
            ];
            $this->save($state);
        }

        return $folderId;
    }

    /** @return list<array{id: string, kind: string, name: string, size: ?int, updatedAt: string}> */
    public function list(string $folderId): array
    {
        return $this->load()[$folderId] ?? [];
    }

    /** @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string} */
    public function createFolder(string $parentId, string $name): array
    {
        return $this->append($parentId, ['id' => 'demo-folder-'.bin2hex(random_bytes(4)), 'kind' => 'folder', 'name' => $name, 'size' => null, 'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)]);
    }

    /** @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string} */
    public function upload(string $folderId, string $name, int $size): array
    {
        return $this->append($folderId, ['id' => 'demo-file-'.bin2hex(random_bytes(4)), 'kind' => 'file', 'name' => $name, 'size' => $size, 'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)]);
    }

    public function remove(string $folderId, string $itemId): void
    {
        $state = $this->load();
        $state[$folderId] = array_values(array_filter($state[$folderId] ?? [], static fn (array $i) => $i['id'] !== $itemId));
        $this->save($state);
    }

    public function rename(string $itemId, string $name): void
    {
        $state = $this->load();
        foreach ($state as $folderId => $items) {
            foreach ($items as $i => $item) {
                if ($item['id'] === $itemId) {
                    $state[$folderId][$i]['name'] = $name;
                    $this->save($state);

                    return;
                }
            }
        }
    }

    public function move(string $itemId, ?string $targetFolderId): void
    {
        $state = $this->load();
        foreach ($state as $folderId => $items) {
            foreach ($items as $i => $item) {
                if ($item['id'] === $itemId) {
                    unset($state[$folderId][$i]);
                    $state[$folderId] = array_values($state[$folderId]);
                    $state[$targetFolderId ?? 'demo-root'][] = $item;
                    $this->save($state);

                    return;
                }
            }
        }
    }

    /**
     * @param array{id: string, kind: string, name: string, size: ?int, updatedAt: string} $item
     *
     * @return array{id: string, kind: string, name: string, size: ?int, updatedAt: string}
     */
    private function append(string $folderId, array $item): array
    {
        $state = $this->load();
        $state[$folderId][] = $item;
        $this->save($state);

        return $item;
    }

    /** @return array<string, list<array{id: string, kind: string, name: string, size: ?int, updatedAt: string}>> */
    private function load(): array
    {
        if (!is_file($this->storagePath)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->storagePath), true);

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, list<array{id: string, kind: string, name: string, size: ?int, updatedAt: string}>> $state */
    private function save(array $state): void
    {
        @mkdir(\dirname($this->storagePath), 0775, true);
        file_put_contents($this->storagePath, json_encode($state, \JSON_THROW_ON_ERROR));
    }
}
