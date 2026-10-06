<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Staff roles: the former commercial accounts (cash in the test drives) become cashiers.
 * ROLE_COMMERCIAL is now only implied by ROLE_RESERVATIONS / ROLE_CASHIER (security.yaml).
 */
final class Version20261006190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Former commercial accounts become cashiers (ROLE_CASHIER)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE admin_user SET roles = '[\"ROLE_CASHIER\"]' WHERE roles LIKE '%\"ROLE_COMMERCIAL\"%' AND roles NOT LIKE '%\"ROLE_ADMIN\"%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE admin_user SET roles = '[\"ROLE_COMMERCIAL\"]' WHERE roles LIKE '%\"ROLE_CASHIER\"%' AND roles NOT LIKE '%\"ROLE_ADMIN\"%'");
    }
}
