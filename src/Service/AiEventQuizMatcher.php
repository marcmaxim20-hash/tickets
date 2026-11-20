<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;

class AiEventQuizMatcher
{
    private const API_URL = 'https://integrate.api.nvidia.com/v1/chat/completions';

    private const FALLBACK_MODELS = [
        'minimaxai/minimax-m3.1-flash',
        'nvidia/nemotron-3.5-lightning-30b-a3b',
    ];

    public function __construct(
        private readonly string $nvidiaApiKey,
        private readonly string $nvidiaAiModel,
    ) {
    }

    /**
     * @param array{
     *     vibe: string,
     *     setting: string,
     *     company: string,
     *     budget: string,
     *     extra_notes: string
     * } $answers
     * @param list<Event> $events
     * @return array{
     *     personality_title: string,
     *     summary: string,
     *     suggested_categories: list<string>,
     *     recommendations: list<array{event: Event, match_score: int, reason: string}>,
     *     ai_powered: bool,
     *     model: string
     * }
     */
    public function matchEvents(array $answers, array $events): array
    {
        $eventsById = [];
        $catalogForPrompt = [];

        foreach ($events as $event) {
            $id = $event->getId();
            if ($id === null) {
                continue;
            }
            $eventsById[$id] = $event;
            $catalogForPrompt[] = [
                'id' => $id,
                'title' => mb_substr($event->getTitle(), 0, 80),
                'cat' => $event->getCategory(),
                'venue' => mb_substr($event->getVenue(), 0, 60),
                'location' => mb_substr($event->getLocation(), 0, 60),
            ];
        }

        $apiKey = trim($this->nvidiaApiKey);
        if ($apiKey !== '' && $eventsById !== []) {
            $aiResult = $this->tryNvidiaMatch($apiKey, $answers, $catalogForPrompt, $eventsById);
            if ($aiResult !== null) {
                return $aiResult;
            }
        }

        return $this->buildFallbackMatch($answers, $events);
    }

    /**
     * @param array<string, string> $answers
     * @param list<array<string, mixed>> $catalogForPrompt
     * @param array<int, Event> $eventsById
     * @return ?array{
     *     personality_title: string,
     *     summary: string,
     *     suggested_categories: list<string>,
     *     recommendations: list<array{event: Event, match_score: int, reason: string}>,
     *     ai_powered: bool,
     *     model: string
     * }
     */
    private function tryNvidiaMatch(string $apiKey, array $answers, array $catalogForPrompt, array $eventsById): ?array
    {
        $prompt = "Match a visitor to up to 3 events.\n"
            . "Visitor: vibe=" . $answers['vibe']
            . "; setting=" . $answers['setting']
            . "; company=" . $answers['company']
            . "; budget=" . $answers['budget']
            . ($answers['extra_notes'] !== '' ? '; notes=' . $answers['extra_notes'] : '')
            . "\nEvents: " . json_encode($catalogForPrompt, JSON_UNESCAPED_UNICODE)
            . "\nReturn strict JSON ONLY (no markdown): "
            . '{"personality_title":"2-4 words","summary":"1-2 sentences","suggested_categories":["concert","festival"],"recommendations":[{"event_id":1,"match_score":95,"reason":"1 short sentence"}]}'
            . "\nUse only event_ids from Events and category keys from: " . implode(',', array_keys(Event::getAvailableCategories())) . '.';

        $modelsToTry = ['minimaxai/minimax-m3.1-flash'];

        foreach ($modelsToTry as $model) {
            $raw = $this->callNvidiaJson($apiKey, $model, $prompt, 22);
            if ($raw === null) {
                continue;
            }

            $parsed = $this->parseAiResponse($raw, $eventsById, $model);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function callNvidiaJson(string $apiKey, string $model, string $prompt, int $timeoutSeconds): ?string
    {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert event matchmaker AI. Always output strict, valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens' => 900,
            'stream' => false,
        ];

        if (str_contains($model, 'kimi')) {
            $payload['reasoning_effort'] = 'low';
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            return null;
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        try {
            $decoded = json_decode((string) $body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;

        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    /**
     * @param array<int, Event> $eventsById
     * @return ?array{
     *     personality_title: string,
     *     summary: string,
     *     suggested_categories: list<string>,
     *     recommendations: list<array{event: Event, match_score: int, reason: string}>,
     *     ai_powered: bool,
     *     model: string
     * }
     */
    private function parseAiResponse(string $rawContent, array $eventsById, string $model): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($rawContent)) ?? trim($rawContent);
        $firstBrace = strpos($cleaned, '{');
        $lastBrace = strrpos($cleaned, '}');
        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        $jsonSlice = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
        try {
            $data = json_decode($jsonSlice, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $personality = trim((string) ($data['personality_title'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($personality === '' || $summary === '') {
            return null;
        }

        $validCategories = Event::getAvailableCategories();
        $suggested = [];
        if (isset($data['suggested_categories']) && is_array($data['suggested_categories'])) {
            foreach ($data['suggested_categories'] as $cat) {
                $k = mb_strtolower(trim((string) $cat));
                if (array_key_exists($k, $validCategories) && !in_array($k, $suggested, true)) {
                    $suggested[] = $k;
                }
            }
        }

        $recommendations = [];
        $seenIds = [];
        if (isset($data['recommendations']) && is_array($data['recommendations'])) {
            foreach ($data['recommendations'] as $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $eid = (int) ($rec['event_id'] ?? 0);
                if (!isset($eventsById[$eid]) || isset($seenIds[$eid])) {
                    continue;
                }
                $seenIds[$eid] = true;
                $score = max(55, min(99, (int) ($rec['match_score'] ?? 85)));
                $reason = trim((string) ($rec['reason'] ?? 'A great match for your vibe and preferences.'));
                $recommendations[] = [
                    'event' => $eventsById[$eid],
                    'match_score' => $score,
                    'reason' => $reason !== '' ? $reason : 'A great match for your vibe and preferences.',
                ];
            }
        }

        if ($recommendations === []) {
            return null;
        }

        usort($recommendations, static fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);

        return [
            'personality_title' => $personality,
            'summary' => $summary,
            'suggested_categories' => array_slice($suggested, 0, 3),
            'recommendations' => array_slice($recommendations, 0, 3),
            'ai_powered' => true,
            'model' => $model,
        ];
    }

    /**
     * Deterministic local match fallback so the quiz always works even offline or in tests.
     *
     * @param array<string, string> $answers
     * @param list<Event> $events
     * @return array{
     *     personality_title: string,
     *     summary: string,
     *     suggested_categories: list<string>,
     *     recommendations: list<array{event: Event, match_score: int, reason: string}>,
     *     ai_powered: bool,
     *     model: string
     * }
     */
    private function buildFallbackMatch(array $answers, array $events): array
    {
        $vibe = mb_strtolower($answers['vibe']);
        $setting = mb_strtolower($answers['setting']);
        $notes = mb_strtolower($answers['extra_notes']);

        $preferredCategories = match (true) {
            str_contains($vibe, 'arts') || str_contains($vibe, 'drama') || str_contains($vibe, 'culture') => [
                Event::CATEGORY_THEATRE,
                Event::CATEGORY_CINEMA,
                Event::CATEGORY_EXHIBITION,
            ],
            str_contains($vibe, 'laugh') || str_contains($vibe, 'social') => [
                Event::CATEGORY_COMEDY,
                Event::CATEGORY_SALOON,
                Event::CATEGORY_SPORTS,
            ],
            str_contains($vibe, 'learn') || str_contains($vibe, 'network') => [
                Event::CATEGORY_CONFERENCE,
                Event::CATEGORY_WORKSHOP,
                Event::CATEGORY_BUILDING,
            ],
            default => [
                Event::CATEGORY_FESTIVAL,
                Event::CATEGORY_CONCERT,
                Event::CATEGORY_NIGHTLIFE,
            ],
        };

        $profileTitle = match ($preferredCategories[0]) {
            Event::CATEGORY_THEATRE => 'Culture & Stage Connoisseur',
            Event::CATEGORY_COMEDY => 'Social & Good-Vibes Seeker',
            Event::CATEGORY_CONFERENCE => 'Curious Mind & Builder',
            default => 'Live-Music & Festival Explorer',
        };

        $scored = [];
        foreach ($events as $event) {
            $score = 68;
            $cat = $event->getCategory();
            if ($cat === $preferredCategories[0]) {
                $score += 24;
            } elseif (in_array($cat, $preferredCategories, true)) {
                $score += 16;
            }

            if (str_contains($setting, 'outdoor') && $cat === Event::CATEGORY_FESTIVAL) {
                $score += 6;
            } elseif (str_contains($setting, 'indoor') && $event->isIndoorCategory()) {
                $score += 6;
            }

            $haystack = mb_strtolower($event->getTitle() . ' ' . $event->getVenue() . ' ' . $event->getLocation() . ' ' . $event->getDescription());
            if ($notes !== '') {
                foreach (preg_split('/[\s,]+/', $notes) ?: [] as $word) {
                    if (mb_strlen($word) >= 3 && str_contains($haystack, $word)) {
                        $score += 7;
                    }
                }
            }

            $score = max(62, min(98, $score));
            $scored[] = [
                'event' => $event,
                'match_score' => $score,
                'reason' => sprintf(
                    'Matches your %s vibe with a %s experience at %s.',
                    $answers['vibe'],
                    mb_strtolower($event->getCategoryLabel()),
                    $event->getVenue()
                ),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);

        return [
            'personality_title' => $profileTitle,
            'summary' => sprintf(
                'Based on your taste for %s in a %s setting, you will enjoy immersive live experiences that match your energy and style.',
                mb_strtolower($answers['vibe']),
                mb_strtolower($answers['setting'])
            ),
            'suggested_categories' => $preferredCategories,
            'recommendations' => array_slice($scored, 0, 3),
            'ai_powered' => false,
            'model' => 'smart-fallback',
        ];
    }
}
