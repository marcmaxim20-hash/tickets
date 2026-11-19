<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return list<Event>
     */
    public function findFiltered(?string $category = null): array
    {
        $this->detachLegacyBackfilledTickets();

        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.tickets', 't')
            ->addSelect('t')
            ->orderBy('e.startDate', 'ASC')
            ->addOrderBy('e.id', 'ASC');

        if ($category !== null && array_key_exists($category, Event::getAvailableCategories())) {
            $qb->andWhere('e.category = :category')
                ->setParameter('category', $category);
        }

        /** @var list<Event> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * @return list<Event>
     */
    public function findByOrganizer(Customer $organizer): array
    {
        $this->detachLegacyBackfilledTickets();

        /** @var list<Event> $results */
        $results = $this->createQueryBuilder('e')
            ->leftJoin('e.tickets', 't')
            ->addSelect('t')
            ->andWhere('e.organizer = :organizer')
            ->setParameter('organizer', $organizer)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Locks the event row in MySQL (FOR UPDATE) inside a transaction and checks if at least 1 seat remains
     * based strictly on tickets bought by registered customers on this site.
     */
    public function hasAvailableSeatForUpdate(int $eventId): bool
    {
        $conn = $this->getEntityManager()->getConnection();
        $totalSeats = $conn->fetchOne('SELECT total_seats FROM events WHERE id = :id FOR UPDATE', ['id' => $eventId]);
        if ($totalSeats === false) {
            return false;
        }

        $soldCount = (int) $conn->fetchOne(
            'SELECT COUNT(t.id)
             FROM tickets t
             INNER JOIN events e ON e.id = t.event_id
             WHERE t.event_id = :id
               AND t.customer_id IS NOT NULL
               AND t.qr_token IS NOT NULL
               AND t.created_at >= DATE_SUB(e.created_at, INTERVAL 5 MINUTE)',
            ['id' => $eventId]
        );

        return $soldCount < (int) $totalSeats;
    }

    public function findWithSiteSeats(int $id): ?Event
    {
        $this->detachLegacyBackfilledTickets();

        return $this->find($id);
    }

    /**
     * Ensures any legacy/pre-event or unowned ticket rows that were previously backfilled
     * do not occupy event seats unless actually bought by a customer on this site.
     */
    private function detachLegacyBackfilledTickets(): void
    {
        static $cleaned = false;
        if ($cleaned) {
            return;
        }
        $cleaned = true;

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE tickets t
             INNER JOIN events e ON e.id = t.event_id
             SET t.event_id = NULL
             WHERE t.customer_id IS NULL
                OR t.qr_token IS NULL
                OR t.created_at < DATE_SUB(e.created_at, INTERVAL 5 MINUTE)'
        );
    }
}
