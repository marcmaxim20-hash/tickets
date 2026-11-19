<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create customers table, link tickets to customer accounts, add used_at single-use scan tracking, and backfill QR codes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customers (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_customers_email (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("ALTER TABLE tickets ADD customer_id INT DEFAULT NULL, ADD used_at DATETIME DEFAULT NULL, CHANGE ticket_type ticket_type VARCHAR(32) DEFAULT 'festival' NOT NULL");
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF49395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_tickets_customer ON tickets (customer_id)');

        $this->addSql("UPDATE tickets SET ticket_type = 'festival', qr_token = LOWER(CONCAT(HEX(RANDOM_BYTES(12)), LPAD(HEX(id), 8, '0'))), qr_expires_at = DATE_ADD(created_at, INTERVAL 24 HOUR) WHERE qr_token IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF49395C3F3');
        $this->addSql('DROP INDEX idx_tickets_customer ON tickets');
        $this->addSql('ALTER TABLE tickets DROP customer_id, DROP used_at');
        $this->addSql('DROP TABLE customers');
    }
}
