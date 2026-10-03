<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521143459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking RENAME INDEX uniq_e00cedde2c1e65cf TO UNIQ_E00CEDDEAEA34913');
        $this->addSql('ALTER TABLE bring_item RENAME INDEX idx_bi12469de2 TO IDX_6D0D46BA12469DE2');
        $this->addSql('ALTER TABLE extra RENAME INDEX idx_6a9b8e0812469de2 TO IDX_4D3F0D6512469DE2');
        $this->addSql('ALTER TABLE highlight RENAME INDEX idx_9fc3cbbe12469de2 TO IDX_C998D83412469DE2');
        $this->addSql('ALTER TABLE included_item RENAME INDEX idx_ii12469de2 TO IDX_65FE992812469DE2');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking RENAME INDEX uniq_e00ceddeaea34913 TO UNIQ_E00CEDDE2C1E65CF');
        $this->addSql('ALTER TABLE bring_item RENAME INDEX idx_6d0d46ba12469de2 TO IDX_BI12469DE2');
        $this->addSql('ALTER TABLE extra RENAME INDEX idx_4d3f0d6512469de2 TO IDX_6A9B8E0812469DE2');
        $this->addSql('ALTER TABLE highlight RENAME INDEX idx_c998d83412469de2 TO IDX_9FC3CBBE12469DE2');
        $this->addSql('ALTER TABLE included_item RENAME INDEX idx_65fe992812469de2 TO IDX_II12469DE2');
    }
}
