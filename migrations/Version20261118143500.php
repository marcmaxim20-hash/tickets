<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118143500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password recovery columns (reset_token_hash, reset_expires_at) to customers table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers ADD reset_token_hash VARCHAR(64) DEFAULT NULL, ADD reset_expires_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_customers_reset_token ON customers (reset_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_customers_reset_token ON customers');
        $this->addSql('ALTER TABLE customers DROP reset_token_hash, DROP reset_expires_at');
    }
}
