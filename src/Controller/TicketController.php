<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ticket;
use App\Form\TicketType;
use App\Repository\CustomerRepository;
use App\Repository\TicketRepository;
use App\Service\CustomerAuth;
use App\Service\QrCodeGenerator;
use App\Service\TicketConfirmationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TicketController extends AbstractController
{
    public function __construct(
        private readonly string $adminAccessCode,
    ) {
    }

    #[Route('/', name: 'ticket_home', methods: ['GET'])]
    public function index(
        Request $request,
        TicketRepository $ticketRepository,
        CustomerAuth $customerAuth,
    ): Response {
        $customer = $customerAuth->getCurrentCustomer();
        if ($customer !== null) {
            $myRecentTickets = array_slice($ticketRepository->findForCustomer($customer), 0, 10);
        } else {
            $recentIds = [];
            if ($request->hasPreviousSession()) {
                $storedIds = $request->getSession()->get('submitted_ticket_ids', []);
                if (is_array($storedIds)) {
                    $recentIds = array_values(array_map('intval', $storedIds));
                }
            }
            $myRecentTickets = $ticketRepository->findByIdsOrdered($recentIds);
        }

        $stats = $ticketRepository->getStatusCounts();

        return $this->render('ticket/index.html.twig', [
            'recent_tickets' => $myRecentTickets,
            'stats' => $stats,
        ]);
    }

    #[Route('/tickets/new', name: 'ticket_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        TicketRepository $ticketRepository,
        CustomerRepository $customerRepository,
        CustomerAuth $customerAuth,
        TicketConfirmationMailer $confirmationMailer,
    ): Response {
        $loggedInCustomer = $customerAuth->getCurrentCustomer();
        $ticket = new Ticket();

        if (!$request->isMethod('POST') && $loggedInCustomer !== null) {
            $ticket->setContact($loggedInCustomer->getEmail());
        }

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uniqueToken = $ticketRepository->generateUniqueQrToken();
            $ticket->setTitle('Festival Door QR Pass');
            $ticket->setDescription(sprintf(
                'Single-entry 24-hour QR pass for %s.',
                $ticket->getContact()
            ));
            $ticket->initializeFestivalQr($uniqueToken);

            $ownerAccount = $loggedInCustomer ?? $customerRepository->findOneByEmail($ticket->getContact());
            if ($ownerAccount !== null) {
                $ticket->setCustomer($ownerAccount);
            }

            $entityManager->persist($ticket);
            $entityManager->flush();

            $this->rememberTicketInSession($request, $ticket);

            $baseUrl = $request->getSchemeAndHttpHost();
            $doorUrl = $baseUrl . $this->generateUrl('festival_door_scan', ['token' => $ticket->getQrToken()]);
            $passUrl = $baseUrl . $this->generateUrl('festival_pass_show', ['token' => $ticket->getQrToken()]);

            $mailResult = $confirmationMailer->sendFestivalTicketConfirmation($ticket, $doorUrl, $passUrl);

            if ($mailResult['status'] === TicketConfirmationMailer::STATUS_SENT) {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Ticket #%d purchased with a unique QR code! Click "See QR" in the main menu (or check your Customer Account) — we also emailed it to %s!',
                        $ticket->getId(),
                        $ticket->getContact()
                    )
                );
            } elseif ($mailResult['status'] === TicketConfirmationMailer::STATUS_NOT_CONFIGURED) {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Ticket #%d purchased and saved in MySQL! Click "See QR" in the main menu to open your unique QR code.',
                        $ticket->getId()
                    )
                );
                $this->addFlash(
                    'warning',
                    sprintf(
                        'Email to %s was not sent yet because SMTP_PASSWORD is still set to the placeholder in .env.',
                        $ticket->getContact()
                    )
                );
            } else {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Ticket #%d purchased and saved in MySQL! Click "See QR" in the main menu to open your unique QR code.',
                        $ticket->getId()
                    )
                );
                $this->addFlash(
                    'warning',
                    sprintf(
                        'Could not email QR code to %s (%s), but your QR code is saved in your account and in "See QR" above.',
                        $ticket->getContact(),
                        $mailResult['error'] ?? 'SMTP connection error'
                    )
                );
            }

            $response = $this->redirectToRoute($loggedInCustomer !== null ? 'account_dashboard' : 'ticket_home');
            if ($ticket->getQrToken() !== null) {
                $response->headers->setCookie(
                    Cookie::create('latest_qr_token')
                        ->withValue($ticket->getQrToken())
                        ->withExpires(new \DateTimeImmutable('+7 days'))
                        ->withPath('/')
                        ->withSameSite(Cookie::SAMESITE_LAX)
                );
            }

            return $response;
        }

        return $this->render('ticket/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/festival/new', name: 'ticket_festival_new', methods: ['GET'])]
    public function redirectLegacyFestivalRoute(): Response
    {
        return $this->redirectToRoute('ticket_new');
    }

    #[Route('/festival/pass/{token}', name: 'festival_pass_show', methods: ['GET'])]
    public function showFestivalPass(
        string $token,
        Request $request,
        TicketRepository $ticketRepository,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        $ticket = $ticketRepository->findOneByQrToken($token);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Festival pass QR token was not found.');
        }

        $doorUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('festival_door_scan', ['token' => $ticket->getQrToken()]);
        $qrSvg = $qrCodeGenerator->generateSvg($doorUrl, 240);

        return $this->render('ticket/festival_pass.html.twig', [
            'ticket' => $ticket,
            'qr_svg' => $qrSvg,
            'door_url' => $doorUrl,
            'is_qr_valid' => $ticket->isQrValid(),
            'remaining_seconds' => $ticket->getRemainingQrSeconds(),
        ]);
    }

    #[Route('/festival/pass/{token}/qr.png', name: 'festival_pass_qr_png', methods: ['GET'])]
    public function downloadFestivalQrPng(
        string $token,
        Request $request,
        TicketRepository $ticketRepository,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        $ticket = $ticketRepository->findOneByQrToken($token);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Festival pass QR token was not found.');
        }

        $doorUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('festival_door_scan', ['token' => $ticket->getQrToken()]);
        $pngBytes = $qrCodeGenerator->generatePng($doorUrl, 8, 3);

        return new Response($pngBytes, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="festival-ticket-%d-qr.png"', (int) $ticket->getId()),
        ]);
    }

    #[Route('/festival/door/{token}', name: 'festival_door_scan', methods: ['GET'])]
    public function scanAtFestivalDoor(
        string $token,
        Request $request,
        TicketRepository $ticketRepository,
    ): Response {
        $ticket = $ticketRepository->findOneByQrToken($token);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Invalid QR code — no matching festival ticket found.');
        }

        $isStaff = $request->hasPreviousSession() && $request->getSession()->get('admin_authenticated') === true;

        if ($ticket->isUsed()) {
            $state = 'already_used';
            $statusCode = Response::HTTP_CONFLICT;
        } elseif ($ticket->isQrExpired()) {
            $state = 'expired';
            $statusCode = Response::HTTP_FORBIDDEN;
        } else {
            $state = 'ready_to_scan';
            $statusCode = Response::HTTP_OK;
        }

        return $this->render('ticket/festival_door.html.twig', [
            'ticket' => $ticket,
            'door_state' => $state,
            'is_staff' => $isStaff,
            'staff_error' => null,
            'remaining_seconds' => $ticket->getRemainingQrSeconds(),
        ], new Response('', $statusCode));
    }

    #[Route('/festival/door/{token}/check-in', name: 'festival_door_checkin', methods: ['POST'])]
    public function checkInAtFestivalDoor(
        string $token,
        Request $request,
        TicketRepository $ticketRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $ticket = $ticketRepository->findOneByQrToken($token);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Invalid QR code — no matching festival ticket found.');
        }

        $session = $request->getSession();
        $isStaff = $session->get('admin_authenticated') === true;

        if (!$this->isCsrfTokenValid('door_checkin_' . $ticket->getId(), (string) $request->request->get('_csrf_token', ''))) {
            return $this->redirectToRoute('festival_door_scan', ['token' => $token]);
        }

        if (!$isStaff) {
            $enteredPin = trim((string) $request->request->get('staff_code', ''));
            if ($enteredPin === '' || !hash_equals($this->adminAccessCode, $enteredPin)) {
                return $this->render('ticket/festival_door.html.twig', [
                    'ticket' => $ticket,
                    'door_state' => $ticket->isUsed() ? 'already_used' : ($ticket->isQrExpired() ? 'expired' : 'ready_to_scan'),
                    'is_staff' => false,
                    'staff_error' => 'Invalid staff code. Enter 1234 to authorize entrance check-in.',
                    'remaining_seconds' => $ticket->getRemainingQrSeconds(),
                ], new Response('', Response::HTTP_UNAUTHORIZED));
            }
            $session->set('admin_authenticated', true);
            $isStaff = true;
        }

        if ($ticket->isUsed()) {
            return $this->render('ticket/festival_door.html.twig', [
                'ticket' => $ticket,
                'door_state' => 'already_used',
                'is_staff' => $isStaff,
                'staff_error' => null,
                'remaining_seconds' => 0,
            ], new Response('', Response::HTTP_CONFLICT));
        }

        if ($ticket->isQrExpired()) {
            return $this->render('ticket/festival_door.html.twig', [
                'ticket' => $ticket,
                'door_state' => 'expired',
                'is_staff' => $isStaff,
                'staff_error' => null,
                'remaining_seconds' => 0,
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        if ($ticketRepository->markTicketAsUsedAtomically((int) $ticket->getId())) {
            $entityManager->refresh($ticket);

            return $this->render('ticket/festival_door.html.twig', [
                'ticket' => $ticket,
                'door_state' => 'admitted_now',
                'is_staff' => $isStaff,
                'staff_error' => null,
                'remaining_seconds' => $ticket->getRemainingQrSeconds(),
            ]);
        }

        $entityManager->refresh($ticket);

        return $this->render('ticket/festival_door.html.twig', [
            'ticket' => $ticket,
            'door_state' => 'already_used',
            'is_staff' => $isStaff,
            'staff_error' => null,
            'remaining_seconds' => 0,
        ], new Response('', Response::HTTP_CONFLICT));
    }

    private function rememberTicketInSession(Request $request, Ticket $ticket): void
    {
        if ($ticket->getId() === null) {
            return;
        }

        $session = $request->getSession();
        if ($ticket->getQrToken() !== null) {
            $session->set('latest_qr_token', $ticket->getQrToken());
        }

        $submittedIds = $session->get('submitted_ticket_ids', []);
        if (!is_array($submittedIds)) {
            $submittedIds = [];
        }

        array_unshift($submittedIds, $ticket->getId());
        $submittedIds = array_slice(array_values(array_unique($submittedIds)), 0, 10);
        $session->set('submitted_ticket_ids', $submittedIds);
    }
}
