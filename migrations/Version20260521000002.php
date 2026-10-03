<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AdminUser table; add archived to category/booking; add updated_at to booking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            roles JSON NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE category ADD archived TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE booking ADD archived TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE booking ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('ALTER TABLE category DROP archived');
        $this->addSql('ALTER TABLE booking DROP archived, DROP updated_at');
    }
}
