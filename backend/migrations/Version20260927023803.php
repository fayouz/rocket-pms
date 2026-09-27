<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927023803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domotique: connectors (a plugin of the catalogue configured for a property).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE connector (id UUID NOT NULL, plugin_id VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, config JSON NOT NULL, enabled BOOLEAN NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_result VARCHAR(300) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, property_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_148C456E549213EC ON connector (property_id)');
        $this->addSql('ALTER TABLE connector ADD CONSTRAINT FK_148C456E549213EC FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE connector DROP CONSTRAINT FK_148C456E549213EC');
        $this->addSql('DROP TABLE connector');
    }
}
