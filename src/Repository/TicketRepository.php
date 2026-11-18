<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function generateUniqueQrToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while ($this->findOneBy(['qrToken' => $token]) !== null);

        return $token;
    }

    public function findOneByQrToken(string $token): ?Ticket
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        return $this->findOneBy(['qrToken' => $trimmed]);
    }

    /**
     * Atomically marks a ticket as used at the entrance only if it has NOT already been used
     * and has not expired. Prevents double-admission race conditions.
     */
    public function markTicketAsUsedAtomically(int $ticketId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE tickets
             SET used_at = :now, updated_at = :now
             WHERE id = :id
               AND used_at IS NULL
               AND qr_token IS NOT NULL
               AND (qr_expires_at IS NULL OR qr_expires_at >= :now)',
            [
                'now' => $now,
                'id' => $ticketId,
            ]
        );

        return $affected === 1;
    }

    /**
     * Links any previously purchased guest tickets with a matching email address to the customer account.
     */
    public function attachGuestTicketsByEmail(Customer $customer): int
    {
        if ($customer->getId() === null) {
            return 0;
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE tickets SET customer_id = :customerId WHERE customer_id IS NULL AND LOWER(contact) = :email',
            [
                'customerId' => $customer->getId(),
                'email' => mb_strtolower($customer->getEmail()),
            ]
        );
    }

    /**
     * @return list<Ticket>
     */
    public function findForCustomer(Customer $customer): array
    {
        /** @var list<Ticket> $results */
        $results = $this->createQueryBuilder('t')
            ->andWhere('t.customer = :customer OR LOWER(t.contact) = :email')
            ->setParameter('customer', $customer)
            ->setParameter('email', mb_strtolower($customer->getEmail()))
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * @return list<Ticket>
     */
    public function findFiltered(?string $status = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if ($status !== null && array_key_exists($status, Ticket::getAvailableStatuses())) {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }

        $trimmedSearch = trim((string) $search);
        if ($trimmedSearch !== '') {
            $idCandidate = ltrim($trimmedSearch, '#');
            if (ctype_digit($idCandidate)) {
                $qb->andWhere('t.id = :exactId OR LOWER(t.title) LIKE :query OR LOWER(t.contact) LIKE :query OR LOWER(t.description) LIKE :query OR LOWER(t.qrToken) LIKE :query')
                    ->setParameter('exactId', (int) $idCandidate)
                    ->setParameter('query', '%' . mb_strtolower($trimmedSearch) . '%');
            } else {
                $qb->andWhere('LOWER(t.title) LIKE :query OR LOWER(t.contact) LIKE :query OR LOWER(t.description) LIKE :query OR LOWER(t.qrToken) LIKE :query')
                    ->setParameter('query', '%' . mb_strtolower($trimmedSearch) . '%');
            }
        }

        /** @var list<Ticket> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * @param list<int> $ids
     * @return list<Ticket>
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Ticket> $results */
        $results = $this->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * @return array{total: int, open: int, in_progress: int, resolved: int, used: int, unused_valid: int}
     */
    public function getStatusCounts(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS total')
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [
            'total' => 0,
            Ticket::STATUS_OPEN => 0,
            Ticket::STATUS_IN_PROGRESS => 0,
            Ticket::STATUS_RESOLVED => 0,
            'used' => 0,
            'unused_valid' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $count = (int) $row['total'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();
        $counts['used'] = (int) $conn->fetchOne('SELECT COUNT(*) FROM tickets WHERE used_at IS NOT NULL');
        $counts['unused_valid'] = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM tickets WHERE used_at IS NULL AND qr_token IS NOT NULL AND (qr_expires_at IS NULL OR qr_expires_at >= :now)',
            ['now' => $now]
        );

        return $counts;
    }
}
