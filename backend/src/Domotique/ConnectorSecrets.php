<?php

namespace App\Domotique;

use App\Secrets\IntegrationSecrets;
use Rocket\Core\Entity\Secret;
use Rocket\Core\Secrets\SecretsException;
use Rocket\Core\Secrets\SecretVault;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Secrets of the connectors: the config only holds the NAME of a secret of the rocket-core vault (field "secret",
 * chosen with the SecretField of the layer), never the value, which is read server side. A connector can never use
 * the integration secrets of the app itself (App\Secrets\IntegrationSecrets, e.g. the Rocket Mailer token).
 *
 * Transition: former configs held the name of a .env variable CONNECTOR_X ("secretVar"); they now designate the
 * secret "connector_x" (what `rocket:secrets:import-env CONNECTOR_` and `app:secrets:migrate-env` create), and until
 * it is in the vault the variable CONNECTOR_X is still read (logged as deprecated).
 */
final class ConnectorSecrets
{
    public const FIELD = 'secret';
    public const LEGACY_FIELD = 'secretVar';
    private const LEGACY_ENV_PATTERN = '/^CONNECTOR_[A-Z0-9_]{1,60}$/';

    public function __construct(private readonly SecretVault $vault)
    {
    }

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match(Secret::NAME_PATTERN, $name) && !\array_key_exists($name, IntegrationSecrets::LEGACY_ENV);
    }

    /** Name of the secret designated by a connector config ('' if none), former "secretVar" configs included. */
    public static function nameIn(array $config, string $field = self::FIELD): string
    {
        $name = trim((string) ($config[$field] ?? ''));
        if ('' === $name && self::FIELD === $field) {
            $name = self::fromLegacyVariable(trim((string) ($config[self::LEGACY_FIELD] ?? '')));
        }

        return $name;
    }

    /** CONNECTOR_NUKI_SALON → connector_nuki_salon ('' if not a former connector variable). */
    public static function fromLegacyVariable(string $variable): string
    {
        return 1 === preg_match(self::LEGACY_ENV_PATTERN, $variable) ? strtolower($variable) : '';
    }

    /** connector_nuki_salon → CONNECTOR_NUKI_SALON (null if the name does not come from a former variable). */
    public static function legacyVariable(string $name): ?string
    {
        $variable = strtoupper($name);

        return str_starts_with($name, 'connector_') && 1 === preg_match(self::LEGACY_ENV_PATTERN, $variable) ? $variable : null;
    }

    /** Value of the secret (never returned to the browser). */
    public function read(string $name, string $label): string
    {
        if ('' === $name) {
            throw new HttpException(400, \sprintf('« %s » : aucun secret indiqué.', $label));
        }
        if (!self::isValidName($name)) {
            throw new HttpException(400, \sprintf('« %s » : secret « %s » non autorisé pour un connecteur.', $label, $name));
        }
        try {
            $variable = self::legacyVariable($name);
            $value = null === $variable ? $this->vault->find($name) : $this->vault->getOrEnv($name, $variable);
        } catch (SecretsException $e) {
            throw new HttpException(503, \sprintf('« %s » : coffre des secrets illisible (%s).', $label, $e->getMessage()));
        }
        if (null === $value || '' === $value) {
            throw new HttpException(400, \sprintf('Secret « %s » absent du coffre (Administration → Secrets).', $name));
        }

        return $value;
    }

    public function isConfigured(string $name): bool
    {
        if ('' === $name || !self::isValidName($name)) {
            return false;
        }
        if ($this->vault->has($name)) {
            return true;
        }
        $variable = self::legacyVariable($name);
        $value = null === $variable ? null : ($_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable));

        return \is_string($value) && '' !== $value;
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
