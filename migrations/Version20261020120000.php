<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cagnotte: name, phone and e-mail of a donor or buyer who is not registered on the site.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cagnotte donors not registered on the site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution ADD donor_name VARCHAR(120) DEFAULT NULL, ADD donor_phone VARCHAR(30) DEFAULT NULL, ADD donor_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution DROP donor_name, DROP donor_phone, DROP donor_email');
    }
}
