<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Welcome book (livret d'accueil) and TV screen of a property: one row per property, content as JSON, TV token, guest-link salt. */
final class Version20260928180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Welcome book and TV screen per property (welcome_book)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE welcome_book (id UUID NOT NULL, content JSON NOT NULL, tv_token VARCHAR(64) NOT NULL, guest_salt VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, property_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4FC0E81DA64D4916 ON welcome_book (tv_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4FC0E81D549213EC ON welcome_book (property_id)');
        $this->addSql('ALTER TABLE welcome_book ADD CONSTRAINT FK_4FC0E81D549213EC FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE welcome_book');
    }
}
