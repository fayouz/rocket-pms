<?php

namespace App\Place;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Rocket Place used whenever ROCKET_PLACE_URL / ROCKET_PLACE_TOKEN are not configured: a tiny in-process imitation of
 * the endpoints PMS uses (same paths, same JSON shapes), so the app and its functional tests stay fully offline.
 * State is kept in a small JSON file (var/demo-place-<env>.json) since, like a real HTTP call, it must survive across
 * requests (cleanings included, find-or-create by externalRef as the real API). Two demo places ("Le port", "Les vignes") each with one fictitious lock; nothing is ever sent to a lock.
 */
final class DemoPlace
{
    public const PORT = '0192f7c4-0000-7000-8000-000000000001';
    public const VIGNES = '0192f7c4-0000-7000-8000-000000000002';

    public function __construct(private readonly string $demoPlacePath)
    {
    }

    public function reset(): void
    {
        if (is_file($this->demoPlacePath)) {
            unlink($this->demoPlacePath);
        }
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>      $query
     *
     * @return array<mixed>
     */
    public function handle(string $method, string $path, ?array $json, array $query): array
    {
        $s = $this->load();
        $json ??= [];
        $uuid = '([0-9a-f-]{36})';
        $m = [];
        $route = static function (string $verb, string $pattern) use ($method, $path, &$m): bool {
            return $verb === $method && 1 === preg_match('#^'.$pattern.'$#', $path, $m);
        };

        $result = match (true) {
            $route('GET', '/api/places') => array_values($s['places']),
            $route('POST', '/api/places') => $this->createPlace($s, $json),
            $route('GET', "/api/places/$uuid") => $this->place($s, $m[1]),
            $route('PATCH', "/api/places/$uuid") => $this->patchPlace($s, $m[1], $json),
            $route('GET', '/api/locks') => ['demo' => true, 'locks' => array_map(fn (array $l) => $this->lockView($s, $l), array_values($s['locks']))],
            $route('PUT', '/api/locks/(\d+)') => $this->linkLock($s, (int) $m[1], $json),
            $route('GET', "/api/places/$uuid/locks") => ['demo' => true, 'locks' => array_values(array_map(fn (array $l) => $this->lockView($s, $l), array_filter($s['locks'], fn (array $l) => $l['placeId'] === $this->place($s, $m[1])['id'])))],
            $route('GET', "/api/places/$uuid/access-grants") => array_values(array_filter($s['grants'], fn (array $g) => $g['placeId'] === $this->place($s, $m[1])['id'])),
            $route('POST', "/api/places/$uuid/access-grants") => $this->planGrant($s, $m[1], $json),
            $route('POST', "/api/access-grants/$uuid/send") => $this->grant($s, $m[1]) && throw new HttpException(400, 'Mode démo (ROCKET_PLACE_URL non configuré) : aucun code n’est envoyé à la serrure.'),
            $route('POST', "/api/access-grants/$uuid/revoke") => $this->revokeGrant($s, $m[1]),
            $route('GET', "/api/places/$uuid/cleanings") => array_values(array_filter($s['cleanings'] ?? [], fn (array $c) => $c['placeId'] === $this->place($s, $m[1])['id'])),
            $route('POST', "/api/places/$uuid/cleanings") => $this->createCleaning($s, $m[1], $json),
            $route('PATCH', "/api/cleanings/$uuid") => $this->patchCleaning($s, $m[1], $json),
            $route('GET', "/api/places/$uuid/domotique") => $this->domotique($s, $m[1]),
            $route('GET', "/api/places/$uuid/documents") => $this->documents($s, $m[1], (string) ($query['folder'] ?? '')),
            $route('POST', "/api/places/$uuid/documents/folders") => $this->addDocument($s, $m[1], 'folder', (string) ($json['name'] ?? ''), null, $json['folder'] ?? null),
            $route('POST', "/api/places/$uuid/documents/upload") => $this->addDocument($s, $m[1], 'file', (string) ($json['name'] ?? ''), (int) ($json['size'] ?? 0), $json['folder'] ?? null),
            $route('PATCH', "/api/places/$uuid/documents/([a-z]+:[\w-]+)") => $this->patchDocument($s, $m[1], $m[2], $json),
            $route('DELETE', "/api/places/$uuid/documents/([a-z]+:[\w-]+)") => $this->deleteDocument($s, $m[1], $m[2]),
            $route('GET', '/api/stock-items') => array_values($s['stockItems']),
            $route('GET', '/api/stock-levels') => array_values(array_filter($s['stockLevels'], static fn (array $l) => !isset($query['place']) || $l['place'] === $query['place'])),
            $route('PATCH', "/api/stock-levels/$uuid") => $this->patchLevel($s, $m[1], $json),
            default => throw new HttpException(404, 'Ressource inconnue de Rocket Place (démo).'),
        };
        $this->save($s);

        return $result;
    }

    public function content(string $path): string
    {
        if (!preg_match('#^/api/places/([0-9a-f-]{36})/documents/file:([\w-]+)/content$#', $path, $m)) {
            throw new HttpException(404, 'Document introuvable.');
        }
        $s = $this->load();
        $this->findDocument($s, $m[1], 'file:'.$m[2]);

        return "Document de démonstration.\n";
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function place(array $s, string $id): array
    {
        return $s['places'][$id] ?? throw new HttpException(404, 'Lieu introuvable.');
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function createPlace(array &$s, array $json): array
    {
        $name = trim((string) ($json['name'] ?? ''));
        if ('' === $name) {
            throw new HttpException(422, 'name: Cette valeur ne doit pas être vide.');
        }
        $id = Uuid::v7()->toRfc4122();
        $s['places'][$id] = self::newPlace($id, $name, (string) ($json['color'] ?? ''), $json['latitude'] ?? null, $json['longitude'] ?? null, $json['address'] ?? null);

        return $s['places'][$id];
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function patchPlace(array &$s, string $id, array $json): array
    {
        $this->place($s, $id);
        foreach (['name', 'address', 'color', 'latitude', 'longitude'] as $k) {
            if (\array_key_exists($k, $json)) {
                $s['places'][$id][$k] = $json[$k];
            }
        }

        return $s['places'][$id];
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $l @return array<string, mixed> */
    private function lockView(array $s, array $l): array
    {
        $placeId = $l['placeId'];
        unset($l['placeId']);

        return $l + ['provider' => 'nuki', 'placeId' => $placeId, 'place' => null === $placeId ? null : ($s['places'][$placeId]['name'] ?? null)];
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function linkLock(array &$s, int $id, array $json): array
    {
        if (!isset($s['locks'][(string) $id])) {
            throw new HttpException(404, 'Serrure inconnue : lance d’abord la synchronisation.');
        }
        if (\array_key_exists('place', $json)) {
            $s['locks'][(string) $id]['placeId'] = null === $json['place'] ? null : $this->place($s, (string) $json['place'])['id'];
        }

        return ['ok' => true];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function grant(array $s, string $id): array
    {
        return $s['grants'][$id] ?? throw new HttpException(404, 'Accès introuvable.');
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function planGrant(array &$s, string $placeId, array $json): array
    {
        $place = $this->place($s, $placeId);
        $lockId = (int) ($json['lockId'] ?? 0);
        if (!isset($s['locks'][(string) $lockId])) {
            throw new HttpException(422, 'Serrure inconnue.');
        }
        $label = trim((string) ($json['label'] ?? ''));
        if ('' === $label || mb_strlen($label) > 120) {
            throw new HttpException(422, 'Libellé requis (120 caractères au plus).');
        }
        try {
            $from = new \DateTimeImmutable((string) ($json['validFrom'] ?? ''));
            $until = new \DateTimeImmutable((string) ($json['validUntil'] ?? ''));
        } catch (\Exception) {
            throw new HttpException(422, 'Dates invalides.');
        }
        if ($until <= $from) {
            throw new HttpException(422, 'La fin doit suivre le début.');
        }
        $taken = array_column(array_filter($s['grants'], static fn (array $g) => $g['lockId'] === $lockId), 'code');
        do {
            $code = '';
            for ($i = 0; $i < 6; ++$i) {
                $code .= (string) random_int(1, 9);
            }
        } while (str_starts_with($code, '12') || \in_array($code, $taken, true));
        $id = Uuid::v7()->toRfc4122();
        $s['grants'][$id] = [
            'id' => $id, 'placeId' => $place['id'], 'lockId' => $lockId, 'label' => $label, 'code' => $code,
            'validFrom' => $from->format(\DATE_ATOM), 'validUntil' => $until->format(\DATE_ATOM), 'status' => 'planned',
            'externalRef' => null === ($json['externalRef'] ?? null) ? null : (string) $json['externalRef'],
            'error' => null, 'sentAt' => null, 'revokedAt' => null,
        ];

        return $s['grants'][$id];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function revokeGrant(array &$s, string $id): array
    {
        $this->grant($s, $id);
        $s['grants'][$id]['status'] = 'revoked';
        $s['grants'][$id]['revokedAt'] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        return $s['grants'][$id];
    }

    /** Find-or-create by externalRef, as Rocket Place (201 new, 200 existing unchanged). @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function createCleaning(array &$s, string $placeId, array $json): array
    {
        $place = $this->place($s, $placeId);
        $s['cleanings'] ??= [];
        $ref = null === ($json['externalRef'] ?? null) ? null : mb_substr(trim((string) $json['externalRef']), 0, 120);
        foreach ($s['cleanings'] as $c) {
            if (null !== $ref && $c['placeId'] === $place['id'] && $c['externalRef'] === $ref) {
                return $c;
            }
        }
        $at = self::cleaningDate($json['scheduledAt'] ?? null) ?? throw new HttpException(422, 'scheduledAt requis.');
        $due = self::cleaningDate($json['dueAt'] ?? null);
        if (null !== $due && $due < $at) {
            throw new HttpException(422, 'dueAt doit suivre scheduledAt.');
        }
        $id = Uuid::v7()->toRfc4122();
        $s['cleanings'][$id] = [
            'id' => $id, 'placeId' => $place['id'], 'placeName' => $place['name'], 'label' => mb_substr(trim((string) ($json['label'] ?? '')) ?: 'Ménage', 0, 120),
            'scheduledAt' => $at->format(\DATE_ATOM), 'dueAt' => $due?->format(\DATE_ATOM), 'status' => 'todo', 'late' => false,
            'assignee' => null === ($json['assigneeEmail'] ?? null) ? null : ['id' => null, 'email' => (string) $json['assigneeEmail'], 'name' => (string) $json['assigneeEmail']],
            'externalRef' => $ref, 'notes' => null === ($json['notes'] ?? null) ? null : (string) $json['notes'],
            'checklist' => [], 'photos' => [], 'stockReports' => [], 'startedAt' => null, 'completedAt' => null,
        ];

        return $s['cleanings'][$id];
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function patchCleaning(array &$s, string $id, array $json): array
    {
        $c = $s['cleanings'][$id] ?? throw new HttpException(404, 'Ménage introuvable.');
        if (isset($json['scheduledAt'])) {
            $c['scheduledAt'] = (self::cleaningDate($json['scheduledAt']) ?? throw new HttpException(422, 'scheduledAt invalide.'))->format(\DATE_ATOM);
        }
        if (\array_key_exists('dueAt', $json)) {
            $due = self::cleaningDate($json['dueAt']);
            if (null !== $due && $due < new \DateTimeImmutable($c['scheduledAt'])) {
                throw new HttpException(422, 'dueAt doit suivre scheduledAt.');
            }
            $c['dueAt'] = $due?->format(\DATE_ATOM);
        }
        if (\array_key_exists('status', $json)) {
            if (!\in_array($json['status'], ['todo', 'in_progress', 'done', 'cancelled'], true)) {
                throw new HttpException(422, 'Statut invalide.');
            }
            $c['status'] = $json['status'];
        }
        $s['cleanings'][$id] = $c;

        return $c;
    }

    private static function cleaningDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new HttpException(422, 'Date invalide.');
        }
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function domotique(array $s, string $placeId): array
    {
        $this->place($s, $placeId);

        return ['sections' => [[
            'connectorId' => 'demo-homey-'.$placeId, 'name' => 'Homey (démo)', 'pluginId' => 'homey', 'pluginName' => 'Homey', 'icon' => 'i-lucide-house-wifi',
            'cards' => [
                ['title' => 'Salon - thermostat', 'icon' => 'i-lucide-thermometer', 'items' => [['label' => 'Température', 'value' => '20,5 °C'], ['label' => 'Consigne', 'value' => '19 °C']]],
                ['title' => 'Entrée - détecteur', 'icon' => 'i-lucide-radar', 'items' => [['label' => 'Mouvement', 'value' => 'non']]],
            ],
            'error' => null,
        ]]];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function documents(array &$s, string $placeId, string $folder): array
    {
        $this->place($s, $placeId);
        $root = 'demo-root-'.$placeId;
        if (!isset($s['documents'][$placeId])) {
            $s['documents'][$placeId] = ['file:demo-welcome-'.substr($placeId, -4) => ['id' => 'file:demo-welcome-'.substr($placeId, -4), 'kind' => 'file', 'name' => 'Bienvenue.pdf', 'size' => 12345, 'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'parent' => null]];
        }
        $parent = null;
        if ('' !== $folder) {
            $parent = $this->findDocument($s, $placeId, $folder)['id'];
        }
        $items = array_values(array_filter($s['documents'][$placeId], static fn (array $d) => $d['parent'] === $parent));

        return ['folderId' => $parent ?? $root, 'rootFolderId' => $root, 'items' => array_map(static fn (array $d) => array_diff_key($d, ['parent' => 1]), $items)];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function addDocument(array &$s, string $placeId, string $kind, string $name, ?int $size, mixed $folder): array
    {
        $this->documents($s, $placeId, '');
        $name = trim($name);
        if ('' === $name) {
            throw new HttpException(400, 'Le nom est requis.');
        }
        $parent = \is_string($folder) && '' !== $folder ? $this->findDocument($s, $placeId, $folder)['id'] : null;
        $id = $kind.':demo-'.bin2hex(random_bytes(4));
        $s['documents'][$placeId][$id] = ['id' => $id, 'kind' => $kind, 'name' => $name, 'size' => $size, 'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'parent' => $parent];

        return array_diff_key($s['documents'][$placeId][$id], ['parent' => 1]);
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function patchDocument(array &$s, string $placeId, string $itemId, array $json): array
    {
        $this->documents($s, $placeId, '');
        $this->findDocument($s, $placeId, $itemId);
        if (isset($json['name']) && '' !== trim((string) $json['name'])) {
            $s['documents'][$placeId][$itemId]['name'] = trim((string) $json['name']);
        }
        if (\array_key_exists('folder', $json)) {
            $s['documents'][$placeId][$itemId]['parent'] = null === $json['folder'] ? null : $this->findDocument($s, $placeId, (string) $json['folder'])['id'];
        }

        return ['ok' => true];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function deleteDocument(array &$s, string $placeId, string $itemId): array
    {
        $this->documents($s, $placeId, '');
        $this->findDocument($s, $placeId, $itemId);
        unset($s['documents'][$placeId][$itemId]);

        return ['ok' => true];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function findDocument(array $s, string $placeId, string $itemId): array
    {
        return $s['documents'][$placeId][$itemId] ?? throw new HttpException(404, 'Document introuvable.');
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $json @return array<string, mixed> */
    private function patchLevel(array &$s, string $id, array $json): array
    {
        if (!isset($s['stockLevels'][$id])) {
            throw new HttpException(404, 'Niveau de stock introuvable.');
        }
        $level = (string) ($json['level'] ?? '');
        if (!\in_array($level, ['ok', 'low', 'empty'], true)) {
            throw new HttpException(422, 'level: Niveau inconnu.');
        }
        $s['stockLevels'][$id]['level'] = $level;

        return $s['stockLevels'][$id];
    }

    /** @return array<string, mixed> */
    private static function newPlace(string $id, string $name, string $color = '', mixed $lat = null, mixed $lng = null, mixed $address = null): array
    {
        return ['id' => $id, 'name' => $name, 'address' => $address, 'color' => $color, 'latitude' => $lat, 'longitude' => $lng, 'cloudFolderId' => null];
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $raw = is_file($this->demoPlacePath) ? file_get_contents($this->demoPlacePath) : false;
        $state = false === $raw ? null : json_decode($raw, true);
        if (\is_array($state)) {
            return $state;
        }
        $ago = static fn (int $min) => (new \DateTimeImmutable('-'.$min.' minutes'))->format(\DATE_ATOM);
        $items = [
            'a0000000-0000-7000-8000-000000000001' => ['id' => 'a0000000-0000-7000-8000-000000000001', 'name' => 'Papier toilette', 'asin' => null, 'reorderQty' => 12, 'subscription' => false],
            'a0000000-0000-7000-8000-000000000002' => ['id' => 'a0000000-0000-7000-8000-000000000002', 'name' => 'Capsules café', 'asin' => null, 'reorderQty' => 50, 'subscription' => true],
        ];
        $levels = [];
        $n = 0;
        foreach ([self::PORT, self::VIGNES] as $placeId) {
            foreach ($items as $itemId => $_) {
                $id = sprintf('b0000000-0000-7000-8000-%012d', ++$n);
                $levels[$id] = ['id' => $id, 'place' => '/api/places/'.$placeId, 'item' => '/api/stock-items/'.$itemId, 'level' => 2 === $n ? 'low' : 'ok'];
            }
        }

        return [
            'places' => [self::PORT => self::newPlace(self::PORT, 'Le port', 'green'), self::VIGNES => self::newPlace(self::VIGNES, 'Les vignes', 'blue')],
            'locks' => [
                '90001' => ['id' => 90001, 'name' => 'Port - entrée', 'state' => 'Verrouillée', 'locked' => true, 'battery' => 82, 'batteryCritical' => false, 'keypadBatteryCritical' => false,
                    'logs' => [['date' => $ago(35), 'who' => 'Sofia Rossi (clavier)', 'action' => 1, 'trigger' => 255], ['date' => $ago(300), 'who' => '', 'action' => 2, 'trigger' => 2]], 'placeId' => self::PORT],
                '90002' => ['id' => 90002, 'name' => 'Vignes - entrée', 'state' => 'Déverrouillée', 'locked' => false, 'battery' => 14, 'batteryCritical' => true, 'keypadBatteryCritical' => false,
                    'logs' => [['date' => $ago(12), 'who' => 'Marc Durand (clavier)', 'action' => 1, 'trigger' => 255]], 'placeId' => self::VIGNES],
            ],
            'grants' => [],
            'documents' => [],
            'cleanings' => [],
            'stockItems' => $items,
            'stockLevels' => $levels,
        ];
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        if (!is_dir(\dirname($this->demoPlacePath))) {
            mkdir(\dirname($this->demoPlacePath), 0o775, true);
        }
        file_put_contents($this->demoPlacePath, json_encode($state, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), \LOCK_EX);
    }
}
