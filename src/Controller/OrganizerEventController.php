<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Form\EventType;
use App\Repository\EventRepository;
use App\Service\CustomerAuth;
use App\Service\EventImageUploader;
use App\Service\SeatMapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/organizer/events')]
class OrganizerEventController extends AbstractController
{
    #[Route('/new', name: 'organizer_events_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        CustomerAuth $customerAuth,
        EventImageUploader $imageUploader,
        SeatMapService $seatMapService,
        EntityManagerInterface $entityManager,
    ): Response {
        $organizer = $customerAuth->getCurrentCustomer();
        if ($organizer === null) {
            $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $request->getRequestUri());
            $this->addFlash('warning', 'Please sign in with an Organizer account to create new events.');

            return $this->redirectToRoute('account_login');
        }

        if (!$organizer->isOrganizer()) {
            throw $this->createAccessDeniedException('Only Organizer accounts can create new events.');
        }

        $event = new Event();
        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ?UploadedFile $uploadedFile */
            $uploadedFile = $form->get('imageFile')->getData();
            $pastedUrl = trim((string) $form->get('imageUrl')->getData());

            if ($uploadedFile instanceof UploadedFile) {
                $event->setImagePath($imageUploader->saveUploadedEventImage($uploadedFile));
            } elseif ($pastedUrl !== '' && $imageUploader->isValidExternalImageUrl($pastedUrl)) {
                $event->setImagePath($pastedUrl);
            }

            if ($event->isSeatMapEnabled()) {
                $rawConfig = (string) ($form->get('seatMapConfigJson')->getData() ?? '');
                $sanitized = $seatMapService->sanitizeSeatMapConfigJson(
                    $rawConfig,
                    $event->getSeatRows(),
                    $event->getSeatsPerRow(),
                );
                $event->setSeatMapConfig($sanitized);
                if ($sanitized !== null) {
                    $event->setSeatRows($sanitized['rows']);
                    $event->setSeatsPerRow(min(20, $sanitized['cols']));
                }
            } else {
                $event->setSeatMapConfig(null);
            }

            $event->setOrganizer($organizer);
            $event->syncTotalSeatsFromSeatMap();

            $entityManager->persist($event);
            $entityManager->flush();

            $seatMapSuffix = $event->isSeatMapEnabled()
                ? sprintf(' with %d-seat interactive map', $event->getTotalSeats())
                : '';

            $this->addFlash(
                'success',
                sprintf(
                    'Event "%s" (%s — %d seats%s) at %s (%s) has been published!',
                    $event->getTitle(),
                    $event->getCategoryLabel(),
                    $event->getTotalSeats(),
                    $seatMapSuffix,
                    $event->getVenue(),
                    $event->getLocation()
                )
            );

            return $this->redirectToRoute('account_dashboard');
        }

        return $this->render('organizer/create_event.html.twig', [
            'form' => $form,
            'event' => $event,
            'organizer' => $organizer,
            'initial_seat_config' => null,
            'taken_seats' => [],
        ], new Response('', $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/seats', name: 'organizer_events_seats', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editSeats(
        int $id,
        Request $request,
        CustomerAuth $customerAuth,
        EventRepository $eventRepository,
        SeatMapService $seatMapService,
        EntityManagerInterface $entityManager,
    ): Response {
        $organizer = $customerAuth->getCurrentCustomer();
        if ($organizer === null) {
            $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $request->getRequestUri());
            $this->addFlash('warning', 'Please sign in with an Organizer account to arrange seat positions.');

            return $this->redirectToRoute('account_login');
        }

        if (!$organizer->isOrganizer()) {
            throw $this->createAccessDeniedException('Only Organizer or Admin accounts can change seat positions.');
        }

        $event = $eventRepository->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event not found.');
        }

        if (
            $event->getOrganizer() !== null
            && $event->getOrganizer()->getId() !== $organizer->getId()
        ) {
            throw $this->createAccessDeniedException('You can only rearrange seats on events you created.');
        }

        $takenSeats = $eventRepository->getTakenSeatLabels((int) $event->getId());

        if ($request->isMethod('POST')) {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('arrange_seats_' . $event->getId(), $csrf)) {
                $this->addFlash('warning', 'Your session expired. Please save the seat layout again.');

                return $this->redirectToRoute('organizer_events_seats', ['id' => $event->getId()]);
            }

            $rawConfig = (string) $request->request->get('seat_map_config_json', '');
            $layout = (string) $request->request->get('seat_map_layout', $event->getSeatMapLayout());
            $rows = max(1, min(26, (int) $request->request->get('seat_rows', $event->getSeatRows())));
            $cols = max(2, min(20, (int) $request->request->get('seats_per_row', $event->getSeatsPerRow())));

            $event->setSeatMapEnabled(true);
            $event->setSeatMapLayout($layout);

            $sanitized = $seatMapService->sanitizeSeatMapConfigJson($rawConfig, $rows, $cols, $takenSeats);
            $event->setSeatMapConfig($sanitized);
            if ($sanitized !== null) {
                $event->setSeatRows($sanitized['rows']);
                $event->setSeatsPerRow(min(20, $sanitized['cols']));
            } else {
                $event->setSeatRows($rows);
                $event->setSeatsPerRow($cols);
            }
            $event->syncTotalSeatsFromSeatMap();
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf('Updated seat positions for "%s" (%d seats arranged)!', $event->getTitle(), $event->getTotalSeats())
            );

            return $this->redirectToRoute('event_show', ['id' => $event->getId()]);
        }

        $seatMap = $seatMapService->buildSeatMap($event, $takenSeats);

        return $this->render('organizer/arrange_seats.html.twig', [
            'event' => $event,
            'organizer' => $organizer,
            'seat_map' => $seatMap,
            'taken_seats' => $takenSeats,
            'available_layouts' => Event::getAvailableSeatMapLayouts(),
        ]);
    }

    #[Route('/{id}/delete', name: 'organizer_events_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CustomerAuth $customerAuth,
        EventRepository $eventRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $organizer = $customerAuth->getCurrentCustomer();
        if ($organizer === null) {
            $request->getSession()->set(AccountController::SESSION_TARGET_PATH, $this->generateUrl('account_dashboard'));
            $this->addFlash('warning', 'Please sign in with your Organizer account to delete your events.');

            return $this->redirectToRoute('account_login');
        }

        if (!$organizer->isOrganizer()) {
            throw $this->createAccessDeniedException('Only Organizer or Admin accounts can delete events.');
        }

        $event = $eventRepository->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event not found.');
        }

        if (
            $event->getOrganizer() === null
            || $event->getOrganizer()->getId() !== $organizer->getId()
        ) {
            throw $this->createAccessDeniedException('You can only delete events that you created.');
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('organizer_delete_event_' . $event->getId(), $csrfToken)) {
            $this->addFlash('warning', 'Your session expired. Please try deleting the event again.');

            return $this->redirectToRoute('account_dashboard');
        }

        $title = $event->getTitle();
        foreach ($event->getTickets() as $ticket) {
            $ticket->setEvent(null);
        }

        $entityManager->remove($event);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Your event "%s" has been deleted.', $title));

        return $this->redirectToRoute('account_dashboard');
    }
}
