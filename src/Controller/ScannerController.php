<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/scanner')]
class ScannerController extends AbstractController
{
    public function __construct(
        private readonly string $adminAccessCode,
    ) {
    }

    #[Route('/login', name: 'staff_scanner_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->isStaffAuthenticated($request)) {
            return $this->redirectToRoute('staff_scanner');
        }

        $error = null;
        $statusCode = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            $enteredCode = trim((string) $request->request->get('staff_code', ''));

            if (!$this->isCsrfTokenValid('staff_scanner_login', $token)) {
                $error = 'Your session expired. Please try again.';
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif ($enteredCode === '' || !hash_equals($this->adminAccessCode, $enteredCode)) {
                $error = 'Invalid entrance staff PIN.';
                $statusCode = Response::HTTP_UNAUTHORIZED;
            } else {
                $session = $request->getSession();
                $session->migrate(true);
                $session->set(AdminController::SESSION_STAFF_KEY, true);

                return $this->redirectToRoute('staff_scanner');
            }
        }

        return $this->render('scanner/login.html.twig', [
            'error' => $error,
        ], new Response('', $statusCode));
    }

    #[Route('', name: 'staff_scanner', methods: ['GET'])]
    public function index(Request $request, TicketRepository $ticketRepository): Response
    {
        if (!$this->isStaffAuthenticated($request)) {
            return $this->redirectToRoute('staff_scanner_login');
        }

        $recentTickets = array_slice($ticketRepository->findFiltered(), 0, 15);
        $counts = $ticketRepository->getStatusCounts();

        return $this->render('scanner/index.html.twig', [
            'scan_result' => null,
            'recent_tickets' => $recentTickets,
            'counts' => $counts,
        ]);
    }

    #[Route('/check-in', name: 'staff_scanner_checkin', methods: ['POST'])]
    public function checkIn(
        Request $request,
        TicketRepository $ticketRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isStaffAuthenticated($request)) {
            return $this->redirectToRoute('staff_scanner_login');
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        $rawInput = trim((string) $request->request->get('qr_input', ''));

        if (!$this->isCsrfTokenValid('staff_scanner_checkin', $csrfToken)) {
            $scanResult = [
                'outcome' => 'invalid',
                'title' => 'Session Token Expired',
                'message' => 'Please submit the QR scan again.',
                'ticket' => null,
            ];
            $httpStatus = Response::HTTP_BAD_REQUEST;
        } else {
            $ticket = $this->resolveTicketFromScanInput($rawInput, $ticketRepository);

            if ($ticket === null) {
                $scanResult = [
                    'outcome' => 'invalid',
                    'title' => 'INVALID QR CODE',
                    'message' => sprintf('No ticket matched "%s" in MySQL.', $rawInput !== '' ? $rawInput : '(empty)'),
                    'ticket' => null,
                ];
                $httpStatus = Response::HTTP_NOT_FOUND;
            } elseif ($ticket->isUsed()) {
                $scanResult = [
                    'outcome' => 'already_used',
                    'title' => 'ACCESS DENIED — TICKET ALREADY USED!',
                    'message' => sprintf(
                        'Ticket #%d (%s) was already scanned at the entrance on %s and cannot be used a second time.',
                        $ticket->getId(),
                        $ticket->getContact(),
                        $ticket->getUsedAt()?->format('M j, Y \a\t H:i:s') ?? 'an earlier scan'
                    ),
                    'ticket' => $ticket,
                ];
                $httpStatus = Response::HTTP_CONFLICT;
            } elseif ($ticket->isQrExpired()) {
                $scanResult = [
                    'outcome' => 'expired',
                    'title' => 'ACCESS DENIED — QR CODE EXPIRED',
                    'message' => sprintf(
                        'Ticket #%d expired after its 24-hour window on %s.',
                        $ticket->getId(),
                        $ticket->getQrExpiresAt()?->format('M j, Y \a\t H:i:s') ?? ''
                    ),
                    'ticket' => $ticket,
                ];
                $httpStatus = Response::HTTP_FORBIDDEN;
            } elseif ($ticketRepository->markTicketAsUsedAtomically((int) $ticket->getId())) {
                $entityManager->refresh($ticket);
                $scanResult = [
                    'outcome' => 'admitted',
                    'title' => 'ACCESS GRANTED — TICKET MARKED AS USED',
                    'message' => sprintf(
                        'Welcome in! Ticket #%d for %s has been verified and permanently marked as USED at %s.',
                        $ticket->getId(),
                        $ticket->getContact(),
                        $ticket->getUsedAt()?->format('H:i:s') ?? 'now'
                    ),
                    'ticket' => $ticket,
                ];
                $httpStatus = Response::HTTP_OK;
            } else {
                $entityManager->refresh($ticket);
                $scanResult = [
                    'outcome' => 'already_used',
                    'title' => 'ACCESS DENIED — TICKET ALREADY USED!',
                    'message' => sprintf('Ticket #%d has already been scanned and cannot be used twice.', $ticket->getId()),
                    'ticket' => $ticket,
                ];
                $httpStatus = Response::HTTP_CONFLICT;
            }
        }

        $recentTickets = array_slice($ticketRepository->findFiltered(), 0, 15);
        $counts = $ticketRepository->getStatusCounts();

        return $this->render('scanner/index.html.twig', [
            'scan_result' => $scanResult,
            'recent_tickets' => $recentTickets,
            'counts' => $counts,
        ], new Response('', $httpStatus));
    }

    private function resolveTicketFromScanInput(string $rawInput, TicketRepository $ticketRepository): ?Ticket
    {
        if ($rawInput === '') {
            return null;
        }

        if (preg_match('#/festival/(?:door|pass)/([a-f0-9]{16,64})#i', $rawInput, $matches) === 1) {
            return $ticketRepository->findOneByQrToken(strtolower($matches[1]));
        }

        $idCandidate = ltrim($rawInput, '#');
        if (ctype_digit($idCandidate)) {
            return $ticketRepository->find((int) $idCandidate);
        }

        return $ticketRepository->findOneByQrToken(strtolower($rawInput));
    }

    private function isStaffAuthenticated(Request $request): bool
    {
        if (!$request->hasPreviousSession()) {
            return false;
        }

        $session = $request->getSession();

        return $session->get(AdminController::SESSION_STAFF_KEY) === true
            || $session->get(AdminController::SESSION_ADMIN_KEY) === true;
    }
}
