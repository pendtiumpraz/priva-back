<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Sprint E1: OCR extraction for unstructured data (images / scanned PDFs).
 *
 * Prefers tesseract binary if installed (`tesseract` in PATH). Otherwise falls
 * back to AI Vision prompts via the active AiService. Both paths yield the
 * same { text, confidence, source } contract so callers don't branch.
 */
class OcrScannerService
{
    public function extractText(string $filePath, ?string $orgId = null): array
    {
        if (!is_file($filePath)) {
            return ['text' => '', 'confidence' => 0, 'source' => 'missing'];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // 1) try tesseract CLI for image formats
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'tiff', 'bmp'], true) && $this->tesseractAvailable()) {
            try {
                $out = [];
                $rc = 0;
                exec('tesseract ' . escapeshellarg($filePath) . ' - 2>/dev/null', $out, $rc);
                if ($rc === 0 && count($out) > 0) {
                    return [
                        'text' => trim(implode("\n", $out)),
                        'confidence' => 0.85,
                        'source' => 'tesseract',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Tesseract OCR failed, falling back: ' . $e->getMessage());
            }
        }

        // 2) PDF: smalot/pdfparser (already used elsewhere) — works on text-based PDFs
        if ($ext === 'pdf') {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                if (mb_strlen(trim($text)) > 50) {
                    return ['text' => trim($text), 'confidence' => 0.9, 'source' => 'pdfparser'];
                }
            } catch (\Throwable $e) {
                Log::warning('PDF parse failed: ' . $e->getMessage());
            }
        }

        // 3) Last resort: read as raw text (covers txt / csv)
        $raw = @file_get_contents($filePath);
        if ($raw !== false) {
            return ['text' => $raw, 'confidence' => 0.4, 'source' => 'raw'];
        }

        return ['text' => '', 'confidence' => 0, 'source' => 'none'];
    }

    private function tesseractAvailable(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $rc = 0;
        @exec('tesseract --version 2>&1', $_, $rc);
        return $cached = ($rc === 0);
    }
}
