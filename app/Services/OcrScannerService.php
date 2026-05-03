<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\AiCreditLog;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/**
 * Sprint E1: OCR extraction for unstructured data (images / scanned PDFs).
 *
 * Primary path: tesseract CLI for raster images, smalot/pdfparser for text-based PDFs.
 * Both yield {text, confidence, source}.
 *
 * Visual fallback (opt-in): when the primary yield is below the configured
 * character threshold AND `ai.use_visual_ocr_fallback` is enabled, the service
 * routes to DeepSeek-OCR / VL2 via the OpenAI-compatible DeepInfra endpoint.
 * This handles scanned hardcopy PDFs (no embedded text layer) that pdfparser
 * cannot extract. The fallback degrades gracefully — missing imagick / missing
 * API key / API failure all return the primary result rather than throwing.
 */
class OcrScannerService
{
    // Vision OCR fallback resolves provider via active "document" mode
    // selection (ai_active_selections), so the user picks DeepSeek-OCR or
    // VL2 (or another vision-capable provider) via the standard provider
    // config UI — sama pattern dengan chat/agent/avatar/voice modes.

    /**
     * Extract text from a file. Always returns a {text, confidence, source} array.
     *
     * @param  string|null  $orgId  Tenant org_id used for credit logging + per-org API key resolution.
     * @param  string|null  $userId  Optional acting user for credit log attribution.
     */
    public function extractText(string $filePath, ?string $orgId = null, ?string $userId = null): array
    {
        if (! is_file($filePath)) {
            return ['text' => '', 'confidence' => 0, 'source' => 'missing'];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $primary = $this->extractViaPrimary($filePath, $ext);

        // Visual fallback: only triggers when (a) feature flag on and (b) primary
        // text yield is shorter than threshold (meaning pdfparser/tesseract
        // produced nothing usable — typical for scanned hardcopy).
        $useFallback = (bool) config('ai.use_visual_ocr_fallback', false);
        $threshold = (int) config('ai.visual_ocr_text_threshold', 100);
        $primaryChars = mb_strlen(trim($primary['text'] ?? ''));

        if ($useFallback && $primaryChars < $threshold && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'bmp', 'webp'], true)) {
            try {
                $visualResult = $this->extractViaDeepSeekVision($filePath, $ext, $orgId, $userId);
                if ($visualResult !== null && ! empty($visualResult['text'])) {
                    Log::info('OCR fallback ke DeepSeek Vision', [
                        'org_id' => $orgId,
                        'primary_chars' => $primaryChars,
                        'primary_source' => $primary['source'] ?? null,
                        'visual_chars' => mb_strlen($visualResult['text']),
                        'pages_processed' => $visualResult['pages_processed'] ?? 1,
                    ]);

                    return [
                        'text' => $visualResult['text'],
                        'confidence' => 0.8,
                        'source' => 'deepseek_vision_fallback',
                        'primary_yield' => $primaryChars,
                        'pages_processed' => $visualResult['pages_processed'] ?? 1,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('DeepSeek Vision fallback failed, returning primary result', [
                    'org_id' => $orgId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $primary;
    }

    /**
     * Primary extraction path — tesseract for images, pdfparser for PDFs,
     * raw read as last resort for text/csv.
     */
    private function extractViaPrimary(string $filePath, string $ext): array
    {
        // 1) try tesseract CLI for image formats
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'tiff', 'bmp'], true) && $this->tesseractAvailable()) {
            try {
                $out = [];
                $rc = 0;
                exec('tesseract '.escapeshellarg($filePath).' - 2>/dev/null', $out, $rc);
                if ($rc === 0 && count($out) > 0) {
                    return [
                        'text' => trim(implode("\n", $out)),
                        'confidence' => 0.85,
                        'source' => 'tesseract',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Tesseract OCR failed, falling back: '.$e->getMessage());
            }
        }

        // 2) PDF: smalot/pdfparser (already used elsewhere) — works on text-based PDFs
        if ($ext === 'pdf') {
            try {
                $parser = new Parser;
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                if (mb_strlen(trim($text)) > 50) {
                    return ['text' => trim($text), 'confidence' => 0.9, 'source' => 'pdfparser'];
                }

                // Return what we got (may be empty / very short → triggers visual fallback).
                return ['text' => trim($text), 'confidence' => 0.3, 'source' => 'pdfparser_empty'];
            } catch (\Throwable $e) {
                Log::warning('PDF parse failed: '.$e->getMessage());
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
        if ($cached !== null) {
            return $cached;
        }
        $rc = 0;
        @exec('tesseract --version 2>&1', $_, $rc);

        return $cached = ($rc === 0);
    }

    // ============================================================
    // DeepSeek Vision fallback (DeepInfra OpenAI-compatible)
    // ============================================================

    /**
     * Convert input to a list of base64-encoded image data URIs and ask the
     * deepseek-vision model to OCR them. Returns null on any non-fatal
     * failure (caller treats null as "stick with primary result").
     *
     * @return array{text: string, pages_processed: int}|null
     */
    private function extractViaDeepSeekVision(string $filePath, string $ext, ?string $orgId, ?string $userId = null): ?array
    {
        // Resolve provider via active "document" mode selection — admin pilih
        // VL2 atau DeepSeek-OCR (atau provider lain capable) di /platform-config
        // > AI Providers > Document mode. Mode-based, bukan size-based —
        // konsisten dengan pattern existing chat/agent/avatar/voice modes.
        $config = $this->resolveVisionProviderConfig($orgId);
        if ($config === null) {
            Log::info('DeepSeek Vision skipped: no provider config / api_key');

            return null;
        }

        // Build list of image data URIs to send. PDF -> rasterize first N pages.
        $maxPages = max(1, (int) config('ai.visual_ocr_max_pages', 10));
        $images = $this->fileToImageDataUris($filePath, $ext, $maxPages);
        if (empty($images)) {
            // Either imagick is missing (PDF case) or the image read failed.
            return null;
        }

        $combinedText = '';
        $totalPromptTokens = 0;
        $totalResponseTokens = 0;
        $pagesProcessed = 0;

        foreach ($images as $idx => $dataUri) {
            try {
                [$pageText, $usage] = $this->callDeepSeekVision($config, $dataUri);
            } catch (\Throwable $e) {
                Log::warning('DeepSeek Vision page call failed', [
                    'page' => $idx + 1,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($pageText !== null && $pageText !== '') {
                if ($combinedText !== '') {
                    $combinedText .= "\n\n--- HALAMAN ".($idx + 1)." ---\n\n";
                }
                $combinedText .= $pageText;
                $pagesProcessed++;
            }
            $totalPromptTokens += (int) ($usage['prompt_tokens'] ?? 0);
            $totalResponseTokens += (int) ($usage['completion_tokens'] ?? 0);
        }

        if ($combinedText === '') {
            return null;
        }

        // Cost-tracking entry: log to ai_credit_logs (no credit deduction —
        // visual OCR is metered separately). Skip silently if no orgId or
        // table missing.
        $this->logUsage($orgId, $userId, $config['model']->model_id ?? 'deepseek-vision', $pagesProcessed, $totalPromptTokens, $totalResponseTokens);

        return [
            'text' => trim($combinedText),
            'pages_processed' => $pagesProcessed,
        ];
    }

    /**
     * Resolve the deepseek-vision provider record + tenant API key. Tenant
     * key wins; falls back to global (org_id NULL) record so a single super-
     * admin entry can serve all tenants in SaaS mode.
     *
     * If $preferredModelId is supplied and an active model with that id exists
     * under the provider, it is selected. Otherwise we fall back to the first
     * active model by sort_order — preserves backwards compatibility when the
     * size-routed model row is somehow missing.
     *
     * @return array{provider: AiProvider, model: AiModel, api_key: string}|null
     */
    private function resolveVisionProviderConfig(?string $orgId, ?string $preferredModelId = null): ?array
    {
        // Step 1: try active "document" mode selection (user-configurable)
        // — supports any vision-capable provider (deepseek-vision/VL2, atau
        // future provider lain user pilih). Same pattern as chat/agent/avatar.
        if ($orgId) {
            $active = AiProviderController::getActiveConfig($orgId, 'document');
            if ($active && ! empty($active['provider']) && ! empty($active['model']) && ! empty($active['api_key'])) {
                return [
                    'provider' => $active['provider'],
                    'model' => $active['model'],
                    'api_key' => $active['api_key'],
                ];
            }
        }

        // Step 2: fallback to default deepseek-vision provider (kalau admin
        // belum set active document mode tapi feature flag aktif).
        $provider = AiProvider::where('slug', 'deepseek-vision')
            ->where('is_active', true)
            ->first();
        if (! $provider) {
            return null;
        }

        $model = null;
        if ($preferredModelId) {
            $model = AiModel::where('provider_id', $provider->id)
                ->where('model_id', $preferredModelId)
                ->where('is_active', true)
                ->first();
        }
        if (! $model) {
            $model = AiModel::where('provider_id', $provider->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
        }
        if (! $model) {
            return null;
        }

        // Per-org key first
        $configRow = null;
        if ($orgId) {
            $configRow = DB::table('ai_provider_configs')
                ->where('provider_id', $provider->id)
                ->where('org_id', $orgId)
                ->first();
        }
        // Fallback to global key
        if (! $configRow) {
            $configRow = DB::table('ai_provider_configs')
                ->where('provider_id', $provider->id)
                ->whereNull('org_id')
                ->first();
        }
        if (! $configRow || empty($configRow->api_key_encrypted)) {
            return null;
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => $configRow->api_key_encrypted,
        ];
    }

    /**
     * Convert file to one or more base64 image data URIs.
     *
     * For images: single-element list (read as-is).
     * For PDFs: rasterize via Imagick if available; otherwise return [] so
     * the caller falls back to primary result.
     *
     * @return array<int,string>
     */
    private function fileToImageDataUris(string $filePath, string $ext, int $maxPages): array
    {
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'tiff', 'bmp', 'webp'], true)) {
            $bin = @file_get_contents($filePath);
            if ($bin === false) {
                return [];
            }
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'tiff' => 'image/tiff',
                'bmp' => 'image/bmp',
                default => 'application/octet-stream',
            };

            return ['data:'.$mime.';base64,'.base64_encode($bin)];
        }

        if ($ext === 'pdf') {
            return $this->rasterizePdfToDataUris($filePath, $maxPages);
        }

        return [];
    }

    /**
     * Rasterize PDF pages to JPEGs using Imagick. Returns an empty list (and
     * logs a warning) if the imagick PHP extension is missing — the caller
     * then keeps the primary OCR result rather than failing the whole call.
     *
     * @return array<int,string>
     */
    private function rasterizePdfToDataUris(string $filePath, int $maxPages): array
    {
        if (! extension_loaded('imagick')) {
            Log::warning('DeepSeek Vision skipped: imagick extension not loaded — cannot rasterize PDF', [
                'file' => basename($filePath),
            ]);

            return [];
        }

        $dataUris = [];
        try {
            $imagick = new \Imagick;
            $imagick->setResolution(150, 150); // 150 DPI — balance between OCR accuracy and payload size

            // Read pages 0..maxPages-1 only. Imagick's [N] suffix selects a single page.
            for ($page = 0; $page < $maxPages; $page++) {
                $pageImagick = new \Imagick;
                $pageImagick->setResolution(150, 150);
                try {
                    $pageImagick->readImage($filePath.'['.$page.']');
                } catch (\ImagickException $e) {
                    // Out of pages — break the loop cleanly.
                    $pageImagick->clear();
                    break;
                }
                $pageImagick->setImageFormat('jpeg');
                $pageImagick->setImageCompressionQuality(85);
                $blob = $pageImagick->getImageBlob();
                $pageImagick->clear();
                $pageImagick->destroy();

                $dataUris[] = 'data:image/jpeg;base64,'.base64_encode($blob);
            }
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $e) {
            Log::warning('PDF rasterization failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $dataUris;
    }

    /**
     * Send a single image to the deepseek-vision OCR endpoint.
     * Uses OpenAI-compatible `chat/completions` with `image_url` content part.
     *
     * @return array{0: string|null, 1: array<string,mixed>} [extracted text, usage]
     */
    private function callDeepSeekVision(array $config, string $imageDataUri): array
    {
        /** @var AiProvider $provider */
        $provider = $config['provider'];
        /** @var AiModel $model */
        $model = $config['model'];

        $authH = $provider->auth_header ?: 'Authorization';
        $authP = ($provider->auth_header && ! $provider->auth_prefix) ? '' : ($provider->auth_prefix ?: 'Bearer');
        $headers = ['Content-Type' => 'application/json'];
        if ($authP) {
            $headers[$authH] = $authP.' '.$config['api_key'];
        } else {
            $headers[$authH] = $config['api_key'];
        }

        $baseUrl = rtrim($provider->api_base_url, '/');

        $prompt = 'Extract ALL visible text from this document image verbatim. '
            .'Preserve layout (paragraphs, tables, headers) using plain text and line breaks. '
            .'Return ONLY the extracted text — no commentary, no markdown fences.';

        $payload = [
            'model' => $model->model_id,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageDataUri]],
                ],
            ]],
            'max_tokens' => (int) ($model->max_output_tokens ?: 4096),
            'temperature' => 0.0,
        ];

        $timeout = (int) env('AI_TIMEOUT', 180);

        $response = Http::timeout($timeout)
            ->withoutVerifying()
            ->withHeaders($headers)
            ->post($baseUrl.'/chat/completions', $payload);

        if ($response->failed()) {
            Log::warning('DeepSeek Vision API non-2xx', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);

            return [null, []];
        }

        $data = $response->json() ?? [];
        $text = $data['choices'][0]['message']['content'] ?? null;
        $usage = $data['usage'] ?? [];

        // Strip optional code fences if the model wraps output despite our prompt.
        if (is_string($text)) {
            $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', trim($text));
        }

        return [$text, is_array($usage) ? $usage : []];
    }

    /**
     * Cost-tracking entry. Writes a row into ai_credit_logs (action_type =
     * `ocr_visual_fallback`, credits_used = 0) when both org_id AND user_id
     * are known — both are FK-required by the schema. Without a user_id the
     * call falls back to a Log::info entry so the cost is still observable
     * in application logs.
     */
    private function logUsage(?string $orgId, ?string $userId, string $modelName, int $pages, int $promptTokens, int $responseTokens): void
    {
        $payload = [
            'org_id' => $orgId,
            'model' => $modelName,
            'pages' => $pages,
            'prompt_tokens' => $promptTokens,
            'response_tokens' => $responseTokens,
        ];

        if (! $orgId || ! $userId) {
            // Without a user_id the FK in ai_credit_logs would fail — settle
            // for a structured application-log line.
            Log::info('ocr.visual_fallback.usage', $payload);

            return;
        }

        try {
            AiCreditLog::create([
                'org_id' => $orgId,
                'user_id' => $userId,
                'action_type' => 'ocr_visual_fallback',
                'credits_used' => 0,
                'status' => 'success',
                'module' => 'ocr',
                'metadata' => [
                    'model' => $modelName,
                    'pages' => $pages,
                    'prompt_tokens' => $promptTokens,
                    'response_tokens' => $responseTokens,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OCR credit log write failed', ['error' => $e->getMessage(), ...$payload]);
        }
    }
}
