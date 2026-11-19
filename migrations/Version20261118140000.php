<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create events table (category, venue, total_seats, image_path, start_date, end_date), link tickets to events, and seed starter events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE events (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(180) NOT NULL,
            category VARCHAR(32) NOT NULL,
            venue VARCHAR(180) NOT NULL,
            total_seats INT NOT NULL,
            description LONGTEXT NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_events_category (category),
            INDEX idx_events_start_date (start_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE tickets ADD event_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF471F7E88B FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_tickets_event ON tickets (event_id)');

        $this->addSql("INSERT INTO events (title, category, venue, total_seats, description, image_path, start_date, end_date, created_at) VALUES
            ('Aurora Open-Air Summer Festival', 'festival', 'Marina Sunset Grounds, Valencia', 300, 'Three stages of electronic, indie, and live sunset sessions by the sea with artisan food gardens and immersive light shows.', 'images/events/festival-sunset.svg', '2026-11-27 16:00:00', '2026-11-29 02:00:00', NOW()),
            ('Midnight Echoes — Live Arena Concert', 'concert', 'City Dome Arena', 180, 'Full live band and visual laser production featuring special guest vocalists and surround-sound acoustics.', 'images/events/concert-arena.svg', '2026-12-04 20:00:00', '2026-12-04 23:45:00', NOW()),
            ('The Glass Crown — Grand Theatre Gala', 'theatre', 'Royal Palace Theatre Hall', 90, 'An acclaimed dramatic stage production accompanied by a 24-piece live chamber orchestra in the historic Grand Hall.', 'images/events/theatre-grand.svg', '2026-12-12 19:30:00', '2026-12-12 22:15:00', NOW())
        ");

        $this->addSql('UPDATE tickets SET event_id = (SELECT id FROM events ORDER BY id ASC LIMIT 1) WHERE event_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF471F7E88B');
        $this->addSql('DROP INDEX idx_tickets_event ON tickets');
        $this->addSql('ALTER TABLE tickets DROP event_id');
        $this->addSql('DROP TABLE events');
    }
}
