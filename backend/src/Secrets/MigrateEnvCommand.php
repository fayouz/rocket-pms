<?php

namespace App\Secrets;

use App\Domotique\ConnectorSecrets;
use App\Domotique\PluginRegistry;
use App\Repository\ConnectorRepository;
use Rocket\Core\Secrets\SecretVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the legacy environment variables of the integration secrets (IntegrationSecrets::LEGACY_ENV) into the vault,
 * under their secret name, and the CONNECTOR_X variables used by the connectors as the secrets connector_x (the name
 * their config now holds, see App\Domotique\ConnectorSecrets). Idempotent: an existing secret is kept unless --overwrite. Remove the variables from the
 * .env files afterwards.
 */
#[AsCommand(name: 'app:secrets:migrate-env', description: 'Import the legacy environment variables of the integration secrets into the secrets vault.')]
final class MigrateEnvCommand
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly ConnectorRepository $connectors,
        private readonly PluginRegistry $plugins,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Replace the secrets that already exist')] bool $overwrite = false,
        #[Option(description: 'Show what would be imported, without importing')] bool $dryRun = false,
    ): int {
        if (!$this->vault->isConfigured()) {
            $io->error((string) $this->vault->configurationError());

            return Command::FAILURE;
        }
        $rows = [];
        $variables = IntegrationSecrets::LEGACY_ENV;
        foreach ($this->connectors->findAll() as $connector) {
            foreach ($this->plugins->get($connector->getPluginId())->fields() as $field) {
                $name = ($field['secret'] ?? false) ? ConnectorSecrets::nameIn($connector->getConfig(), $field['key']) : '';
                if (null !== $variable = ConnectorSecrets::legacyVariable($name)) {
                    $variables[$name] = $variable;
                }
            }
        }
        foreach ($variables as $name => $env) {
            $value = $_SERVER[$env] ?? $_ENV[$env] ?? getenv($env);
            if (!\is_string($value) || '' === $value) {
                $rows[] = [$env, $name, 'absente'];
                continue;
            }
            if (!$overwrite && $this->vault->has($name)) {
                $rows[] = [$env, $name, 'déjà dans le coffre'];
                continue;
            }
            if (!$dryRun) {
                $this->vault->set($name, $value);
            }
            $rows[] = [$env, $name, $dryRun ? 'à importer' : 'importée'];
        }
        $io->table(['Variable', 'Secret', 'Résultat'], $rows);
        $io->success($dryRun ? 'Simulation : rien n’a été enregistré.' : 'Terminé. Retirez ces variables des fichiers .env.');

        return Command::SUCCESS;
    }
}
