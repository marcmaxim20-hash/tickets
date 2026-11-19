<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Form\EventType;
use App\Service\CustomerAuth;
use App\Service\EventImageUploader;
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

            $event->setOrganizer($organizer);

            $entityManager->persist($event);
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Event "%s" (%s — %d seats) at %s (%s) has been published!',
                    $event->getTitle(),
                    $event->getCategoryLabel(),
                    $event->getTotalSeats(),
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
        ], new Response('', $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
