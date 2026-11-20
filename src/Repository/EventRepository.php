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
    public function findFiltered(
        ?string $category = null,
        ?string $locationQuery = null,
        ?string $searchQuery = null,
    ): array {
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

        $trimmedLocation = trim((string) $locationQuery);
        if ($trimmedLocation !== '') {
            $qb->andWhere('LOWER(e.location) LIKE :locationQuery OR LOWER(e.venue) LIKE :locationQuery')
                ->setParameter('locationQuery', '%' . mb_strtolower($trimmedLocation) . '%');
        }

        $trimmedSearch = trim((string) $searchQuery);
        if ($trimmedSearch !== '') {
            $qb->andWhere('LOWER(e.title) LIKE :searchQuery OR LOWER(e.venue) LIKE :searchQuery OR LOWER(e.location) LIKE :searchQuery OR LOWER(e.description) LIKE :searchQuery')
                ->setParameter('searchQuery', '%' . mb_strtolower($trimmedSearch) . '%');
        }

        /** @var list<Event> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * Extracts unique city/area hints from stored event locations for quick location filter chips.
     *
     * @return list<string>
     */
    public function getDistinctLocationHints(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT location FROM events WHERE location IS NOT NULL AND TRIM(location) <> "" ORDER BY location ASC'
        );

        $seen = [];
        $hints = [];

        foreach ($rows as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== ''));
            if ($parts === []) {
                continue;
            }

            // Pick city/area part (e.g. "46011 Valencia" -> "Valencia") when comma-separated, else full string
            $candidate = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];
            $candidate = trim((string) preg_replace('/^\d{4,6}\s+/', '', $candidate));
            if ($candidate === '' || mb_strlen($candidate) > 40) {
                $candidate = mb_substr($parts[0], 0, 40);
            }

            $key = mb_strtolower($candidate);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $hints[] = $candidate;
            }
        }

        return array_slice($hints, 0, 8);
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

    /**
     * Returns all already-booked seat labels (e.g. ['A1', 'C4']) for an event.
     *
     * @return list<string>
     */
    public function getTakenSeatLabels(int $eventId): array
    {
        $this->detachLegacyBackfilledTickets();

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT t.seat_label
             FROM tickets t
             WHERE t.event_id = :id
               AND t.seat_label IS NOT NULL
               AND t.seat_label <> ""',
            ['id' => $eventId]
        );

        $labels = [];
        foreach ($rows as $row) {
            if (is_string($row) && trim($row) !== '') {
                $labels[] = strtoupper(trim($row));
            }
        }

        return $labels;
    }

    /**
     * Checks inside an active FOR UPDATE transaction whether a specific seat is already booked.
     */
    public function isSeatTakenForUpdate(int $eventId, string $seatLabel): bool
    {
        $count = (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(t.id)
             FROM tickets t
             WHERE t.event_id = :id
               AND UPPER(t.seat_label) = :seat',
            [
                'id' => $eventId,
                'seat' => strtoupper(trim($seatLabel)),
            ]
        );

        return $count > 0;
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
