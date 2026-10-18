<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cagnotte: products sold for the cause (managed by admins), the donor (a registration)
 * of a donation and the product + quantity of a sale.
 */
final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cagnotte products, donors and product sales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, price INT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_D34A04AD5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fund_contribution ADD quantity INT DEFAULT NULL, ADD donor_id INT DEFAULT NULL, ADD product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fund_contribution ADD CONSTRAINT FK_3D27182E3DD7B7A7 FOREIGN KEY (donor_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE fund_contribution ADD CONSTRAINT FK_3D27182E4584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('CREATE INDEX IDX_3D27182E3DD7B7A7 ON fund_contribution (donor_id)');
        $this->addSql('CREATE INDEX IDX_3D27182E4584665A ON fund_contribution (product_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fund_contribution DROP FOREIGN KEY FK_3D27182E3DD7B7A7');
        $this->addSql('ALTER TABLE fund_contribution DROP FOREIGN KEY FK_3D27182E4584665A');
        $this->addSql('DROP INDEX IDX_3D27182E3DD7B7A7 ON fund_contribution');
        $this->addSql('DROP INDEX IDX_3D27182E4584665A ON fund_contribution');
        $this->addSql('ALTER TABLE fund_contribution DROP quantity, DROP donor_id, DROP product_id');
        $this->addSql('DROP TABLE product');
    }
}
