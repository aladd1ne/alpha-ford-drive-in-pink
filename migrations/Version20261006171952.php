<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Back-office manual entries: walk-in test drives (who added them) and cagnotte amounts
 * added by hand (no reservation, with a note).
 */
final class Version20261006171952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual test drives and manual cagnotte amounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution ADD note VARCHAR(255) DEFAULT NULL, CHANGE reservation_id reservation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD created_by VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Fails if manual amounts (no reservation) exist: delete them first.
        $this->addSql('ALTER TABLE fund_contribution DROP note, CHANGE reservation_id reservation_id INT NOT NULL');
        $this->addSql('ALTER TABLE reservation DROP created_by');
    }
}
