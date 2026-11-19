<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class EventImageUploader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function saveUploadedEventImage(UploadedFile $file): string
    {
        $uploadDir = $this->projectDir . '/public/uploads/events';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg'));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg'], true)) {
            $ext = 'jpg';
        }

        $filename = sprintf('event_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(6)), $ext);
        $file->move($uploadDir, $filename);
        @chmod($uploadDir . '/' . $filename, 0664);

        return 'uploads/events/' . $filename;
    }

    public function isValidExternalImageUrl(string $url): bool
    {
        return mb_strlen($url) <= 255
            && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
