<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cagnotte corrections: an admin can fix the amount of a credit, the last correction
 * (who, when, why) is kept on it.
 */
final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cagnotte amount corrections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution ADD corrected_by VARCHAR(180) DEFAULT NULL, ADD corrected_at DATETIME DEFAULT NULL, ADD correction_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution DROP corrected_by, DROP corrected_at, DROP correction_reason');
    }
}
