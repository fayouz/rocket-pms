<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926193706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE access_code (booking_id BIGINT NOT NULL, code VARCHAR(6) NOT NULL, valid_from TIMESTAMP(0) WITH TIME ZONE NOT NULL, valid_until TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, error TEXT DEFAULT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, lock_id BIGINT NOT NULL, PRIMARY KEY (booking_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_access_code_lock_code ON access_code (lock_id, code)');
        $this->addSql('CREATE INDEX IDX_81CC569E836D25DD ON access_code (lock_id)');
        $this->addSql('CREATE TABLE property (id UUID NOT NULL, name VARCHAR(120) NOT NULL, lodgify_property_id INT DEFAULT NULL, lodgify_name VARCHAR(255) DEFAULT NULL, color VARCHAR(16) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, cloud_folder_id VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8BF21CDE8864AD3D ON property (lodgify_property_id)');
        $this->addSql('CREATE TABLE smart_lock (nuki_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, property_id UUID DEFAULT NULL, PRIMARY KEY (nuki_id))');
        $this->addSql('CREATE INDEX IDX_B85502E6549213EC ON smart_lock (property_id)');
        $this->addSql('ALTER TABLE access_code ADD CONSTRAINT FK_81CC569E836D25DD FOREIGN KEY (lock_id) REFERENCES smart_lock (nuki_id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE smart_lock ADD CONSTRAINT FK_B85502E6549213EC FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_code DROP CONSTRAINT FK_81CC569E836D25DD');
        $this->addSql('ALTER TABLE smart_lock DROP CONSTRAINT FK_B85502E6549213EC');
        $this->addSql('DROP TABLE access_code');
        $this->addSql('DROP TABLE property');
        $this->addSql('DROP TABLE smart_lock');
    }
}
