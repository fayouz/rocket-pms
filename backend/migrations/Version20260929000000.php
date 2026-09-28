<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connector secrets move to the rocket-core vault: the config key "secretVar" (name of a .env variable CONNECTOR_X)
 * becomes "secret" (name of the vault secret connector_x, what `app:secrets:migrate-env` imports the variable into).
 */
final class Version20260929000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connector configs: secretVar (CONNECTOR_X) → secret (connector_x, rocket-core vault)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE connector SET config = ((config::jsonb - 'secretVar') || jsonb_build_object('secret', lower(config::jsonb ->> 'secretVar')))::json
            WHERE config::jsonb ->> 'secretVar' ~ '^CONNECTOR_[A-Z0-9_]{1,60}$'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE connector SET config = ((config::jsonb - 'secret') || jsonb_build_object('secretVar', upper(config::jsonb ->> 'secret')))::json
            WHERE config::jsonb ->> 'secret' ~ '^connector_[a-z0-9_]{1,60}$'
            SQL);
    }
}
