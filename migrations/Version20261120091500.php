<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add interactive seat map configuration to events and seat_label with unique (event_id, seat_label) constraint to tickets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE events ADD seat_map_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD seat_map_layout VARCHAR(32) DEFAULT 'theatre' NOT NULL, ADD seat_rows INT DEFAULT 8 NOT NULL, ADD seats_per_row INT DEFAULT 10 NOT NULL");

        $this->addSql('ALTER TABLE tickets ADD seat_label VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_tickets_event_seat ON tickets (event_id, seat_label)');

        // Enable interactive theatre seat map for existing theatre events (e.g., 90 seats -> 9 rows x 10 seats)
        $this->addSql("UPDATE events SET seat_map_enabled = 1, seat_map_layout = 'theatre', seat_rows = 9, seats_per_row = 10 WHERE category = 'theatre' AND total_seats = 90");
        $this->addSql("UPDATE events SET seat_map_enabled = 1, seat_map_layout = 'theatre', seat_rows = GREATEST(1, LEAST(26, CEIL(total_seats / 10))), seats_per_row = 10 WHERE category = 'theatre' AND seat_map_enabled = 0");
        $this->addSql("UPDATE events SET total_seats = seat_rows * seats_per_row WHERE seat_map_enabled = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_tickets_event_seat ON tickets');
        $this->addSql('ALTER TABLE tickets DROP seat_label');

        $this->addSql('ALTER TABLE events DROP seat_map_enabled, DROP seat_map_layout, DROP seat_rows, DROP seats_per_row');
    }
}
