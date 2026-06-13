<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\GeneratedDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;

/**
 * Document Maker — generate Policy / Contract drafts from wizard input,
 * persist the canonical sections JSON, and render to DOCX / PDF.
 *
 * Output schema (ai_output):
 *   {
 *     "title": "...",
 *     "metadata": { "version": "1.0", "language": "id", ... },
 *     "sections": [
 *       { "type": "heading_1|heading_2|heading_3", "text": "..." },
 *       { "type": "paragraph", "text": "..." },
 *       { "type": "list", "items": ["..."] },
 *       { "type": "table", "headers": ["A","B"], "rows": [["1","2"]] },
 *       { "type": "signature_block", "parties": [{"label":"...","name":"...","title":"..."}] }
 *     ]
 *   }
 */
class DocumentMakerService
{
    public function __construct(private AiService $ai) {}

    /**
     * Generate a document from wizard inputs. Persists a GeneratedDocument
     * row and returns it. Throws RuntimeException on validation/AI failure.
     */
    public function generate(string $orgId, string $userId, string $kind, string $documentType, string $title, array $wizardInputs): GeneratedDocument
    {
        if (! in_array($kind, [GeneratedDocument::KIND_POLICY, GeneratedDocument::KIND_CONTRACT], true)) {
            throw new \InvalidArgumentException("Invalid kind: {$kind}");
        }

        if (! $this->ai->isAvailable()) {
            throw new \RuntimeException('AI provider is not configured / unavailable. Activate a provider in Settings → AI.');
        }

        $language = strtolower($wizardInputs['language'] ?? 'id') === 'en' ? 'en' : 'id';
        $this->ai->setLocale($language);

        [$system, $user] = $this->buildPrompt($kind, $documentType, $title, $wizardInputs, $language);

        $aiOutput = $this->ai->ask($system, $user, 6000);
        if (! is_array($aiOutput) || empty($aiOutput['sections']) || ! is_array($aiOutput['sections'])) {
            Log::error('DocumentMaker: AI output missing sections', ['kind' => $kind, 'type' => $documentType, 'output' => $aiOutput]);
            throw new \RuntimeException('AI returned invalid document structure (sections missing).');
        }

        // Defensive: ensure title is set.
        if (empty($aiOutput['title']) || ! is_string($aiOutput['title'])) {
            $aiOutput['title'] = $title;
        }
        if (! isset($aiOutput['metadata']) || ! is_array($aiOutput['metadata'])) {
            $aiOutput['metadata'] = [];
        }
        $aiOutput['metadata']['language'] = $language;
        $aiOutput['metadata']['kind'] = $kind;
        $aiOutput['metadata']['document_type'] = $documentType;
        $aiOutput['metadata']['generated_at'] = now()->toIso8601String();

        // Self-check: jalankan mini compliance review supaya UI bisa flag
        // "Need PDP enrichment" sebelum user re-upload ke contract review.
        // Failure di sini gak boleh crash generate flow.
        try {
            $aiOutput['metadata']['compliance'] = $this->selfCheck($kind, $documentType, $aiOutput['sections'], $wizardInputs);
        } catch (\Throwable $e) {
            Log::warning('DocumentMaker: self-check failed: '.$e->getMessage());
            $aiOutput['metadata']['compliance'] = ['status' => 'skipped', 'reason' => 'self-check error'];
        }

        $providerInfo = $this->resolveProviderInfo();

        $doc = GeneratedDocument::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'kind' => $kind,
            'document_type' => substr($documentType, 0, 64),
            'title' => substr($title, 0, 255),
            'wizard_inputs' => $wizardInputs,
            'ai_output' => $aiOutput,
            'ai_provider' => $providerInfo['provider'],
            'ai_model' => $providerInfo['model'],
            'credits_used' => 0,
        ]);

        return $doc;
    }

    /**
     * Render the stored sections JSON into a DOCX file. Returns absolute path
     * to the temp file. Caller is responsible for cleanup after streaming.
     */
    public function renderDocx(GeneratedDocument $doc): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $sectionStyle = ['marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134];
        $section = $phpWord->addSection($sectionStyle);

        // Title
        $titleStyle = ['name' => 'Calibri', 'size' => 18, 'bold' => true, 'color' => '1F2937'];
        $section->addText((string) ($doc->ai_output['title'] ?? $doc->title), $titleStyle, ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);

        // Metadata line (small)
        $meta = $doc->ai_output['metadata'] ?? [];
        $metaLine = collect([
            isset($meta['version']) ? 'Versi '.$meta['version'] : null,
            isset($meta['language']) ? 'Bahasa: '.strtoupper((string) $meta['language']) : null,
            'Dibuat: '.now()->format('d M Y'),
        ])->filter()->implode('  ·  ');
        if ($metaLine !== '') {
            $section->addText($metaLine, ['size' => 9, 'color' => '6B7280', 'italic' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 360]);
        }

        $sections = $doc->ai_output['sections'] ?? [];
        foreach ($sections as $node) {
            if (! is_array($node) || empty($node['type'])) {
                continue;
            }
            $this->renderDocxNode($section, $node);
        }

        $out = tempnam(sys_get_temp_dir(), 'docmaker_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($out);

        return $out;
    }

    /**
     * Render the stored sections JSON into a PDF file via dompdf. Returns
     * absolute path to the temp file. Caller is responsible for cleanup.
     */
    public function renderPdf(GeneratedDocument $doc): string
    {
        $pdf = Pdf::loadView('reports.document_maker.document', [
            'doc' => $doc,
            'title' => $doc->ai_output['title'] ?? $doc->title,
            'metadata' => $doc->ai_output['metadata'] ?? [],
            'sections' => $doc->ai_output['sections'] ?? [],
        ])->setPaper('a4', 'portrait')
            ->setOption(['isHtml5ParserEnabled' => true, 'defaultFont' => 'sans-serif']);

        $bytes = $pdf->output();
        $out = tempnam(sys_get_temp_dir(), 'docmaker_').'.pdf';
        file_put_contents($out, $bytes);

        return $out;
    }

    // =========================================================================
    // Fix Compliance Gaps — re-prompt AI to add missing clauses
    // =========================================================================

    /**
     * Re-generate sections with missing UU PDP clauses added/improved. Reuses
     * the existing wizard inputs + sections; AI returns the FULL updated
     * sections array (not a diff). Re-runs self-check after.
     */
    public function fixCompliance(GeneratedDocument $doc): GeneratedDocument
    {
        if (! $this->ai->isAvailable()) {
            throw new \RuntimeException('AI provider is not configured / unavailable.');
        }

        $aiOutput = is_array($doc->ai_output) ? $doc->ai_output : [];
        $sections = $aiOutput['sections'] ?? [];
        $compliance = $aiOutput['metadata']['compliance'] ?? [];
        $missing = $compliance['missing_clauses'] ?? [];
        if (empty($missing)) {
            // Nothing to fix — refresh self-check & return as-is.
            try {
                $aiOutput['metadata']['compliance'] = $this->selfCheck(
                    $doc->kind,
                    $doc->document_type,
                    is_array($sections) ? $sections : [],
                    is_array($doc->wizard_inputs) ? $doc->wizard_inputs : []
                );
                $doc->ai_output = $aiOutput;
                $doc->save();
            } catch (\Throwable $e) {
                Log::warning('DocumentMaker.fixCompliance: refresh self-check failed: '.$e->getMessage());
            }

            return $doc;
        }

        $language = strtolower($aiOutput['metadata']['language'] ?? 'id') === 'en' ? 'en' : 'id';
        $this->ai->setLocale($language);

        $contractType = $doc->kind === GeneratedDocument::KIND_CONTRACT
            ? UuPdpClauseRelevanceService::mapDocumentTypeToContractType($doc->document_type)
            : null;
        $assumePii = $this->detectsPiiCoverage(is_array($doc->wizard_inputs) ? $doc->wizard_inputs : []);

        $labels = UuPdpClauseRelevanceService::getClauseLabels();
        $guidance = UuPdpClauseRelevanceService::getDraftingGuidance();

        $missingDetail = [];
        foreach ($missing as $key) {
            $key = (string) $key;
            $label = $labels[$key] ?? $key;
            $g = $guidance[$key] ?? null;
            $missingDetail[] = $g
                ? "- {$label} ({$g['pasal']}): {$g['must_include']}"
                : "- {$label}";
        }

        $shapeExample = json_encode([
            'title' => 'string',
            'metadata' => ['version' => '1.0', 'language' => 'id|en'],
            'sections' => [['type' => 'heading_1|heading_2|heading_3|paragraph|list|table|signature_block', 'text' => '...']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $existingJson = json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $system = 'Kamu adalah privacy lawyer UU PDP Indonesia. Tugasmu MENAMBAHKAN / MEMPERBAIKI klausul UU PDP yang masih kurang di dokumen existing.'
            ."\nOutput WAJIB JSON valid murni — JANGAN tambahkan markdown atau teks di luar JSON.\n\n"
            ."Format output (return SELURUH sections, bukan diff):\n".$shapeExample;

        $contextHint = $contractType
            ? "Tipe kontrak (canonical): {$contractType}. PII coverage: ".($assumePii ? 'YA — treat conditional sebagai core' : 'TIDAK terdeteksi')
            : "Tipe dokumen: {$doc->kind}/{$doc->document_type}";

        $user = $contextHint."\n\n"
            ."Klausul UU PDP yang HARUS ditambahkan / diperbaiki:\n"
            .implode("\n", $missingDetail)."\n\n"
            ."Sections existing (JSON):\n{$existingJson}\n\n"
            ."ATURAN:\n"
            ."- Pertahankan semua section existing yang sudah baik.\n"
            ."- Tambahkan pasal/bagian baru untuk klausul yang missing — sebagai heading_1 baru, posisikan secara logis (mis. klausul UU PDP di tengah, sebelum penutup/signature).\n"
            ."- Kalau ada section existing yang TERKAIT tapi kurang detail, perluas paragraf-nya agar mencakup must_include di atas.\n"
            ."- Kutip Pasal UU PDP yang sesuai dalam isi paragraf.\n"
            ."- Re-number heading kalau perlu supaya urutan tetap konsisten.\n"
            ."- Jangan duplikasi klausul yang sudah ada.\n"
            .'Jawab HANYA JSON valid yang berisi sections lengkap (pre-existing + tambahan).';

        $newOutput = $this->ai->ask($system, $user, 6000);
        if (! is_array($newOutput) || empty($newOutput['sections']) || ! is_array($newOutput['sections'])) {
            throw new \RuntimeException('AI returned invalid structure for compliance fix.');
        }

        $merged = $aiOutput;
        $merged['sections'] = $newOutput['sections'];
        if (! empty($newOutput['title']) && is_string($newOutput['title'])) {
            $merged['title'] = $newOutput['title'];
        }
        $merged['metadata'] = is_array($merged['metadata'] ?? null) ? $merged['metadata'] : [];
        $merged['metadata']['compliance_fixed_at'] = now()->toIso8601String();
        $merged['metadata']['compliance_fixed_count'] = ($merged['metadata']['compliance_fixed_count'] ?? 0) + 1;

        // Re-run self-check on the fixed sections.
        try {
            $merged['metadata']['compliance'] = $this->selfCheck(
                $doc->kind,
                $doc->document_type,
                $merged['sections'],
                is_array($doc->wizard_inputs) ? $doc->wizard_inputs : []
            );
        } catch (\Throwable $e) {
            Log::warning('DocumentMaker.fixCompliance: post-fix self-check failed: '.$e->getMessage());
        }

        $doc->ai_output = $merged;
        $doc->save();

        return $doc;
    }

    // =========================================================================
    // Self-check (mini compliance review on generated output)
    // =========================================================================

    /**
     * Run a lightweight compliance check on the generated sections so the UI
     * can display a score + missing-clause list immediately after generation.
     * Mirrors AiFeatureController::contractReview applicability rules but
     * returns a compact shape suited for embedding in metadata.
     *
     * @return array{
     *   score:int,
     *   level:string,
     *   missing_clauses:array<int,string>,
     *   not_applicable_clauses:array<int,string>,
     *   summary:string,
     *   checked_at:string,
     *   status:string
     * }
     */
    public function selfCheck(string $kind, string $documentType, array $sections, array $wizardInputs = []): array
    {
        $plainText = $this->sectionsToPlainText($sections);
        if (mb_strlen($plainText) < 80) {
            return [
                'score' => 0,
                'level' => 'unknown',
                'missing_clauses' => [],
                'not_applicable_clauses' => [],
                'summary' => 'Dokumen terlalu pendek untuk dievaluasi.',
                'checked_at' => now()->toIso8601String(),
                'status' => 'skipped',
            ];
        }

        if ($kind === GeneratedDocument::KIND_CONTRACT) {
            $contractType = UuPdpClauseRelevanceService::mapDocumentTypeToContractType($documentType);
            $assumePii = $this->detectsPiiCoverage($wizardInputs);

            return $this->selfCheckContract($contractType, $plainText, $assumePii);
        }

        return $this->selfCheckPolicy($documentType, $plainText);
    }

    private function selfCheckContract(string $contractType, string $plainText, bool $assumePii): array
    {
        $relevance = UuPdpClauseRelevanceService::getRelevance($contractType);
        $checklist = UuPdpClauseRelevanceService::buildPromptChecklist($contractType, $assumePii);

        $system = 'Kamu adalah Data Protection Officer ahli UU PDP Indonesia (UU 27/2022). '
            ."Output WAJIB JSON valid murni — JANGAN tambahkan teks di luar JSON.\n\n"
            ."Format output:\n"
            .json_encode([
                'score' => '0-100 (integer)',
                'level' => 'good|partial|needs_work',
                'missing_clauses' => ['key klausul UU PDP yang belum ada (HANYA dari core/conditional applicable)'],
                'not_applicable_clauses' => ['key klausul yang TIDAK applicable untuk tipe ini'],
                'summary' => 'ringkasan 1-2 kalimat',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Tipe kontrak: {$contractType}\n\n"
            .$checklist."\n\n"
            ."=== ISI DOKUMEN ===\n"
            .mb_substr($plainText, 0, 7000)
            ."\n=== END ===\n\n"
            ."Beri score 0-100 berdasarkan klausul applicable saja (core + conditional kalau PII), JANGAN paksakan klausul N.A.\n"
            ."- score >= 80 → level=good\n"
            ."- 60-79 → partial\n"
            ."- < 60 → needs_work\n"
            .'Jawab HANYA JSON valid.';

        $resp = $this->ai->ask($system, $user, 1500);
        if (! is_array($resp)) {
            throw new \RuntimeException('Self-check returned invalid shape.');
        }

        return [
            'score' => (int) ($resp['score'] ?? 0),
            'level' => (string) ($resp['level'] ?? 'partial'),
            'missing_clauses' => array_values(array_filter((array) ($resp['missing_clauses'] ?? []), 'is_string')),
            'not_applicable_clauses' => array_values(array_filter((array) ($resp['not_applicable_clauses'] ?? array_values($relevance['not_applicable'] ?? [])), 'is_string')),
            'summary' => (string) ($resp['summary'] ?? ''),
            'contract_type' => $contractType,
            'checked_at' => now()->toIso8601String(),
            'status' => 'completed',
        ];
    }

    private function selfCheckPolicy(string $documentType, string $plainText): array
    {
        $scope = $this->buildPolicyScopeHint($documentType);
        $reviewType = UuPdpClauseRelevanceService::mapPolicyDocumentTypeToReviewType($documentType);
        $labels = UuPdpClauseRelevanceService::getPolicyTypeLabels();
        $label = $labels[$reviewType] ?? $documentType;

        $system = 'Kamu adalah auditor kepatuhan UU PDP. Output WAJIB JSON valid murni.';

        $user = "Tipe dokumen policy/SOP: {$label} (canonical: {$reviewType})\n\n"
            .$scope."\n\n"
            ."=== ISI DOKUMEN ===\n"
            .mb_substr($plainText, 0, 7000)
            ."\n=== END ===\n\n"
            ."Format output:\n"
            .json_encode([
                'score' => '0-100',
                'level' => 'good|partial|needs_work',
                'missing_elements' => ['elemen UU PDP yang seharusnya ada untuk tipe ini tapi belum'],
                'not_applicable_elements' => ['elemen yg memang tidak relevan'],
                'summary' => '1-2 kalimat',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            .'Beri score berdasarkan dimensi applicable saja. Jawab HANYA JSON.';

        $resp = $this->ai->ask($system, $user, 1500);
        if (! is_array($resp)) {
            throw new \RuntimeException('Self-check returned invalid shape.');
        }

        return [
            'score' => (int) ($resp['score'] ?? 0),
            'level' => (string) ($resp['level'] ?? 'partial'),
            'missing_clauses' => array_values(array_filter((array) ($resp['missing_elements'] ?? []), 'is_string')),
            'not_applicable_clauses' => array_values(array_filter((array) ($resp['not_applicable_elements'] ?? []), 'is_string')),
            'summary' => (string) ($resp['summary'] ?? ''),
            'checked_at' => now()->toIso8601String(),
            'status' => 'completed',
        ];
    }

    /** Flatten sections JSON to plain text for review prompts. */
    private function sectionsToPlainText(array $sections): string
    {
        $lines = [];
        foreach ($sections as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            switch ($type) {
                case 'heading_1':
                case 'heading_2':
                case 'heading_3':
                case 'paragraph':
                    if (! empty($node['text']) && is_string($node['text'])) {
                        $lines[] = $node['text'];
                    }
                    break;
                case 'list':
                    foreach ((array) ($node['items'] ?? []) as $it) {
                        $lines[] = '- '.(string) $it;
                    }
                    break;
                case 'table':
                    $headers = (array) ($node['headers'] ?? []);
                    if (! empty($headers)) {
                        $lines[] = implode(' | ', array_map('strval', $headers));
                    }
                    foreach ((array) ($node['rows'] ?? []) as $row) {
                        if (is_array($row)) {
                            $lines[] = implode(' | ', array_map('strval', $row));
                        }
                    }
                    break;
            }
        }

        return implode("\n", $lines);
    }

    // =========================================================================
    // Prompt builders
    // =========================================================================

    /** @return array{0:string,1:string} [system, user] */
    private function buildPrompt(string $kind, string $documentType, string $title, array $inputs, string $language): array
    {
        $expertLabel = $kind === GeneratedDocument::KIND_POLICY
            ? 'privacy lawyer & policy drafter ahli UU PDP Indonesia (UU 27/2022) dan GDPR'
            : 'corporate lawyer ahli kontrak komersial Indonesia (KUH Perdata) dan UU PDP';

        $shapeExample = json_encode([
            'title' => 'string — judul dokumen lengkap',
            'metadata' => [
                'version' => '1.0',
                'language' => 'id|en',
                'effective_date' => 'YYYY-MM-DD or null',
            ],
            'sections' => [
                ['type' => 'heading_1', 'text' => '1. Pendahuluan'],
                ['type' => 'paragraph', 'text' => '...'],
                ['type' => 'heading_2', 'text' => '1.1 Definisi'],
                ['type' => 'list', 'items' => ['Item satu', 'Item dua']],
                ['type' => 'table', 'headers' => ['Kolom A', 'Kolom B'], 'rows' => [['nilai 1', 'nilai 2']]],
                ['type' => 'signature_block', 'parties' => [
                    ['label' => 'Pihak 1', 'name' => 'Nama / Perusahaan', 'title' => 'Jabatan'],
                    ['label' => 'Pihak 2', 'name' => 'Nama / Perusahaan', 'title' => 'Jabatan'],
                ]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $rules = "ATURAN OUTPUT:\n"
            ."- Output WAJIB JSON valid murni. JANGAN tambahkan teks/markdown di luar JSON.\n"
            ."- Field `sections` WAJIB array. Setiap node WAJIB punya `type`.\n"
            ."- Tipe node yang DIIZINKAN: heading_1, heading_2, heading_3, paragraph, list, table, signature_block.\n"
            ."- `paragraph.text` plain string (boleh multi-kalimat, tanpa markdown).\n"
            ."- `list.items` array of string.\n"
            ."- `table.headers` & `table.rows` array — semua sel berupa string.\n"
            ."- `signature_block.parties` array of {label, name, title}. Boleh kosong string saat tidak diketahui.\n"
            ."- Susun dokumen secara hierarkis: gunakan heading_1 untuk pasal/bagian utama, heading_2 untuk sub-pasal.\n"
            ."- Sertakan klausul standar yang relevan (definisi, kewajiban, term & termination, governing law, signature).\n"
            ."- Jika konteks UU PDP relevan, kutip Pasal yang sesuai dalam isi paragraf.\n";

        $contextStr = json_encode($inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $kindLabel = $kind === GeneratedDocument::KIND_POLICY ? 'kebijakan / policy' : 'kontrak / agreement';

        // Per-document-type UU PDP clause checklist. Generator harus tau klausul
        // mana yang WAJIB ada supaya output-nya gak under-cover saat di-review.
        $pdpChecklist = $this->buildPdpChecklist($kind, $documentType, $inputs);

        $system = "Kamu adalah {$expertLabel}.\n"
            ."Tugasmu menyusun {$kindLabel} berbahasa "
            .($language === 'en' ? 'Inggris (English, formal legal register)' : 'Indonesia (formal, hukum)')
            .", lengkap, siap-tanda-tangan, dengan struktur pasal yang jelas.\n\n"
            .$rules
            .($pdpChecklist !== '' ? "\n".$pdpChecklist."\n" : '')
            ."\nFORMAT OUTPUT (sections JSON):\n".$shapeExample;

        $user = "Tipe dokumen ({$kind}): {$documentType}\n"
            ."Judul: {$title}\n\n"
            ."Konteks dari wizard:\n{$contextStr}\n\n"
            ."Susun {$kindLabel} lengkap berdasarkan konteks di atas. Sertakan minimal 6-12 pasal/bagian utama.\n"
            .'Pastikan SEMUA klausul UU PDP yang WAJIB (lihat checklist di system prompt) hadir dalam dokumen, masing-masing sebagai pasal/bagian terpisah dengan kutipan Pasal yang sesuai. '
            .'Jawab HANYA JSON valid sesuai format.';

        return [$system, $user];
    }

    /**
     * Build the UU PDP clause checklist injected into the system prompt.
     *
     * Untuk contracts: pakai per-type relevance map dari UuPdpClauseRelevanceService.
     * Auto-detect PII coverage dari wizard inputs (ada field data_categories /
     * confidential_info_types yg sebut data pribadi/PII), kalau iya conditional
     * di-treat sebagai core.
     *
     * Untuk policies: pakai scope hint per doc type (privacy_policy vs SOP, dll).
     */
    private function buildPdpChecklist(string $kind, string $documentType, array $inputs): string
    {
        if ($kind === GeneratedDocument::KIND_CONTRACT) {
            $contractType = UuPdpClauseRelevanceService::mapDocumentTypeToContractType($documentType);
            $assumePii = $this->detectsPiiCoverage($inputs);

            return UuPdpClauseRelevanceService::buildPromptChecklist($contractType, $assumePii);
        }

        return $this->buildPolicyScopeHint($documentType);
    }

    /**
     * Heuristik deteksi apakah wizard input menyentuh data pribadi.
     * Cek field common: data_categories, confidential_info_types, processing_purpose,
     * pdp_clause = 'Ya', vendor data_handling = 'Ya'.
     */
    private function detectsPiiCoverage(array $inputs): bool
    {
        $piiKeywords = ['pii', 'data pribadi', 'data pelanggan', 'data karyawan', 'identitas', 'kontak', 'biometrik', 'behavioral', 'sensitive', 'transaksi', 'personal data'];

        $haystacks = [];
        foreach (['data_categories', 'confidential_info_types', 'data_subjects', 'audience', 'scope', 'processing_purpose', 'service_scope', 'vendor_service'] as $k) {
            if (isset($inputs[$k])) {
                $haystacks[] = is_array($inputs[$k]) ? implode(' ', array_map('strval', $inputs[$k])) : (string) $inputs[$k];
            }
        }

        $combined = strtolower(implode(' ', $haystacks));
        foreach ($piiKeywords as $kw) {
            if ($combined !== '' && str_contains($combined, $kw)) {
                return true;
            }
        }

        // Eksplicit toggles dari wizard.
        if (isset($inputs['data_handling']) && stripos((string) $inputs['data_handling'], 'ya') === 0) {
            return true;
        }
        if (isset($inputs['pdp_clause']) && stripos((string) $inputs['pdp_clause'], 'ya') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Scope hint per policy doc-type. Delegates ke UuPdpClauseRelevanceService
     * supaya generator + reviewer (AiFeatureController::policyReview) +
     * self-check pakai mapping yg sama.
     */
    private function buildPolicyScopeHint(string $documentType): string
    {
        return UuPdpClauseRelevanceService::buildPolicyPromptScope($documentType);
    }

    private function resolveProviderInfo(): array
    {
        try {
            $config = AiProviderController::getActiveConfig(null, 'chat');
            if ($config) {
                return [
                    'provider' => $config['provider']->slug ?? $config['provider']->name ?? null,
                    'model' => $config['model']->model_id ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('DocumentMaker: provider info resolve skipped: '.$e->getMessage());
        }

        return ['provider' => null, 'model' => null];
    }

    /**
     * Render a canonical ai_output (title/metadata/sections) to a DOCX temp file.
     * Extracted from renderDocx() so sibling generators (e.g. Policy Generator)
     * reuse the exact same DOCX engine without needing a GeneratedDocument.
     */
    public function renderDocxFromOutput(array $aiOutput, string $fallbackTitle): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $sectionStyle = ['marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134];
        $section = $phpWord->addSection($sectionStyle);

        // Title
        $titleStyle = ['name' => 'Calibri', 'size' => 18, 'bold' => true, 'color' => '1F2937'];
        $section->addText((string) ($aiOutput['title'] ?? $fallbackTitle), $titleStyle, ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);

        // Metadata line (small)
        $meta = $aiOutput['metadata'] ?? [];
        $metaLine = collect([
            isset($meta['version']) ? 'Versi '.$meta['version'] : null,
            isset($meta['language']) ? 'Bahasa: '.strtoupper((string) $meta['language']) : null,
            'Dibuat: '.now()->format('d M Y'),
        ])->filter()->implode('  ·  ');
        if ($metaLine !== '') {
            $section->addText($metaLine, ['size' => 9, 'color' => '6B7280', 'italic' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 360]);
        }

        $sections = $aiOutput['sections'] ?? [];
        foreach ($sections as $node) {
            if (! is_array($node) || empty($node['type'])) {
                continue;
            }
            $this->renderDocxNode($section, $node);
        }

        $out = tempnam(sys_get_temp_dir(), 'docmaker_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($out);

        return $out;
    }

    // =========================================================================
    // DOCX section renderer
    // =========================================================================

    /**
     * @param  Section  $section
     */
    private function renderDocxNode($section, array $node): void
    {
        $type = (string) ($node['type'] ?? '');
        switch ($type) {
            case 'heading_1':
                $section->addText((string) ($node['text'] ?? ''), ['size' => 14, 'bold' => true, 'color' => '111827'], ['spaceBefore' => 240, 'spaceAfter' => 120]);
                break;
            case 'heading_2':
                $section->addText((string) ($node['text'] ?? ''), ['size' => 12, 'bold' => true, 'color' => '1F2937'], ['spaceBefore' => 180, 'spaceAfter' => 90]);
                break;
            case 'heading_3':
                $section->addText((string) ($node['text'] ?? ''), ['size' => 11, 'bold' => true, 'color' => '374151'], ['spaceBefore' => 120, 'spaceAfter' => 60]);
                break;
            case 'paragraph':
                $section->addText((string) ($node['text'] ?? ''), ['size' => 11], ['spaceAfter' => 120, 'alignment' => Jc::BOTH]);
                break;
            case 'list':
                $items = is_array($node['items'] ?? null) ? $node['items'] : [];
                foreach ($items as $item) {
                    $section->addListItem((string) $item, 0, ['size' => 11], ['listType' => ListItem::TYPE_BULLET_FILLED]);
                }
                break;
            case 'table':
                $headers = is_array($node['headers'] ?? null) ? $node['headers'] : [];
                $rows = is_array($node['rows'] ?? null) ? $node['rows'] : [];
                $tableStyle = ['borderSize' => 4, 'borderColor' => 'CBD5E1', 'cellMargin' => 80];
                $tbl = $section->addTable($tableStyle);
                if (! empty($headers)) {
                    $tbl->addRow();
                    foreach ($headers as $h) {
                        $cell = $tbl->addCell(null, ['bgColor' => 'F1F5F9']);
                        $cell->addText((string) $h, ['bold' => true, 'size' => 10]);
                    }
                }
                foreach ($rows as $r) {
                    $tbl->addRow();
                    $cells = is_array($r) ? $r : [];
                    foreach ($cells as $c) {
                        $cell = $tbl->addCell();
                        $cell->addText((string) $c, ['size' => 10]);
                    }
                }
                $section->addTextBreak(1);
                break;
            case 'signature_block':
                $section->addTextBreak(2);
                $parties = is_array($node['parties'] ?? null) ? $node['parties'] : [];
                if (empty($parties)) {
                    break;
                }
                $tbl = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
                $tbl->addRow();
                foreach ($parties as $p) {
                    $cell = $tbl->addCell(4500);
                    $cell->addText((string) ($p['label'] ?? ''), ['bold' => true, 'size' => 10]);
                    $cell->addTextBreak(3);
                    $cell->addText('_______________________________', ['size' => 10]);
                    $cell->addText((string) ($p['name'] ?? ''), ['bold' => true, 'size' => 11]);
                    $cell->addText((string) ($p['title'] ?? ''), ['size' => 10, 'italic' => true, 'color' => '6B7280']);
                }
                break;
            default:
                // Unknown node type → fall back to plain paragraph if it has text.
                if (! empty($node['text']) && is_string($node['text'])) {
                    $section->addText($node['text'], ['size' => 11], ['spaceAfter' => 120]);
                }
        }
    }
}
