<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tier_key and price_cents to tickets for multi-tier ticket pricing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tickets ADD tier_key VARCHAR(40) DEFAULT 'full_pass' NOT NULL, ADD price_cents INT DEFAULT 3900 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets DROP tier_key, DROP price_cents');
    }
}
