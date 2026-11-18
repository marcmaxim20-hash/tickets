<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create persistent tickets table for support requests and status tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tickets (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(180) NOT NULL,
            contact VARCHAR(180) NOT NULL,
            description LONGTEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_tickets_status (status),
            INDEX idx_tickets_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tickets');
    }
}
