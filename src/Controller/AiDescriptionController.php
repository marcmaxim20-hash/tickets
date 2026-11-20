<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\AiEventDescriptionGenerator;
use App\Service\AiEventQuizMatcher;
use App\Service\CustomerAuth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AiDescriptionController extends AbstractController
{
    #[Route('/ai/event-description', name: 'ai_generate_event_description', methods: ['POST'])]
    public function generateEventDescription(
        Request $request,
        CustomerAuth $customerAuth,
        AiEventDescriptionGenerator $generator,
    ): JsonResponse {
        $isAdmin = $request->hasPreviousSession()
            && $request->getSession()->get(AdminController::SESSION_ADMIN_KEY) === true;
        $viewer = $customerAuth->getCurrentCustomer();
        $isOrganizer = $viewer !== null && $viewer->isOrganizer();

        if (!$isAdmin && !$isOrganizer) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Only signed-in Organizers or Admins can generate AI event descriptions.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode((string) $request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Invalid JSON request payload.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($data)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Invalid request payload.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $csrfToken = (string) ($data['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('ai_event_description', $csrfToken)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Your session expired. Please refresh the page and try again.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));

        if ($title === '' && $venue === '') {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Please enter an Event Name (or Venue) first so the AI knows what to write about.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $generator->generateDescription([
                'title' => $title,
                'category' => $category,
                'venue' => $venue,
                'location' => (string) ($data['location'] ?? ''),
                'start_date' => (string) ($data['start_date'] ?? ''),
                'end_date' => (string) ($data['end_date'] ?? ''),
                'total_seats' => (string) ($data['total_seats'] ?? ''),
                'image_url' => (string) ($data['image_url'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'ok' => true,
            'description' => $result['description'],
            'model' => $result['model'],
        ]);
    }

    #[Route('/ai/event-quiz', name: 'ai_event_quiz', methods: ['POST'])]
    public function eventTasteQuiz(
        Request $request,
        EventRepository $eventRepository,
        AiEventQuizMatcher $quizMatcher,
    ): JsonResponse {
        try {
            $data = json_decode((string) $request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Invalid quiz submission payload.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($data)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Invalid quiz submission payload.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $csrfToken = (string) ($data['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('ai_event_quiz', $csrfToken)) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Your session expired. Please refresh the page and retake the AI quiz.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $answers = [
            'vibe' => trim((string) ($data['vibe'] ?? 'High-Energy & Live Music')),
            'setting' => trim((string) ($data['setting'] ?? 'Any Setting — Surprise Me')),
            'company' => trim((string) ($data['company'] ?? 'Friends Group')),
            'budget' => trim((string) ($data['budget'] ?? 'Preferred (~39$)')),
            'extra_notes' => mb_substr(trim((string) ($data['extra_notes'] ?? '')), 0, 200),
        ];

        $events = $eventRepository->findFiltered();
        $result = $quizMatcher->matchEvents($answers, $events);
        $categoryLabels = Event::getAvailableCategories();

        $formattedRecs = [];
        foreach ($result['recommendations'] as $rec) {
            $event = $rec['event'];
            $formattedRecs[] = [
                'event_id' => $event->getId(),
                'title' => $event->getTitle(),
                'category_label' => $event->getCategoryLabel(),
                'venue' => $event->getVenue(),
                'location' => $event->getLocation(),
                'image_url' => $event->getDisplayImageUrl(),
                'available_seats' => $event->getAvailableSeats(),
                'total_seats' => $event->getTotalSeats(),
                'start_date' => $event->getStartDate()?->format('M j, Y H:i') ?? '',
                'event_url' => $this->generateUrl('event_show', ['id' => $event->getId()]),
                'match_score' => $rec['match_score'],
                'reason' => $rec['reason'],
            ];
        }

        $formattedCategories = [];
        foreach ($result['suggested_categories'] as $catKey) {
            if (isset($categoryLabels[$catKey])) {
                $formattedCategories[] = [
                    'key' => $catKey,
                    'label' => $categoryLabels[$catKey],
                    'url' => $this->generateUrl('ticket_home', ['category' => $catKey]) . '#events-showcase',
                ];
            }
        }

        return new JsonResponse([
            'ok' => true,
            'personality_title' => $result['personality_title'],
            'summary' => $result['summary'],
            'ai_powered' => $result['ai_powered'],
            'suggested_categories' => $formattedCategories,
            'recommendations' => $formattedRecs,
        ]);
    }
}
