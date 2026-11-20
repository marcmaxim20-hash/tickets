<?php

declare(strict_types=1);

namespace App\Service;

class AiEventDescriptionGenerator
{
    private const API_URL = 'https://integrate.api.nvidia.com/v1/chat/completions';

    /**
     * Fast fallback models on NVIDIA NIM in case the primary model times out or is overloaded.
     */
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
     *     title?: string,
     *     category?: string,
     *     venue?: string,
     *     location?: string,
     *     start_date?: string,
     *     end_date?: string,
     *     total_seats?: string|int,
     *     image_url?: string
     * } $context
     * @return array{description: string, model: string}
     */
    public function generateDescription(array $context): array
    {
        $apiKey = trim($this->nvidiaApiKey);
        if ($apiKey === '') {
            throw new \RuntimeException('NVIDIA_API_KEY is not configured in .env.local.');
        }

        $title = trim((string) ($context['title'] ?? ''));
        $category = trim((string) ($context['category'] ?? ''));
        $venue = trim((string) ($context['venue'] ?? ''));
        $location = trim((string) ($context['location'] ?? ''));
        $startDate = trim((string) ($context['start_date'] ?? ''));
        $endDate = trim((string) ($context['end_date'] ?? ''));
        $totalSeats = trim((string) ($context['total_seats'] ?? ''));
        $imageUrl = trim((string) ($context['image_url'] ?? ''));

        $details = [];
        if ($title !== '') {
            $details[] = 'Event Name: ' . $title;
        }
        if ($category !== '') {
            $details[] = 'Category: ' . $category;
        }
        if ($venue !== '') {
            $details[] = 'Venue: ' . $venue;
        }
        if ($location !== '') {
            $details[] = 'Location: ' . $location;
        }
        if ($startDate !== '') {
            $details[] = 'Start: ' . str_replace('T', ' ', $startDate);
        }
        if ($endDate !== '') {
            $details[] = 'End: ' . str_replace('T', ' ', $endDate);
        }
        if ($totalSeats !== '') {
            $details[] = 'Seat Capacity: ' . $totalSeats;
        }

        $prompt = "Write a compelling, clear, ready-to-publish event description in plain text (no markdown headings, no bullet symbols, no asterisks, no quotes around the answer). "
            . "Write 2 short, engaging paragraphs (about 70-120 words total) highlighting the atmosphere, what guests can expect, and inviting them to reserve their seat.\n\n"
            . "Event details:\n" . implode("\n", $details);

        $hasValidImageUrl = $imageUrl !== ''
            && str_starts_with($imageUrl, 'https://')
            && filter_var($imageUrl, FILTER_VALIDATE_URL) !== false;

        $primaryModel = trim($this->nvidiaAiModel) !== '' ? trim($this->nvidiaAiModel) : 'moonshotai/kimi-k3.1-pro';
        $modelsToTry = array_values(array_unique(array_merge([$primaryModel], self::FALLBACK_MODELS)));

        $lastError = 'AI service did not return a description.';

        foreach ($modelsToTry as $index => $model) {
            $timeoutSeconds = $index === 0 ? 12 : 20;

            if ($index === 0 && $hasValidImageUrl) {
                try {
                    $text = $this->callNvidiaApi($apiKey, $model, [
                        [
                            'type' => 'text',
                            'text' => $prompt . "\n\nAlso draw subtle inspiration from the attached event cover image.",
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => $imageUrl],
                        ],
                    ], $timeoutSeconds);

                    return ['description' => $text, 'model' => $model];
                } catch (\RuntimeException $e) {
                    $lastError = $e->getMessage();
                }
            }

            try {
                $text = $this->callNvidiaApi($apiKey, $model, $prompt, $timeoutSeconds);

                return ['description' => $text, 'model' => $model];
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * @param string|list<array<string, mixed>> $userContent
     */
    private function callNvidiaApi(string $apiKey, string $model, string|array $userContent, int $timeoutSeconds): string
    {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert event copywriter for a modern ticket booking website. Return only clean plain-text event descriptions with no markdown formatting.',
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'max_tokens' => 1200,
            'stream' => false,
        ];

        if (str_contains($model, 'kimi')) {
            $payload['reasoning_effort'] = 'low';
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize HTTP client.');
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
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);

            $rawBody = curl_exec($ch);
            if ($rawBody === false) {
                $err = curl_error($ch);
                throw new \RuntimeException('AI request failed: ' . ($err !== '' ? $err : 'network error'));
            }

            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }

        try {
            $decoded = json_decode((string) $rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException(sprintf('AI service returned an invalid response (HTTP %d).', $httpStatus));
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $apiMsg = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['detail'] ?? $decoded['message'] ?? '')
                : '';
            throw new \RuntimeException(
                $apiMsg !== ''
                    ? sprintf('AI service error (HTTP %d): %s', $httpStatus, $apiMsg)
                    : sprintf('AI service returned HTTP %d.', $httpStatus)
            );
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('AI service returned an empty description.');
        }

        $cleaned = trim($content);
        $cleaned = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned, " \t\n\r\0\x0B\"'");

        return $cleaned;
    }
}
