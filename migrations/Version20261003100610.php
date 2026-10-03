<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drive in Pink initial schema: back-office users and test drive reservations
 * (unique seat per experience + date + vehicle + slot).
 */
final class Version20261003100610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drive in Pink initial schema (admin users, reservations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, experience VARCHAR(20) NOT NULL, full_name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, email VARCHAR(180) NOT NULL, reservation_date DATE NOT NULL, vehicle VARCHAR(30) DEFAULT NULL, slot_start VARCHAR(5) DEFAULT NULL, seat SMALLINT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_reservation_experience_date (experience, reservation_date), INDEX idx_reservation_status (status), UNIQUE INDEX uniq_reservation_seat (experience, reservation_date, vehicle, slot_start, seat), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE reservation');
    }
}
