<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120094000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable interactive 1-person-per-seat map by default for all events (including festivals and concerts) and backfill existing events & tickets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events CHANGE seat_map_enabled seat_map_enabled TINYINT(1) DEFAULT 1 NOT NULL');

        // Enable seat maps on existing Festival & Concert events with clean row x column dimensions
        $this->addSql("UPDATE events SET seat_map_enabled = 1, seat_map_layout = 'festival', seat_rows = 15, seats_per_row = 20 WHERE category = 'festival' AND total_seats = 300 AND seat_map_enabled = 0");
        $this->addSql("UPDATE events SET seat_map_enabled = 1, seat_map_layout = 'festival', seat_rows = 10, seats_per_row = 10 WHERE category = 'festival' AND total_seats = 100 AND seat_map_enabled = 0");
        $this->addSql("UPDATE events SET seat_map_enabled = 1, seat_map_layout = 'building', seat_rows = 12, seats_per_row = 15 WHERE category = 'concert' AND total_seats = 180 AND seat_map_enabled = 0");
        $this->addSql("UPDATE events SET seat_map_enabled = 1, seat_map_layout = CASE WHEN category = 'festival' THEN 'festival' WHEN category = 'saloon' THEN 'saloon' WHEN category = 'theatre' THEN 'theatre' ELSE 'building' END, seat_rows = GREATEST(1, LEAST(26, CEIL(total_seats / 10))), seats_per_row = 10 WHERE seat_map_enabled = 0");
        $this->addSql('UPDATE events SET total_seats = seat_rows * seats_per_row WHERE seat_map_enabled = 1');

        // Assign unique 1-person seats to existing event tickets that were purchased before seat maps were enabled
        $this->addSql("UPDATE tickets SET seat_label = 'K1' WHERE id = 28 AND event_id = 1 AND seat_label IS NULL");
        $this->addSql("UPDATE tickets SET seat_label = 'K2' WHERE id = 29 AND event_id = 1 AND seat_label IS NULL");
        $this->addSql("UPDATE tickets SET seat_label = 'B1' WHERE id = 27 AND event_id = 2 AND seat_label IS NULL");
        $this->addSql("UPDATE tickets SET seat_label = 'D1' WHERE id = 30 AND event_id = 2 AND seat_label IS NULL");
        $this->addSql("UPDATE tickets SET seat_label = 'D2' WHERE id = 31 AND event_id = 2 AND seat_label IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events CHANGE seat_map_enabled seat_map_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
