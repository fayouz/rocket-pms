<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927155132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SmartLock: optional connector (state) and codeConnector (codes) to reroute to another lock provider (Home Assistant, Homey, a second Nuki account...), plus externalId (entity/device id in that connector).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE smart_lock ADD external_id VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE smart_lock ADD connector_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE smart_lock ADD code_connector_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE smart_lock ADD CONSTRAINT FK_B85502E64D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE smart_lock ADD CONSTRAINT FK_B85502E68620A520 FOREIGN KEY (code_connector_id) REFERENCES connector (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B85502E64D085745 ON smart_lock (connector_id)');
        $this->addSql('CREATE INDEX IDX_B85502E68620A520 ON smart_lock (code_connector_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE smart_lock DROP CONSTRAINT FK_B85502E64D085745');
        $this->addSql('ALTER TABLE smart_lock DROP CONSTRAINT FK_B85502E68620A520');
        $this->addSql('DROP INDEX IDX_B85502E64D085745');
        $this->addSql('DROP INDEX IDX_B85502E68620A520');
        $this->addSql('ALTER TABLE smart_lock DROP external_id');
        $this->addSql('ALTER TABLE smart_lock DROP connector_id');
        $this->addSql('ALTER TABLE smart_lock DROP code_connector_id');
    }
}
