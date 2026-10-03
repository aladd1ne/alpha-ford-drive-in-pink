<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validating a test drive now confirms a pending booking: apply that to the test drives
 * validated before the rule existed.
 */
final class Version20261003150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Confirm pending bookings whose test drive is already validated';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE reservation SET status = 'confirmed' WHERE status = 'pending' AND test_drive_status = 'completed'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible data fix: confirmed bookings cannot be told apart from those confirmed by this migration.
    }
}
