<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Repository\EventRepository;
use App\Repository\TicketRepository;
use App\Service\CustomerAuth;
use App\Service\TicketConfirmationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account')]
class AccountController extends AbstractController
{
    public const SESSION_TARGET_PATH = 'customer_target_path';

    public function __construct(
        private readonly string $organizerRegistrationCode,
    ) {
    }

    #[Route('', name: 'account_dashboard', methods: ['GET'])]
    public function index(
        CustomerAuth $customerAuth,
        TicketRepository $ticketRepository,
        EventRepository $eventRepository,
    ): Response {
        $customer = $customerAuth->getCurrentCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('account_login');
        }

        if ($customer->isOrganizer()) {
            return $this->render('account/organizer_dashboard.html.twig', [
                'customer' => $customer,
                'events' => $eventRepository->findByOrganizer($customer),
            ]);
        }

        $tickets = $ticketRepository->findForCustomer($customer);
        $activeTickets = [];
        $usedCount = 0;
        $totalSpentCents = 0;

        foreach ($tickets as $ticket) {
            $totalSpentCents += $ticket->getPriceCents();
            if ($ticket->isUsed()) {
                ++$usedCount;
            } elseif ($ticket->isQrValid()) {
                $activeTickets[] = $ticket;
            }
        }

        $totalDollars = ($totalSpentCents % 100 === 0)
            ? (string) intdiv($totalSpentCents, 100) . '$'
            : number_format($totalSpentCents / 100, 2) . '$';

        return $this->render('account/index.html.twig', [
            'customer' => $customer,
            'tickets' => $tickets,
            'active_tickets' => $activeTickets,
            'used_count' => $usedCount,
            'total_spent' => $totalDollars,
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
        $existingCustomer = $customerAuth->getCurrentCustomer();

        $error = null;
        $requestedRole = mb_strtolower(trim((string) $request->query->get('role', Customer::ROLE_CUSTOMER)));
        $formData = [
            'name' => '',
            'email' => (string) $request->query->get('email', ''),
            'role' => array_key_exists($requestedRole, Customer::getAvailableRoles()) ? $requestedRole : Customer::ROLE_CUSTOMER,
        ];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            $name = trim((string) $request->request->get('name', ''));
            $email = mb_strtolower(trim((string) $request->request->get('email', '')));
            $password = (string) $request->request->get('password', '');
            $passwordConfirm = (string) $request->request->get('password_confirm', $password);
            $role = mb_strtolower(trim((string) $request->request->get('role', Customer::ROLE_CUSTOMER)));
            $organizerPassword = trim((string) $request->request->get('organizer_password', ''));

            if (!array_key_exists($role, Customer::getAvailableRoles())) {
                $role = Customer::ROLE_CUSTOMER;
            }

            $formData['name'] = $name;
            $formData['email'] = $email;
            $formData['role'] = $role;

            if (!$this->isCsrfTokenValid('customer_register', $token)) {
                $error = 'Your form session expired. Please submit again.';
            } elseif (mb_strlen($name) < 2) {
                $error = 'Please enter your name (at least 2 characters).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (mb_strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            } elseif (
                $role === Customer::ROLE_ORGANIZER
                && ($organizerPassword === '' || !hash_equals($this->organizerRegistrationCode, $organizerPassword))
            ) {
                $error = 'Invalid Organizer password. Please enter the correct Organizer password to register as an Organizer.';
            } elseif ($customerRepository->findOneByEmail($email) !== null) {
                $error = 'An account with this email already exists. Please sign in instead.';
            } else {
                $customer = new Customer($name, $email, $password, $role);
                $entityManager->persist($customer);
                $entityManager->flush();

                $session = $request->getSession();
                $session->remove('latest_qr_token');
                $session->remove('submitted_ticket_ids');

                if ($customer->isCustomer()) {
                    $ticketRepository->attachGuestTicketsByEmail($customer);
                }
                $customerAuth->login($customer);

                $targetPath = $session->remove(self::SESSION_TARGET_PATH);
                if (
                    is_string($targetPath)
                    && str_starts_with($targetPath, '/')
                    && !str_starts_with($targetPath, '//')
                    && (!$customer->isOrganizer() || str_starts_with($targetPath, '/organizer'))
                ) {
                    return $this->redirect($targetPath);
                }

                if ($customer->isOrganizer()) {
                    $this->addFlash('success', sprintf('Welcome, %s! Your Organizer account is ready — you can now create new events.', $customer->getName()));

                    return $this->redirectToRoute('organizer_events_new');
                }

                $this->addFlash('success', sprintf('Welcome, %s! Your customer account has been registered.', $customer->getName()));

                return $this->redirectToRoute('account_dashboard');
            }
        }

        return $this->render('account/register.html.twig', [
            'error' => $error,
            'form_data' => $formData,
            'existing_customer' => $existingCustomer,
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
                    if ($customer->isCustomer()) {
                        $ticketRepository->attachGuestTicketsByEmail($customer);
                    }
                    $customerAuth->login($customer);

                    $targetPath = $request->getSession()->remove(self::SESSION_TARGET_PATH);
                    if (
                        is_string($targetPath)
                        && str_starts_with($targetPath, '/')
                        && !str_starts_with($targetPath, '//')
                        && (!$customer->isOrganizer() || str_starts_with($targetPath, '/organizer'))
                    ) {
                        return $this->redirect($targetPath);
                    }

                    return $this->redirectToRoute('account_dashboard');
                }
            }
        }

        return $this->render('account/login.html.twig', [
            'error' => $error,
            'entered_email' => $enteredEmail,
        ], new Response('', $error !== null ? Response::HTTP_UNAUTHORIZED : Response::HTTP_OK));
    }

    #[Route('/forgot-password', name: 'account_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        CustomerRepository $customerRepository,
        EntityManagerInterface $entityManager,
        TicketConfirmationMailer $mailer,
        CustomerAuth $customerAuth,
    ): Response {
        $error = null;
        $sentMessage = null;
        $localFallbackResetUrl = null;
        $enteredEmail = (string) $request->query->get('email', $customerAuth->getCurrentCustomer()?->getEmail() ?? '');

        if ($request->isMethod('POST')) {
            $csrfToken = (string) $request->request->get('_csrf_token', '');
            $enteredEmail = mb_strtolower(trim((string) $request->request->get('email', '')));

            if (!$this->isCsrfTokenValid('customer_forgot_password', $csrfToken)) {
                $error = 'Your session expired. Please try again.';
            } elseif (!filter_var($enteredEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $customer = $customerRepository->findOneByEmail($enteredEmail);
                if ($customer !== null) {
                    $rawResetToken = $customer->issuePasswordResetToken();
                    $entityManager->flush();

                    $resetUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('account_reset_password', ['token' => $rawResetToken]);
                    $mailResult = $mailer->sendPasswordResetEmail($customer, $resetUrl);

                    if ($mailResult['status'] !== TicketConfirmationMailer::STATUS_SENT) {
                        $localFallbackResetUrl = $resetUrl;
                    }
                }

                $sentMessage = sprintf(
                    'If an account exists for %s, a password recovery link (valid for 1 hour) has been sent to that email.',
                    $enteredEmail
                );
            }
        }

        return $this->render('account/forgot_password.html.twig', [
            'error' => $error,
            'sent_message' => $sentMessage,
            'local_fallback_reset_url' => $localFallbackResetUrl,
            'entered_email' => $enteredEmail,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'account_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        string $token,
        Request $request,
        CustomerRepository $customerRepository,
        EntityManagerInterface $entityManager,
        CustomerAuth $customerAuth,
    ): Response {
        $customer = $customerRepository->findOneByValidResetToken($token);
        if ($customer === null) {
            return $this->render('account/reset_password.html.twig', [
                'token_valid' => false,
                'token' => $token,
                'error' => null,
            ], new Response('', Response::HTTP_GONE));
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $csrfToken = (string) $request->request->get('_csrf_token', '');
            $newPassword = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('password_confirm', '');

            if (!$this->isCsrfTokenValid('customer_reset_password', $csrfToken)) {
                $error = 'Your session expired. Please submit your new password again.';
            } elseif (mb_strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match.';
            } else {
                $customer->resetPassword($newPassword);
                $entityManager->flush();

                $customerAuth->login($customer);
                $this->addFlash('success', 'Your password has been reset! You are now signed in.');

                return $this->redirectToRoute('account_dashboard');
            }
        }

        return $this->render('account/reset_password.html.twig', [
            'token_valid' => true,
            'token' => $token,
            'target_customer' => $customer,
            'error' => $error,
        ], new Response('', $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/logout', name: 'account_logout', methods: ['POST'])]
    public function logout(Request $request, CustomerAuth $customerAuth): Response
    {
        $token = (string) $request->request->get('_csrf_token', '');
        if ($this->isCsrfTokenValid('customer_logout', $token)) {
            $customerAuth->logout();
            $session = $request->getSession();
            $session->remove('latest_qr_token');
            $session->remove('submitted_ticket_ids');
            $this->addFlash('success', 'You have signed out. Your tickets remain private to your account.');
        }

        $response = $this->redirectToRoute('ticket_home');
        $response->headers->clearCookie('latest_qr_token', '/');

        return $response;
    }
}
