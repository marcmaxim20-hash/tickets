<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom seat_map_config JSON column to events so Admins and Organizers can customize exact seat positions, aisles, zones, and stage placement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD seat_map_config JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP seat_map_config');
    }
}
