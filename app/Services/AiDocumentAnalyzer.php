<?php

namespace App\Services;

use App\Services\AiDocumentAnalyzer\AnalysisResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AiDocumentAnalyzer
 *
 * Menganalisis dokumen yang di-upload terhadap pertanyaan kepatuhan UU PDP.
 * Dipakai oleh modul TPRM, Maturity Assessment, dan GAP Assessment untuk
 * memverifikasi apakah dokumen pendukung benar-benar menjawab pertanyaan
 * audit (comply / partial / non_comply / unsure).
 *
 * Pipeline:
 *   1. Ekstrak teks dari file (PDF/DOCX/XLSX). Gambar belum didukung.
 *   2. Truncate ke 8000 karakter untuk menghindari batas TPM provider.
 *   3. Kirim ke AiService dengan prompt JSON-only, temperature 0.2.
 *   4. Parse defensif → AnalysisResult.
 *   5. Cache 7 hari berdasarkan hash(path normalisasi + question + regulationRef).
 *   6. Charge 1 credit per call sukses (skip jika cache hit / OCR unsupported).
 */
class AiDocumentAnalyzer
{
    /**
     * Maksimum karakter dokumen yang dikirim ke AI (guard terhadap TPM limit).
     */
    private const MAX_DOCUMENT_CHARS = 8000;

    /**
     * TTL cache hasil analisis (7 hari).
     */
    private const CACHE_TTL_SECONDS = 7 * 24 * 60 * 60;

    /**
     * Ekstensi file gambar yang belum didukung (OCR).
     *
     * @var array<int, string>
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];

    public function __construct(
        private AiService $ai,
        private CreditService $credit, // sengaja inject (meski API-nya static) — sesuai spec & testability
    ) {}

    /**
     * Analyze a document against a compliance question.
     *
     * @param  string  $documentPath  Absolute local path to the uploaded file.
     * @param  string  $question  Compliance question text.
     * @param  string  $regulationRef  Optional regulation reference (e.g. 'UU PDP Pasal 35').
     * @param  string|null  $orgId  Tenant org_id for credit charging & audit. Null = skip credit.
     */
    public function analyze(
        string $documentPath,
        string $question,
        string $regulationRef = '',
        ?string $orgId = null,
    ): AnalysisResult {
        $startedAt = microtime(true);
        $normalizedPath = $this->normalizePath($documentPath);
        $extension = strtolower(pathinfo($documentPath, PATHINFO_EXTENSION));
        $cacheKey = 'ai_doc_analyze_'.hash('sha256', $normalizedPath.'|'.$question.'|'.$regulationRef);

        // Cache hit → return langsung, jangan charge credit.
        if ($cached = Cache::get($cacheKey)) {
            Log::info('[AiDocAnalyze]', [
                'event' => 'cache_hit',
                'path' => $normalizedPath,
                'org_id' => $orgId,
                'question_preview' => mb_substr($question, 0, 80),
            ]);

            return AnalysisResult::fromArray($cached);
        }

        // File guard.
        if (! is_file($documentPath) || ! is_readable($documentPath)) {
            Log::warning('[AiDocAnalyze]', [
                'event' => 'file_unreadable',
                'path' => $normalizedPath,
                'org_id' => $orgId,
            ]);

            return AnalysisResult::error("File tidak dapat dibaca: {$normalizedPath}");
        }

        // OCR belum didukung → skip credit.
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            Log::info('[AiDocAnalyze]', [
                'event' => 'unsupported_image',
                'extension' => $extension,
                'path' => $normalizedPath,
            ]);

            return AnalysisResult::error(
                'OCR belum didukung untuk format gambar. Silakan upload dokumen teks (PDF/DOCX/XLSX).',
                'unsure'
            );
        }

        // Ekstrak teks.
        try {
            $documentText = $this->extractText($documentPath, $extension);
        } catch (Throwable $e) {
            Log::error('[AiDocAnalyze]', [
                'event' => 'extract_failed',
                'path' => $normalizedPath,
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            return AnalysisResult::error(
                "Gagal mengekstrak teks dari dokumen ({$extension}): ".$e->getMessage()
            );
        }

        $documentText = trim($documentText);
        if ($documentText === '') {
            return AnalysisResult::error(
                'Dokumen tidak mengandung teks yang dapat dianalisis. Pastikan file bukan PDF hasil scan.'
            );
        }

        // Truncate.
        if (mb_strlen($documentText) > self::MAX_DOCUMENT_CHARS) {
            $documentText = mb_substr($documentText, 0, self::MAX_DOCUMENT_CHARS)
                ."\n\n[...dokumen dipotong otomatis pada ".self::MAX_DOCUMENT_CHARS." karakter...]";
        }

        // Provider availability.
        if (! $this->ai->isAvailable()) {
            Log::warning('[AiDocAnalyze]', [
                'event' => 'ai_unavailable',
                'org_id' => $orgId,
            ]);

            return AnalysisResult::error(
                'Layanan AI belum dikonfigurasi. Hubungi administrator untuk mengaktifkan provider AI.'
            );
        }

        // Panggil AI.
        [$systemPrompt, $userPrompt] = $this->buildPrompts($question, $regulationRef, $documentText);
        $raw = $this->ai->ask($systemPrompt, $userPrompt, 1200);

        if (! is_array($raw) || empty($raw) || isset($raw['raw'])) {
            Log::warning('[AiDocAnalyze]', [
                'event' => 'parse_failed',
                'org_id' => $orgId,
                'raw_preview' => is_array($raw) ? mb_substr((string) ($raw['raw'] ?? ''), 0, 200) : null,
            ]);

            return AnalysisResult::error('Tidak dapat mem-parse respons AI');
        }

        $result = $this->buildResult($raw);

        // Cache + charge credit hanya jika sukses (error null).
        if ($result->error === null) {
            Cache::put($cacheKey, $result->toArray(), self::CACHE_TTL_SECONDS);
            $this->chargeCredit($orgId, $question, $regulationRef, $normalizedPath);
        }

        Log::info('[AiDocAnalyze]', [
            'event' => 'analyzed',
            'org_id' => $orgId,
            'path' => $normalizedPath,
            'extension' => $extension,
            'status' => $result->status,
            'confidence' => $result->confidence,
            'tokens_used' => $result->tokens_used,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    // ---------------------------------------------------------------------
    // Text extraction
    // ---------------------------------------------------------------------

    private function extractText(string $path, string $extension): string
    {
        return match ($extension) {
            'pdf' => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            'xlsx', 'xlsm' => $this->extractXlsx($path),
            'txt', 'md', 'csv' => (string) file_get_contents($path),
            default => throw new \RuntimeException(
                "Format file '.{$extension}' belum didukung. Gunakan PDF, DOCX, atau XLSX."
            ),
        };
    }

    private function extractPdf(string $path): string
    {
        $parser = new \Smalot\PdfParser\Parser;
        $pdf = $parser->parseFile($path);

        $buffer = '';
        $pageNumber = 1;
        foreach ($pdf->getPages() as $page) {
            $text = trim($page->getText());
            if ($text !== '') {
                $buffer .= "[Halaman {$pageNumber}]\n".$text."\n\n";
            }
            $pageNumber++;
        }

        if (trim($buffer) === '') {
            // Fallback: keseluruhan teks PDF (kadang getPages() empty untuk PDF tertentu).
            $buffer = (string) $pdf->getText();
        }

        return $buffer;
    }

    private function extractDocx(string $path): string
    {
        // Strategi utama: parse word/document.xml lalu strip_tags — cepat & tidak
        // butuh seluruh element tree PhpWord.
        $xml = $this->readDocxBodyXml($path);
        if ($xml !== null) {
            // Tambah newline pada paragraph & break agar tidak nempel.
            $xml = preg_replace('/<\/w:p>/', "\n", $xml);
            $xml = preg_replace('/<w:br[^>]*\/>/', "\n", (string) $xml);
            $text = trim(html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($text !== '') {
                return $text;
            }
        }

        // Fallback: PhpWord element-walk (lebih lambat tapi robust).
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
        $buffer = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $buffer .= $this->phpWordElementText($element)."\n";
            }
        }

        return trim($buffer);
    }

    private function readDocxBodyXml(string $path): ?string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml === false ? null : $xml;
    }

    private function phpWordElementText($element): string
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            return (string) $element->getText();
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun
            || $element instanceof \PhpOffice\PhpWord\Element\ListItemRun) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= $this->phpWordElementText($child);
            }

            return $out;
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $out = '';
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellBuf = '';
                    foreach ($cell->getElements() as $child) {
                        $cellBuf .= $this->phpWordElementText($child).' ';
                    }
                    $cells[] = trim($cellBuf);
                }
                $out .= implode(' | ', $cells)."\n";
            }

            return $out;
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Title) {
            $titleText = $element->getText();
            if (is_string($titleText)) {
                return $titleText;
            }
            if ($titleText instanceof \PhpOffice\PhpWord\Element\TextRun) {
                return $this->phpWordElementText($titleText);
            }
        }

        return '';
    }

    private function extractXlsx(string $path): string
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $buffer = '';
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $buffer .= "=== Sheet: {$sheet->getTitle()} ===\n";
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $iter = $row->getCellIterator();
                $iter->setIterateOnlyExistingCells(false);
                foreach ($iter as $cell) {
                    $cells[] = trim((string) $cell->getValue());
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                $buffer .= implode(' | ', $cells)."\n";
            }
            $buffer .= "\n";
        }

        return trim($buffer);
    }

    // ---------------------------------------------------------------------
    // Prompt building & response parsing
    // ---------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string}
     */
    private function buildPrompts(string $question, string $regulationRef, string $documentText): array
    {
        $systemPrompt = 'Anda adalah auditor kepatuhan UU PDP 27/2022. '
            .'Analisis dokumen yang diberikan terhadap pertanyaan kepatuhan. '
            ."Output WAJIB JSON valid dengan field exact:\n"
            ."- status: salah satu dari \"comply\", \"partial\", \"non_comply\", \"unsure\"\n"
            ."- analysis: penjelasan singkat (maksimal 200 kata) dalam Bahasa Indonesia formal (gunakan 'Anda', hindari kata kasual)\n"
            ."- cited_passages: array kutipan kalimat dari dokumen yang mendukung kesimpulan. "
            ."Format: [{\"page\": <nomor halaman atau null>, \"text\": \"<kutipan persis dari dokumen>\"}]\n"
            ."- confidence: angka 0-1 menyatakan tingkat kepercayaan Anda\n\n"
            .'Aturan: jika dokumen tidak relevan / tidak cukup informasi, gunakan status "unsure". '
            .'Jika dokumen secara eksplisit memenuhi pertanyaan, gunakan "comply". '
            .'Jika hanya sebagian, gunakan "partial". Jika bertentangan / tidak memenuhi, gunakan "non_comply". '
            .'Output JSON saja, TANPA markdown fence atau penjelasan tambahan.';

        $userPrompt = "PERTANYAAN: {$question}\n"
            .'RUJUKAN: '.($regulationRef !== '' ? $regulationRef : '(tidak disebutkan)')."\n\n"
            ."DOKUMEN:\n{$documentText}\n\n"
            .'Output JSON saja.';

        return [$systemPrompt, $userPrompt];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildResult(array $raw): AnalysisResult
    {
        $status = AnalysisResult::normalizeStatus($raw['status'] ?? null);
        $analysis = trim((string) ($raw['analysis'] ?? ''));
        $confidence = (float) ($raw['confidence'] ?? 0.0);
        if ($confidence < 0) {
            $confidence = 0.0;
        }
        if ($confidence > 1) {
            // Beberapa model output 0-100; normalisasi.
            $confidence = $confidence > 1.0 && $confidence <= 100.0 ? $confidence / 100.0 : 1.0;
        }

        $citedPassages = [];
        $rawCitations = $raw['cited_passages'] ?? [];
        if (is_array($rawCitations)) {
            foreach ($rawCitations as $citation) {
                if (! is_array($citation)) {
                    continue;
                }
                $text = trim((string) ($citation['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $page = $citation['page'] ?? null;
                $citedPassages[] = [
                    'page' => is_numeric($page) ? (int) $page : null,
                    'text' => $text,
                ];
            }
        }

        // Token usage: AiService::ask() saat ini tidak return usage — fallback estimasi 0.
        $tokensUsed = (int) ($raw['tokens_used'] ?? $raw['usage']['total_tokens'] ?? 0);

        // Sanity: status comply tanpa analysis → demote ke unsure.
        if ($analysis === '' && $status !== 'unsure') {
            return AnalysisResult::error('Respons AI kosong / tidak lengkap');
        }

        return new AnalysisResult(
            status: $status,
            analysis: $analysis,
            cited_passages: $citedPassages,
            confidence: $confidence,
            tokens_used: $tokensUsed,
            error: null,
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function normalizePath(string $path): string
    {
        $resolved = @realpath($path);

        return $resolved !== false ? str_replace('\\', '/', $resolved) : str_replace('\\', '/', $path);
    }

    private function chargeCredit(?string $orgId, string $question, string $regulationRef, string $path): void
    {
        if ($orgId === null || $orgId === '') {
            return;
        }

        try {
            // CreditService API saat ini static; gunakan langsung walaupun instance di-inject.
            $userId = (string) (auth()->id() ?? 'system');
            CreditService::deduct(
                orgId: $orgId,
                userId: $userId,
                actionType: 'ai_doc_analyze',
                module: 'doc_analyzer',
                recordId: null,
                meta: [
                    'question' => mb_substr($question, 0, 500),
                    'regulation_ref' => $regulationRef,
                    'document' => basename($path),
                ],
            );
        } catch (Throwable $e) {
            // Jangan gagalkan analisis hanya karena credit ledger error — cukup log.
            Log::warning('[AiDocAnalyze]', [
                'event' => 'credit_deduct_failed',
                'org_id' => $orgId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
