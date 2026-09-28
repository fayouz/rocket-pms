<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rocket PMS becomes a client of Rocket Place: locks, keypad codes (now access grants) and documents move there.
 * Drops smart_lock/access_code and the Rocket Cloud folder id, deletes every non-Lodgify connector (physical
 * connectors are recreated on the place in Rocket Place), adds property.place_id.
 */
final class Version20260928102815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rocket Place client: property.place_id, drop smart_lock/access_code/cloud_folder_id and non-Lodgify connectors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_code DROP CONSTRAINT fk_81cc569e836d25dd');
        $this->addSql('ALTER TABLE smart_lock DROP CONSTRAINT fk_b85502e6549213ec');
        $this->addSql('ALTER TABLE smart_lock DROP CONSTRAINT fk_b85502e64d085745');
        $this->addSql('ALTER TABLE smart_lock DROP CONSTRAINT fk_b85502e68620a520');
        $this->addSql('DROP TABLE access_code');
        $this->addSql('DROP TABLE smart_lock');
        $this->addSql('ALTER TABLE property ADD place_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE property DROP cloud_folder_id');
        $this->addSql("DELETE FROM connector WHERE plugin_id <> 'lodgify'");
    }

    public function down(Schema $schema): void
    {
        // Structure only: the dropped locks, codes and connectors are not restored.
        $this->addSql('CREATE TABLE access_code (booking_id BIGINT NOT NULL, code VARCHAR(6) NOT NULL, valid_from TIMESTAMP(0) WITH TIME ZONE NOT NULL, valid_until TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, error TEXT DEFAULT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, lock_id BIGINT NOT NULL, PRIMARY KEY (booking_id))');
        $this->addSql('CREATE INDEX idx_81cc569e836d25dd ON access_code (lock_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_access_code_lock_code ON access_code (lock_id, code)');
        $this->addSql('CREATE TABLE smart_lock (nuki_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, property_id UUID DEFAULT NULL, external_id VARCHAR(120) DEFAULT NULL, connector_id UUID DEFAULT NULL, code_connector_id UUID DEFAULT NULL, PRIMARY KEY (nuki_id))');
        $this->addSql('CREATE INDEX idx_b85502e68620a520 ON smart_lock (code_connector_id)');
        $this->addSql('CREATE INDEX idx_b85502e6549213ec ON smart_lock (property_id)');
        $this->addSql('CREATE INDEX idx_b85502e64d085745 ON smart_lock (connector_id)');
        $this->addSql('ALTER TABLE access_code ADD CONSTRAINT fk_81cc569e836d25dd FOREIGN KEY (lock_id) REFERENCES smart_lock (nuki_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE smart_lock ADD CONSTRAINT fk_b85502e6549213ec FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE smart_lock ADD CONSTRAINT fk_b85502e64d085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE smart_lock ADD CONSTRAINT fk_b85502e68620a520 FOREIGN KEY (code_connector_id) REFERENCES connector (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE property ADD cloud_folder_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE property DROP place_id');
    }
}
