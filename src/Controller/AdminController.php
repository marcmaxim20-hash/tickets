<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\EventRepository;
use App\Repository\TicketRepository;
use App\Service\QrCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    public const SESSION_ADMIN_KEY = 'admin_role_authenticated';
    public const SESSION_STAFF_KEY = 'staff_scanner_authenticated';
    private const SESSION_TARGET_KEY = 'admin_target_path';

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminPassword,
    ) {
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->get(self::SESSION_ADMIN_KEY) === true) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;
        $enteredIdentity = '';
        $statusCode = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            $enteredIdentity = trim((string) $request->request->get('identity', ''));
            $enteredPassword = (string) $request->request->get('password', '');

            $normalizedIdentity = mb_strtolower($enteredIdentity);
            $expectedEmail = mb_strtolower(trim($this->adminUsername));
            $identityMatches = $normalizedIdentity !== '' && (
                hash_equals($expectedEmail, $normalizedIdentity)
                || hash_equals('admin', $normalizedIdentity)
            );
            $passwordMatches = $enteredPassword !== '' && hash_equals($this->adminPassword, $enteredPassword);

            if (!$this->isCsrfTokenValid('admin_login', $token)) {
                $error = 'Your session expired. Please sign in again.';
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif (!$identityMatches || !$passwordMatches) {
                $error = 'Invalid administrator username/email or password.';
                $statusCode = Response::HTTP_UNAUTHORIZED;
            } else {
                $session->migrate(true);
                $session->set(self::SESSION_ADMIN_KEY, true);
                $session->set(self::SESSION_STAFF_KEY, true);

                $targetPath = $session->remove(self::SESSION_TARGET_KEY);
                if (
                    is_string($targetPath)
                    && (str_starts_with($targetPath, '/admin') || str_starts_with($targetPath, '/scanner'))
                    && !str_starts_with($targetPath, '//')
                ) {
                    return $this->redirect($targetPath);
                }

                return $this->redirectToRoute('admin_dashboard');
            }
        }

        return $this->render('admin/login.html.twig', [
            'error' => $error,
            'entered_identity' => $enteredIdentity,
        ], new Response('', $statusCode));
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        TicketRepository $ticketRepository,
        EventRepository $eventRepository,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $statusFilter = $request->query->get('status');
        $activeStatus = is_string($statusFilter) && array_key_exists($statusFilter, Ticket::getAvailableStatuses())
            ? $statusFilter
            : null;

        $search = trim((string) $request->query->get('q', ''));
        $tickets = $ticketRepository->findFiltered($activeStatus, $search);
        $counts = $ticketRepository->getStatusCounts();
        $events = $eventRepository->findFiltered();

        return $this->render('admin/index.html.twig', [
            'tickets' => $tickets,
            'counts' => $counts,
            'active_status' => $activeStatus,
            'search' => $search,
            'available_statuses' => Ticket::getAvailableStatuses(),
            'events' => $events,
        ]);
    }

    #[Route('/tickets/{id}', name: 'admin_ticket_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        TicketRepository $ticketRepository,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            $request->getSession()->set(self::SESSION_TARGET_KEY, $request->getRequestUri());

            return $this->redirectToRoute('admin_login');
        }

        $ticket = $ticketRepository->find($id);
        if ($ticket === null) {
            throw $this->createNotFoundException(sprintf('Ticket #%d was not found.', $id));
        }

        $qrSvg = null;
        $doorUrl = null;
        if ($ticket->isFestivalTicket() && $ticket->getQrToken() !== null) {
            $doorUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('festival_door_scan', ['token' => $ticket->getQrToken()]);
            $qrSvg = $qrCodeGenerator->generateSvg($doorUrl, 180);
        }

        return $this->render('admin/show.html.twig', [
            'ticket' => $ticket,
            'available_statuses' => Ticket::getAvailableStatuses(),
            'qr_svg' => $qrSvg,
            'door_url' => $doorUrl,
        ]);
    }

    #[Route('/tickets/{id}/status', name: 'admin_ticket_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        TicketRepository $ticketRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $ticket = $ticketRepository->find($id);
        if ($ticket === null) {
            throw $this->createNotFoundException(sprintf('Ticket #%d was not found.', $id));
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('ticket_status_' . $ticket->getId(), $csrfToken)) {
            $this->addFlash('admin_error', 'Invalid security token. Please try updating the status again.');

            return $this->redirectToRoute('admin_ticket_show', ['id' => $ticket->getId()]);
        }

        $newStatus = (string) $request->request->get('status', '');
        if (!array_key_exists($newStatus, Ticket::getAvailableStatuses())) {
            $this->addFlash('admin_error', 'Please select a valid ticket status.');
        } else {
            $previousLabel = $ticket->getStatusLabel();
            $ticket->setStatus($newStatus);
            $entityManager->flush();

            if ($previousLabel !== $ticket->getStatusLabel()) {
                $this->addFlash(
                    'admin_success',
                    sprintf('Ticket #%d status updated from "%s" to "%s".', $ticket->getId(), $previousLabel, $ticket->getStatusLabel())
                );
            } else {
                $this->addFlash(
                    'admin_info',
                    sprintf('Ticket #%d is already marked as "%s".', $ticket->getId(), $ticket->getStatusLabel())
                );
            }
        }

        $redirectTo = (string) $request->request->get('redirect_to', 'show');
        if ($redirectTo === 'dashboard') {
            $params = [];
            $filterStatus = (string) $request->request->get('filter_status', '');
            $filterQuery = trim((string) $request->request->get('filter_q', ''));
            if ($filterStatus !== '' && array_key_exists($filterStatus, Ticket::getAvailableStatuses())) {
                $params['status'] = $filterStatus;
            }
            if ($filterQuery !== '') {
                $params['q'] = $filterQuery;
            }

            return $this->redirectToRoute('admin_dashboard', $params);
        }

        return $this->redirectToRoute('admin_ticket_show', ['id' => $ticket->getId()]);
    }

    #[Route('/tickets/{id}/qr-expiry', name: 'admin_ticket_qr_expiry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleQrExpiry(
        int $id,
        Request $request,
        TicketRepository $ticketRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $ticket = $ticketRepository->find($id);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Festival ticket not found.');
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('ticket_qr_' . $ticket->getId(), $csrfToken)) {
            $this->addFlash('admin_error', 'Invalid security token.');

            return $this->redirectToRoute('admin_ticket_show', ['id' => $ticket->getId()]);
        }

        $action = (string) $request->request->get('action', 'expire');
        if ($action === 'renew') {
            $ticket->renewQrFor24Hours();
            $this->addFlash('admin_success', sprintf('Ticket #%d QR code renewed for a fresh 24 hours.', $ticket->getId()));
        } elseif ($action === 'reset_used') {
            $ticket->resetUsedStatus();
            $this->addFlash('admin_success', sprintf('Ticket #%d reset to UNUSED so it can be scanned again for testing.', $ticket->getId()));
        } else {
            $ticket->expireQrNow();
            $this->addFlash('admin_info', sprintf('Ticket #%d QR code marked as expired (simulated >24h).', $ticket->getId()));
        }

        $entityManager->flush();

        return $this->redirectToRoute('admin_ticket_show', ['id' => $ticket->getId()]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $token = (string) $request->request->get('_csrf_token', '');
        if ($this->isCsrfTokenValid('admin_logout', $token)) {
            $session = $request->getSession();
            $session->remove(self::SESSION_ADMIN_KEY);
            $session->remove(self::SESSION_STAFF_KEY);
            $session->remove('admin_authenticated');
            $session->remove(self::SESSION_TARGET_KEY);
            $this->addFlash('admin_info', 'Session locked.');
        }

        return $this->redirectToRoute('admin_login');
    }

    private function isAdminAuthenticated(Request $request): bool
    {
        return $request->hasPreviousSession() && $request->getSession()->get(self::SESSION_ADMIN_KEY) === true;
    }
}
