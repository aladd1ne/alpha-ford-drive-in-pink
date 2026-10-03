<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commercial test drive validation: test drive status on reservations, and the
 * solidarity fund contributions (at most one per reservation).
 */
final class Version20261003140650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Test drive validation and solidarity fund contributions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fund_contribution (id INT AUTO_INCREMENT NOT NULL, client_amount INT NOT NULL, alpha_ford_amount INT NOT NULL, amount INT NOT NULL, validated_by VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, UNIQUE INDEX uniq_fund_contribution_reservation (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fund_contribution ADD CONSTRAINT FK_3D27182EB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE reservation ADD test_drive_status VARCHAR(20) DEFAULT \'to_validate\' NOT NULL, ADD test_drive_completed_at DATETIME DEFAULT NULL, ADD test_drive_validated_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_reservation_test_drive_status ON reservation (test_drive_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution DROP FOREIGN KEY FK_3D27182EB83297E7');
        $this->addSql('DROP TABLE fund_contribution');
        $this->addSql('DROP INDEX idx_reservation_test_drive_status ON reservation');
        $this->addSql('ALTER TABLE reservation DROP test_drive_status, DROP test_drive_completed_at, DROP test_drive_validated_by');
    }
}
