<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add festival QR ticket fields (ticket_type, qr_token, qr_expires_at) with 24-hour expiration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tickets ADD ticket_type VARCHAR(32) NOT NULL DEFAULT 'standard', ADD qr_token VARCHAR(64) DEFAULT NULL, ADD qr_expires_at DATETIME DEFAULT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_tickets_qr_token ON tickets (qr_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_tickets_qr_token ON tickets');
        $this->addSql('ALTER TABLE tickets DROP ticket_type, DROP qr_token, DROP qr_expires_at');
    }
}
