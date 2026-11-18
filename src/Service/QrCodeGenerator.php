<?php

declare(strict_types=1);

namespace App\Service;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeGenerator
{
    public function generateSvg(string $payload, int $size = 240): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);
        $svg = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());

        // Strip XML declaration so SVG embeds cleanly inline in HTML5
        return preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $svg) ?? $svg;
    }

    public function generatePng(string $payload, int $scale = 7, int $margin = 3): string
    {
        $qrCode = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8');
        $matrix = $qrCode->getMatrix();

        $matrixWidth = $matrix->getWidth();
        $matrixHeight = $matrix->getHeight();

        $imgWidth = ($matrixWidth + ($margin * 2)) * $scale;
        $imgHeight = ($matrixHeight + ($margin * 2)) * $scale;

        $whitePixelRun = str_repeat("\xFF", $scale);
        $blackPixelRun = str_repeat("\x00", $scale);
        $marginPixels = str_repeat("\xFF", $margin * $scale);
        $blankScanline = "\x00" . str_repeat("\xFF", $imgWidth);

        $rawScanlines = str_repeat($blankScanline, $margin * $scale);

        for ($y = 0; $y < $matrixHeight; ++$y) {
            $rowBytes = "\x00" . $marginPixels;
            for ($x = 0; $x < $matrixWidth; ++$x) {
                $rowBytes .= ($matrix->get($x, $y) === 1) ? $blackPixelRun : $whitePixelRun;
            }
            $rowBytes .= $marginPixels;
            $rawScanlines .= str_repeat($rowBytes, $scale);
        }

        $rawScanlines .= str_repeat($blankScanline, $margin * $scale);

        $compressedData = gzcompress($rawScanlines, 9);
        if ($compressedData === false) {
            throw new \RuntimeException('Failed to compress QR code PNG data.');
        }

        $ihdr = pack('NNCCCCC', $imgWidth, $imgHeight, 8, 0, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . $this->buildPngChunk('IHDR', $ihdr)
            . $this->buildPngChunk('IDAT', $compressedData)
            . $this->buildPngChunk('IEND', '');
    }

    private function buildPngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
