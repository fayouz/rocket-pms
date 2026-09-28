<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Bilan: accounting entries of a property (charges and other income), amounts in cents. */
final class Version20260928200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bilan: accounting entries per property (expense)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense (id UUID NOT NULL, date DATE NOT NULL, amount_cents INT NOT NULL, category VARCHAR(32) NOT NULL, note VARCHAR(500) NOT NULL, document_ref VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, property_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2D3A8DA6549213ECAA9E377A ON expense (property_id, date)');
        $this->addSql('CREATE INDEX IDX_2D3A8DA6549213EC ON expense (property_id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6549213EC FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA6549213EC');
        $this->addSql('DROP TABLE expense');
    }
}
