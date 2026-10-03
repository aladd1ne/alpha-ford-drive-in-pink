<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop event_image; make hero/panel images nullable; add included_item and bring_item tables';
    }

    public function up(Schema $schema): void
    {
        // Drop gallery table — data intentionally lost (was placeholder URLs only)
        $this->addSql('ALTER TABLE event_image DROP FOREIGN KEY FK_C3E77A0412469DE2');
        $this->addSql('DROP TABLE event_image');

        // Make image columns nullable (no real images yet — uploaded via admin)
        $this->addSql('ALTER TABLE category MODIFY hero_image VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE category MODIFY panel_image VARCHAR(500) DEFAULT NULL');

        // "What's included" list
        $this->addSql('CREATE TABLE included_item (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            icon VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            INDEX IDX_II12469DE2 (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE included_item ADD CONSTRAINT FK_II12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');

        // "What to bring" list
        $this->addSql('CREATE TABLE bring_item (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            icon VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            INDEX IDX_BI12469DE2 (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE bring_item ADD CONSTRAINT FK_BI12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE included_item DROP FOREIGN KEY FK_II12469DE2');
        $this->addSql('ALTER TABLE bring_item DROP FOREIGN KEY FK_BI12469DE2');
        $this->addSql('DROP TABLE included_item');
        $this->addSql('DROP TABLE bring_item');
        $this->addSql('ALTER TABLE category MODIFY hero_image VARCHAR(500) NOT NULL');
        $this->addSql('ALTER TABLE category MODIFY panel_image VARCHAR(500) NOT NULL');
    }
}
