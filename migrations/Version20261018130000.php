<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cagnotte: source of the amounts added by hand (donation or purchase of a product).
 */
final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cagnotte contribution source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution ADD source VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution DROP source');
    }
}
