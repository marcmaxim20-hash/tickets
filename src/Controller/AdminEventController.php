<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Form\EventType;
use App\Repository\EventRepository;
use App\Service\EventImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/events')]
class AdminEventController extends AbstractController
{
    private const SESSION_TARGET_KEY = 'admin_target_path';

    public function __construct(
        private readonly EventImageUploader $imageUploader,
    ) {
    }

    #[Route('', name: 'admin_events_index', methods: ['GET'])]
    public function index(Request $request, EventRepository $eventRepository): Response
    {
        if (!$this->isAdminAuthenticated($request)) {
            $request->getSession()->set(self::SESSION_TARGET_KEY, $request->getRequestUri());

            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/events/index.html.twig', [
            'events' => $eventRepository->findFiltered(),
        ]);
    }

    #[Route('/new', name: 'admin_events_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            $request->getSession()->set(self::SESSION_TARGET_KEY, $request->getRequestUri());

            return $this->redirectToRoute('admin_login');
        }

        $event = new Event();
        $form = $this->createForm(EventType::class, $event);
        $currentImage = $event->getImagePath();
        if ($currentImage !== null && $this->isValidExternalImageUrl($currentImage)) {
            $form->get('imageUrl')->setData($currentImage);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ?UploadedFile $uploadedFile */
            $uploadedFile = $form->get('imageFile')->getData();
            $pastedUrl = trim((string) $form->get('imageUrl')->getData());

            if ($uploadedFile instanceof UploadedFile) {
                $savedPath = $this->saveUploadedEventImage($uploadedFile);
                $event->setImagePath($savedPath);
            } elseif ($pastedUrl !== '' && $this->isValidExternalImageUrl($pastedUrl)) {
                $event->setImagePath($pastedUrl);
            }

            $entityManager->persist($event);
            $entityManager->flush();

            $this->addFlash(
                'admin_success',
                sprintf('Event "%s" (%s — %d seats) created!', $event->getTitle(), $event->getCategoryLabel(), $event->getTotalSeats())
            );

            return $this->redirectToRoute('admin_events_index');
        }

        return $this->render('admin/events/form.html.twig', [
            'form' => $form,
            'event' => $event,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_events_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        EventRepository $eventRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            $request->getSession()->set(self::SESSION_TARGET_KEY, $request->getRequestUri());

            return $this->redirectToRoute('admin_login');
        }

        $event = $eventRepository->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event not found.');
        }

        $form = $this->createForm(EventType::class, $event);
        $currentImage = $event->getImagePath();
        if ($currentImage !== null && $this->isValidExternalImageUrl($currentImage)) {
            $form->get('imageUrl')->setData($currentImage);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ?UploadedFile $uploadedFile */
            $uploadedFile = $form->get('imageFile')->getData();
            $pastedUrl = trim((string) $form->get('imageUrl')->getData());

            if ($uploadedFile instanceof UploadedFile) {
                $savedPath = $this->saveUploadedEventImage($uploadedFile);
                $event->setImagePath($savedPath);
            } elseif ($pastedUrl !== '' && $this->isValidExternalImageUrl($pastedUrl)) {
                $event->setImagePath($pastedUrl);
            }

            $entityManager->flush();

            $this->addFlash(
                'admin_success',
                sprintf('Event "%s" updated!', $event->getTitle())
            );

            return $this->redirectToRoute('admin_events_index');
        }

        return $this->render('admin/events/form.html.twig', [
            'form' => $form,
            'event' => $event,
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/photo', name: 'admin_events_update_photo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updatePhoto(
        int $id,
        Request $request,
        EventRepository $eventRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isAdminAuthenticated($request)) {
            $request->getSession()->set(self::SESSION_TARGET_KEY, $request->getRequestUri());

            return $this->redirectToRoute('admin_login');
        }

        $event = $eventRepository->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Event not found.');
        }

        $redirectRoute = $request->request->get('redirect_to') === 'dashboard'
            ? 'admin_dashboard'
            : 'admin_events_index';

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('event_photo_' . $event->getId(), $csrfToken)) {
            $this->addFlash('admin_error', 'Security session expired. Please upload the photo again.');

            return $this->redirectToRoute($redirectRoute);
        }

        $uploadedFile = $request->files->get('photo');
        $photoUrl = trim((string) $request->request->get('photo_url', ''));

        if ($uploadedFile instanceof UploadedFile) {
            if (!$uploadedFile->isValid()) {
                $this->addFlash('admin_error', 'The uploaded photo exceeded size limits or failed to upload.');

                return $this->redirectToRoute($redirectRoute);
            }

            $mime = (string) ($uploadedFile->getMimeType() ?: $uploadedFile->getClientMimeType());
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml'];
            if (!in_array($mime, $allowedMimes, true)) {
                $this->addFlash('admin_error', 'Please select a valid photo file (JPG, PNG, WEBP, AVIF, GIF, or SVG).');

                return $this->redirectToRoute($redirectRoute);
            }

            $savedPath = $this->saveUploadedEventImage($uploadedFile);
            $event->setImagePath($savedPath);
            $entityManager->flush();

            $this->addFlash('admin_success', sprintf('Photo updated for "%s"!', $event->getTitle()));
        } elseif ($photoUrl !== '' && $this->isValidExternalImageUrl($photoUrl)) {
            $event->setImagePath($photoUrl);
            $entityManager->flush();

            $this->addFlash('admin_success', sprintf('Photo URL updated for "%s"!', $event->getTitle()));
        } else {
            $this->addFlash('admin_error', 'Please choose an image file or enter a valid https:// image URL.');
        }

        return $this->redirectToRoute($redirectRoute);
    }

    private function saveUploadedEventImage(UploadedFile $file): string
    {
        return $this->imageUploader->saveUploadedEventImage($file);
    }

    private function isValidExternalImageUrl(string $url): bool
    {
        return $this->imageUploader->isValidExternalImageUrl($url);
    }

    private function isAdminAuthenticated(Request $request): bool
    {
        return $request->hasPreviousSession()
            && $request->getSession()->get(AdminController::SESSION_ADMIN_KEY) === true;
    }
}
