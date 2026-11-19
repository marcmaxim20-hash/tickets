<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119171500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add role column to customers (customer/organizer) and location + organizer_id columns to events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customers ADD role VARCHAR(20) DEFAULT 'customer' NOT NULL");
        $this->addSql('CREATE INDEX idx_customers_role ON customers (role)');

        $this->addSql("ALTER TABLE events ADD location VARCHAR(255) DEFAULT '' NOT NULL, ADD organizer_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A876C4DDA FOREIGN KEY (organizer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_events_organizer ON events (organizer_id)');

        $this->addSql("UPDATE events SET location = 'Passeig de Neptú 2, 46011 Valencia, Spain' WHERE title LIKE '%Aurora%' AND location = ''");
        $this->addSql("UPDATE events SET location = 'Av. de les Corts Valencianes 52, 46015 Valencia, Spain' WHERE title LIKE '%Midnight Echoes%' AND location = ''");
        $this->addSql("UPDATE events SET location = 'Carrer de les Barques 15, 46002 Valencia, Spain' WHERE title LIKE '%Glass Crown%' AND location = ''");
        $this->addSql("UPDATE events SET location = venue WHERE location = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP FOREIGN KEY FK_5387574A876C4DDA');
        $this->addSql('DROP INDEX idx_events_organizer ON events');
        $this->addSql('ALTER TABLE events DROP location, DROP organizer_id');

        $this->addSql('DROP INDEX idx_customers_role ON customers');
        $this->addSql('ALTER TABLE customers DROP role');
    }
}
