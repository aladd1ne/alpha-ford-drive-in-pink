<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Andiamo Experience schema: category, event_image, highlight, extra, booking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE category (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            teaser VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            hero_image VARCHAR(500) NOT NULL,
            panel_image VARCHAR(500) NOT NULL,
            base_price NUMERIC(10, 2) NOT NULL,
            UNIQUE INDEX UNIQ_64C19C1989D9B62 (slug),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE event_image (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            image_path VARCHAR(500) NOT NULL,
            INDEX IDX_C3E77A0412469DE2 (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE highlight (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            icon VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            value VARCHAR(255) NOT NULL,
            INDEX IDX_9FC3CBBE12469DE2 (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE extra (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            price NUMERIC(10, 2) NOT NULL,
            INDEX IDX_6A9B8E0812469DE2 (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE booking (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            reference VARCHAR(20) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            email VARCHAR(255) NOT NULL,
            people_count INT NOT NULL,
            preferred_date DATE NOT NULL,
            selected_extras JSON DEFAULT NULL,
            total_price NUMERIC(10, 2) NOT NULL,
            deposit_amount NUMERIC(10, 2) NOT NULL,
            status VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_E00CEDDE2C1E65CF (reference),
            INDEX IDX_E00CEDDE12469DE2 (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE event_image ADD CONSTRAINT FK_C3E77A0412469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE highlight ADD CONSTRAINT FK_9FC3CBBE12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE extra ADD CONSTRAINT FK_6A9B8E0812469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE12469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_image DROP FOREIGN KEY FK_C3E77A0412469DE2');
        $this->addSql('ALTER TABLE highlight DROP FOREIGN KEY FK_9FC3CBBE12469DE2');
        $this->addSql('ALTER TABLE extra DROP FOREIGN KEY FK_6A9B8E0812469DE2');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE12469DE2');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE extra');
        $this->addSql('DROP TABLE highlight');
        $this->addSql('DROP TABLE event_image');
        $this->addSql('DROP TABLE category');
    }
}
