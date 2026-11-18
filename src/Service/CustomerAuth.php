<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class CustomerAuth
{
    public const SESSION_CUSTOMER_ID = 'customer_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CustomerRepository $customerRepository,
    ) {
    }

    public function getCurrentCustomer(): ?Customer
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasPreviousSession()) {
            return null;
        }

        $id = $request->getSession()->get(self::SESSION_CUSTOMER_ID);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        return $this->customerRepository->find((int) $id);
    }

    public function login(Customer $customer): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || $customer->getId() === null) {
            return;
        }

        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_CUSTOMER_ID, $customer->getId());
    }

    public function logout(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $request->getSession()->remove(self::SESSION_CUSTOMER_ID);
    }
}
