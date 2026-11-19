<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Ticket;
use App\Form\TicketType;
use App\Repository\EventRepository;
use App\Repository\TicketRepository;
use App\Service\CustomerAuth;
use App\Service\QrCodeGenerator;
use App\Service\TicketCatalog;
use App\Service\TicketConfirmationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        EventRepository $eventRepository,
        CustomerAuth $customerAuth,
        TicketRepository $ticketRepository,
    ): Response {
        $categoryParam = (string) $request->query->get('category', '');
        $activeCategory = array_key_exists($categoryParam, Event::getAvailableCategories()) ? $categoryParam : null;

        $events = $eventRepository->findFiltered($activeCategory);
        $customer = $customerAuth->getCurrentCustomer();
        $myTicketsCount = $customer !== null ? count($ticketRepository->findForCustomer($customer)) : 0;

        return $this->render('ticket/index.html.twig', [
            'events' => $events,
            'categories' => Event::getAvailableCategories(),
            'active_category' => $activeCategory,
            'my_tickets_count' => $myTicketsCount,
        ]);
    }

    #[Route('/events/{id}', name: 'event_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showEvent(
        int $id,
        EventRepository $eventRepository,
        TicketCatalog $ticketCatalog,
    ): Response {
        $event = $eventRepository->findWithSiteSeats($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event not found.');
        }

        return $this->render('ticket/event_show.html.twig', [
            'event' => $event,
            'tiers' => $ticketCatalog->getTiers(),
        ]);
    }

    #[Route('/tickets/new', name: 'ticket_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        EventRepository $eventRepository,
        TicketRepository $ticketRepository,
        CustomerAuth $customerAuth,
        TicketCatalog $ticketCatalog,
        TicketConfirmationMailer $confirmationMailer,
    ): Response {
        $loggedInCustomer = $customerAuth->getCurrentCustomer();
        if ($loggedInCustomer === null) {
            $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $request->getRequestUri());
            $this->addFlash('warning', 'Please sign in or create a Customer account so only your account can buy and view your tickets.');

            return $this->redirectToRoute('account_login');
        }

        if ($loggedInCustomer->isOrganizer()) {
            $this->addFlash('warning', 'Organizer accounts can only create new events. Please sign in with a Customer account to buy tickets.');

            $requestedEventId = (int) ($request->request->get('event_id') ?: $request->query->get('event', 0));
            if ($requestedEventId > 0) {
                return $this->redirectToRoute('event_show', ['id' => $requestedEventId]);
            }

            return $this->redirectToRoute('account_dashboard');
        }

        $allEvents = $eventRepository->findFiltered();
        if ($allEvents === []) {
            $this->addFlash('warning', 'No events are currently scheduled. An Organizer or Admin needs to create an event first.');

            return $this->redirectToRoute('ticket_home');
        }

        $requestedEventId = (int) ($request->request->get('event_id') ?: $request->query->get('event', 0));
        $selectedEvent = $requestedEventId > 0 ? $eventRepository->find($requestedEventId) : null;
        if ($selectedEvent === null) {
            $selectedEvent = $allEvents[0];
        }

        $ticket = new Ticket();
        $ticket->setContact($loggedInCustomer->getEmail());
        $ticket->setEvent($selectedEvent);

        if (!$request->isMethod('POST')) {
            $requestedTier = (string) $request->query->get('tier', TicketCatalog::DEFAULT_TIER);
            if ($ticketCatalog->hasTier($requestedTier)) {
                $ticket->setTierKey($requestedTier);
            }
        }

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($selectedEvent->getId() === null) {
                throw $this->createNotFoundException('Selected event not found.');
            }

            $conn = $entityManager->getConnection();
            $conn->beginTransaction();
            try {
                if (!$eventRepository->hasAvailableSeatForUpdate($selectedEvent->getId())) {
                    $conn->rollBack();
                    $this->addFlash(
                        'warning',
                        sprintf('Sorry, "%s" at %s is completely sold out (0 seats left).', $selectedEvent->getTitle(), $selectedEvent->getVenue())
                    );

                    return $this->redirectToRoute('event_show', ['id' => $selectedEvent->getId()]);
                }

                $selectedTier = $ticketCatalog->getTier($ticket->getTierKey());
                $uniqueToken = $ticketRepository->generateUniqueQrToken();

                $ticket->setEvent($selectedEvent);
                $ticket->setCustomer($loggedInCustomer);
                $ticket->setContact($loggedInCustomer->getEmail());
                $ticket->setTierKey($selectedTier['key']);
                $ticket->setPriceCents($selectedTier['price_cents']);
                $ticket->setTitle($selectedTier['name']);
                $locationSuffix = $selectedEvent->getLocation() !== ''
                    ? ' (' . $selectedEvent->getLocation() . ')'
                    : '';
                $ticket->setDescription(sprintf(
                    '%s — %s (%s) at %s%s',
                    $selectedEvent->getTitle(),
                    $selectedTier['name'],
                    $selectedTier['price_display'],
                    $selectedEvent->getVenue(),
                    $locationSuffix
                ));
                $ticket->initializeFestivalQr($uniqueToken);

                $entityManager->persist($ticket);
                $entityManager->flush();
                $conn->commit();
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            $baseUrl = $request->getSchemeAndHttpHost();
            $doorUrl = $baseUrl . $this->generateUrl('festival_door_scan', ['token' => $ticket->getQrToken()]);
            $passUrl = $baseUrl . $this->generateUrl('festival_pass_show', ['token' => $ticket->getQrToken()]);

            $mailResult = $confirmationMailer->sendFestivalTicketConfirmation($ticket, $doorUrl, $passUrl);

            if ($mailResult['status'] === TicketConfirmationMailer::STATUS_FAILED) {
                $this->addFlash(
                    'warning',
                    sprintf('Your ticket is saved in your account, though emailing %s failed (%s).', $ticket->getContact(), $mailResult['error'] ?? 'SMTP error')
                );
            }

            return $this->redirectToRoute('ticket_purchased_confirmation', ['id' => $ticket->getId()]);
        }

        return $this->render('ticket/new.html.twig', [
            'form' => $form,
            'tiers' => $ticketCatalog->getTiers(),
            'selected_event' => $selectedEvent,
            'all_events' => $allEvents,
            'customer' => $loggedInCustomer,
        ]);
    }

    #[Route('/account/tickets/{id}/confirmed', name: 'ticket_purchased_confirmation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirmed(
        int $id,
        Request $request,
        TicketRepository $ticketRepository,
        CustomerAuth $customerAuth,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        $customer = $customerAuth->getCurrentCustomer();
        if ($customer === null) {
            $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $request->getRequestUri());

            return $this->redirectToRoute('account_login');
        }

        $ticket = $ticketRepository->find($id);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        if (!$ticket->isOwnedBy($customer)) {
            throw $this->createAccessDeniedException('Only your own account can view tickets you bought.');
        }

        $doorUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('festival_door_scan', ['token' => $ticket->getQrToken()]);
        $qrSvg = $qrCodeGenerator->generateSvg($doorUrl, 220);

        return $this->render('ticket/confirmed.html.twig', [
            'ticket' => $ticket,
            'qr_svg' => $qrSvg,
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
        CustomerAuth $customerAuth,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        $ticket = $ticketRepository->findOneByQrToken($token);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Festival pass QR token was not found.');
        }

        $isStaff = $this->hasStaffOrAdminSession($request);
        $customer = $customerAuth->getCurrentCustomer();

        if (!$isStaff) {
            if ($customer === null) {
                $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $request->getRequestUri());
                $this->addFlash('warning', 'Please sign in to your account to view your ticket QR code.');

                return $this->redirectToRoute('account_login');
            }
            if (!$ticket->isOwnedBy($customer)) {
                throw $this->createAccessDeniedException('Only the customer account that bought this ticket can view it.');
            }
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
        CustomerAuth $customerAuth,
        QrCodeGenerator $qrCodeGenerator,
    ): Response {
        $ticket = $ticketRepository->findOneByQrToken($token);
        if ($ticket === null || !$ticket->isFestivalTicket()) {
            throw $this->createNotFoundException('Festival pass QR token was not found.');
        }

        $isStaff = $this->hasStaffOrAdminSession($request);
        $customer = $customerAuth->getCurrentCustomer();

        if (!$isStaff) {
            if ($customer === null) {
                $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $request->getRequestUri());

                return $this->redirectToRoute('account_login');
            }
            if (!$ticket->isOwnedBy($customer)) {
                throw $this->createAccessDeniedException('Only the customer account that bought this ticket can download its QR code.');
            }
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

        $isStaff = $this->hasStaffOrAdminSession($request);

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
        $isStaff = $this->hasStaffOrAdminSession($request);

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
                    'staff_error' => 'Invalid staff PIN. Only authorized gate staff can check in tickets.',
                    'remaining_seconds' => $ticket->getRemainingQrSeconds(),
                ], new Response('', Response::HTTP_UNAUTHORIZED));
            }
            $session->set(AdminController::SESSION_STAFF_KEY, true);
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

    private function hasStaffOrAdminSession(Request $request): bool
    {
        if (!$request->hasPreviousSession()) {
            return false;
        }

        $session = $request->getSession();

        return $session->get(AdminController::SESSION_ADMIN_KEY) === true
            || $session->get(AdminController::SESSION_STAFF_KEY) === true;
    }
}
