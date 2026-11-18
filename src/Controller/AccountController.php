<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Repository\TicketRepository;
use App\Service\CustomerAuth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account')]
class AccountController extends AbstractController
{
    #[Route('', name: 'account_dashboard', methods: ['GET'])]
    public function index(
        CustomerAuth $customerAuth,
        TicketRepository $ticketRepository,
    ): Response {
        $customer = $customerAuth->getCurrentCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('account_login');
        }

        $tickets = $ticketRepository->findForCustomer($customer);
        $activeTickets = [];
        $usedCount = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->isUsed()) {
                ++$usedCount;
            } elseif ($ticket->isQrValid()) {
                $activeTickets[] = $ticket;
            }
        }

        return $this->render('account/index.html.twig', [
            'customer' => $customer,
            'tickets' => $tickets,
            'active_tickets' => $activeTickets,
            'used_count' => $usedCount,
        ]);
    }

    #[Route('/register', name: 'account_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        CustomerRepository $customerRepository,
        TicketRepository $ticketRepository,
        EntityManagerInterface $entityManager,
        CustomerAuth $customerAuth,
    ): Response {
        if ($customerAuth->getCurrentCustomer() !== null) {
            return $this->redirectToRoute('account_dashboard');
        }

        $error = null;
        $formData = [
            'name' => '',
            'email' => (string) $request->query->get('email', ''),
        ];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            $name = trim((string) $request->request->get('name', ''));
            $email = mb_strtolower(trim((string) $request->request->get('email', '')));
            $password = (string) $request->request->get('password', '');

            $formData['name'] = $name;
            $formData['email'] = $email;

            if (!$this->isCsrfTokenValid('customer_register', $token)) {
                $error = 'Your form session expired. Please submit again.';
            } elseif (mb_strlen($name) < 2) {
                $error = 'Please enter your name (at least 2 characters).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (mb_strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long.';
            } elseif ($customerRepository->findOneByEmail($email) !== null) {
                $error = 'An account with this email already exists. Please sign in instead.';
            } else {
                $customer = new Customer($name, $email, $password);
                $entityManager->persist($customer);
                $entityManager->flush();

                $linkedCount = $ticketRepository->attachGuestTicketsByEmail($customer);
                $customerAuth->login($customer);

                if ($linkedCount > 0) {
                    $this->addFlash(
                        'success',
                        sprintf('Welcome, %s! Your account is ready and %d existing ticket(s) for %s were linked to your purchase history.', $customer->getName(), $linkedCount, $customer->getEmail())
                    );
                } else {
                    $this->addFlash('success', sprintf('Welcome, %s! Your customer account has been created.', $customer->getName()));
                }

                return $this->redirectToRoute('account_dashboard');
            }
        }

        return $this->render('account/register.html.twig', [
            'error' => $error,
            'form_data' => $formData,
        ], new Response('', $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/login', name: 'account_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        CustomerRepository $customerRepository,
        TicketRepository $ticketRepository,
        CustomerAuth $customerAuth,
    ): Response {
        if ($customerAuth->getCurrentCustomer() !== null) {
            return $this->redirectToRoute('account_dashboard');
        }

        $error = null;
        $enteredEmail = '';

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            $enteredEmail = mb_strtolower(trim((string) $request->request->get('email', '')));
            $password = (string) $request->request->get('password', '');

            if (!$this->isCsrfTokenValid('customer_login', $token)) {
                $error = 'Your form session expired. Please try again.';
            } else {
                $customer = $customerRepository->findOneByEmail($enteredEmail);
                if ($customer === null || !$customer->verifyPassword($password)) {
                    $error = 'Invalid email or password.';
                } else {
                    $ticketRepository->attachGuestTicketsByEmail($customer);
                    $customerAuth->login($customer);

                    return $this->redirectToRoute('account_dashboard');
                }
            }
        }

        return $this->render('account/login.html.twig', [
            'error' => $error,
            'entered_email' => $enteredEmail,
        ], new Response('', $error !== null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_OK));
    }

    #[Route('/logout', name: 'account_logout', methods: ['POST'])]
    public function logout(Request $request, CustomerAuth $customerAuth): Response
    {
        $token = (string) $request->request->get('_csrf_token', '');
        if ($this->isCsrfTokenValid('customer_logout', $token)) {
            $customerAuth->logout();
            $this->addFlash('success', 'You have signed out of your customer account.');
        }

        return $this->redirectToRoute('ticket_home');
    }
}
