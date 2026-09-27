<?php

namespace App\Domotique;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A connector secret is NEVER stored in the database: the config only holds the NAME of a .env variable, always
 * prefixed CONNECTOR_ so a connector can never read another secret of the app (e.g. the Lodgify key).
 */
final class SecretEnv
{
    private const PATTERN = '/^CONNECTOR_[A-Z0-9_]{1,60}$/';

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match(self::PATTERN, $name);
    }

    /** Value of the .env variable designated by $name (never returned to the browser). */
    public static function read(string $name, string $label): string
    {
        if ('' === $name) {
            throw new HttpException(400, \sprintf('« %s » : aucune variable indiquée.', $label));
        }
        if (!self::isValidName($name)) {
            throw new HttpException(400, \sprintf('« %s » : le nom doit commencer par CONNECTOR_ (lettres majuscules, chiffres, _).', $label));
        }
        $value = $_ENV[$name] ?? getenv($name);
        if (!\is_string($value) || '' === $value) {
            throw new HttpException(400, \sprintf('Variable %s absente de .env (ajoute-la puis redémarre l’application).', $name));
        }

        return $value;
    }

    public static function isConfigured(string $name): bool
    {
        return '' !== $name && \is_string($_ENV[$name] ?? getenv($name) ?: null);
    }

    /** Private / local network address (LAN, localhost, .local): the only place a secret may travel over plain http. */
    public static function isPrivateHost(string $hostname): bool
    {
        $h = strtolower(trim($hostname, '[]'));
        if ('localhost' === $h || str_ends_with($h, '.local') || '::1' === $h || str_starts_with($h, 'fe80:')) {
            return true;
        }
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $h, $m)) {
            return false;
        }
        [$a, $b] = [(int) $m[1], (int) $m[2]];

        return 10 === $a || 127 === $a || (172 === $a && $b >= 16 && $b <= 31) || (192 === $a && 168 === $b) || (100 === $a && $b >= 64 && $b <= 127);
    }
}
