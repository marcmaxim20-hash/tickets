<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findOneByEmail(string $email): ?Customer
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        return $this->findOneBy(['email' => $normalized]);
    }

    public function findOneByValidResetToken(string $rawToken): ?Customer
    {
        $trimmed = trim($rawToken);
        if ($trimmed === '') {
            return null;
        }

        $tokenHash = hash('sha256', $trimmed);
        $customer = $this->findOneBy(['resetTokenHash' => $tokenHash]);

        if ($customer === null || !$customer->isResetTokenValid()) {
            return null;
        }

        return $customer;
    }
}
