<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Detach legacy backfilled/unowned tickets from events so available seats strictly reflect purchases made on this site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE tickets t
             INNER JOIN events e ON e.id = t.event_id
             SET t.event_id = NULL
             WHERE t.customer_id IS NULL
                OR t.qr_token IS NULL
                OR t.created_at < DATE_SUB(e.created_at, INTERVAL 5 MINUTE)'
        );
    }

    public function down(Schema $schema): void
    {
        // Irreversible data cleanup
    }
}
