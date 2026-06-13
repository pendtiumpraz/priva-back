<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\GeneratedPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

/**
 * Policy Generator — drafts a UU PDP privacy policy from wizard input.
 *
 * Sibling of the Policy Review (audit) feature; a first-class feature that
 * REUSES existing infrastructure rather than cloning it:
 *   - AiService          → LLM transport (constructor-injected for testability)
 *   - UuPdpClauseRelevanceService → per-doc-type UU PDP scope (shared w/ reviewer)
 *   - PolicyElementValidator      → deterministic 15-element coverage gate
 *   - DocumentMakerService        → DOCX/PDF rendering of the sections JSON (download)
 *
 * Output is buffered + JSON-parsed (mirrors Policy Review), persisted as a
 * GeneratedPolicy. Legal-safety footer is ALWAYS appended; credit metering +
 * audit logging are handled by the controller (saveAndRespond pattern).
 */
class PolicyGeneratorService
{
    /** MANDATORY legal-safety disclaimer appended to every generated policy. */
    public const LEGAL_DISCLAIMER = 'Catatan Hukum: Template ini dihasilkan dengan bantuan AI dan BUKAN nasihat hukum. '
        .'Dokumen ini WAJIB direview bersama DPO dan tim legal Anda sebelum dipublikasikan atau digunakan.';

    /** English variant of the mandatory disclaimer (language=en output). */
    public const LEGAL_DISCLAIMER_EN = 'Legal Notice: This template was generated with AI assistance and is NOT legal advice. '
        .'It MUST be reviewed with your DPO and legal team before publication or use.';

    public function __construct(private AiService $ai) {}

    /**
     * Generate a privacy policy from wizard inputs. Persists a GeneratedPolicy
     * (status=draft) and returns it. Throws RuntimeException on AI failure.
     */
    public function generate(
        string $orgId,
        string $createdBy,
        string $audience,
        string $documentType,
        string $language,
        string $title,
        array $wizardInputs
    ): GeneratedPolicy {
        $language = strtolower($language) === 'en' ? 'en' : 'id';
        $this->ai->setLocale($language);

        if (! $this->ai->isAvailable()) {
            throw new \RuntimeException('AI provider is not configured / unavailable. Activate a provider in Settings → AI.');
        }

        [$system, $user] = $this->buildPrompt($documentType, $audience, $title, $wizardInputs, $language);

        $aiOutput = $this->ai->ask($system, $user, 6000);
        if (! is_array($aiOutput) || empty($aiOutput['sections']) || ! is_array($aiOutput['sections'])) {
            Log::error('PolicyGenerator: AI output missing sections', ['type' => $documentType, 'output' => $aiOutput]);
            throw new \RuntimeException('AI returned invalid policy structure (sections missing).');
        }

        if (empty($aiOutput['title']) || ! is_string($aiOutput['title'])) {
            $aiOutput['title'] = $title;
        }
        if (! isset($aiOutput['metadata']) || ! is_array($aiOutput['metadata'])) {
            $aiOutput['metadata'] = [];
        }
        $aiOutput['metadata']['language'] = $language;
        $aiOutput['metadata']['audience'] = $audience;
        $aiOutput['metadata']['document_type'] = $documentType;
        $aiOutput['metadata']['generated_at'] = now()->toIso8601String();

        // Deterministic (NON-AI) 15-element coverage → drives the manual-review flag.
        // Computed on the AI-generated content ONLY (before the boilerplate footer,
        // whose disclaimer text would otherwise falsely satisfy elements like DPO).
        $coverage = PolicyElementValidator::validate($aiOutput['sections'], $audience);

        // Legal-safety footer — MANDATORY, always the FINAL section so it renders last.
        $aiOutput = $this->ensureLegalFooter($aiOutput);

        // Per-section confidence (heuristic) → flags low-confidence sections for review.
        $confidence = PolicyConfidenceScorer::score($aiOutput['sections']);

        // Provenance resolved for THIS tenant (mirrors the AiService that ran ask()).
        $provider = $this->resolveProviderInfo($orgId);

        $aiMetadata = [
            'coverage' => $coverage,
            'needs_manual_review' => $coverage['missing'],
            'clause_sources' => $this->clauseSources($documentType, $coverage),
            'confidence' => $confidence,
            'legal_disclaimer' => true,
            'disclaimer_version' => sha1($this->disclaimerText($language)),
            // English output uses Indonesian-anchored prompts → flag for linguist/legal check.
            'needs_legal_linguist_review' => $language === 'en',
            'provider' => $provider['provider'],
            'model' => $provider['model'],
            'generated_at' => now()->toIso8601String(),
        ];

        return GeneratedPolicy::create([
            'org_id' => $orgId,
            'created_by' => $createdBy,
            'audience' => mb_substr($audience, 0, 32),
            'language' => $language,
            'document_type' => mb_substr($documentType, 0, 64),
            'status' => GeneratedPolicy::STATUS_DRAFT,
            'title' => mb_substr($title, 0, 255),
            'wizard_inputs' => $wizardInputs,
            'ai_output' => $aiOutput,
            'ai_metadata' => $aiMetadata,
            'ai_provider' => $provider['provider'],
            'ai_model' => $provider['model'],
            'credits_used' => 0,
        ]);
    }

    /**
     * Render a generated policy to a DOCX temp file by REUSING the Document Maker
     * DOCX engine (same canonical sections shape). Caller cleans up the file.
     */
    public function renderDocx(GeneratedPolicy $policy): string
    {
        // Defense-in-depth: guarantee the legal-safety footer is present at the
        // render boundary, so NO downloaded policy can ever ship without it —
        // even one persisted by a future code path that bypassed generate().
        $aiOutput = $this->ensureLegalFooter(is_array($policy->ai_output) ? $policy->ai_output : []);

        return (new DocumentMakerService($this->ai))->renderDocxFromOutput($aiOutput, (string) $policy->title);
    }

    /**
     * Render a generated policy to a white-labelled PDF temp file (dompdf).
     * Branding (logo/colors/header/footer) comes from the tenant's active
     * DocumentTemplate. Caller cleans up the file.
     */
    public function renderPdf(GeneratedPolicy $policy): string
    {
        $view = $this->renderView('reports.policy_generator.document', $policy);

        $bytes = Pdf::loadHTML($view)
            ->setPaper(($policy->ai_metadata['page_size'] ?? 'a4'), 'portrait')
            ->setOption(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();

        $out = tempnam(sys_get_temp_dir(), 'policy_').'.pdf';
        file_put_contents($out, $bytes);

        return $out;
    }

    /** Render a generated policy to a self-contained, white-labelled HTML embed snippet. */
    public function renderHtml(GeneratedPolicy $policy): string
    {
        return $this->renderView('reports.policy_generator.embed', $policy);
    }

    /** Shared blade payload builder for PDF + HTML (branding + footer-guaranteed sections). */
    private function renderView(string $view, GeneratedPolicy $policy): string
    {
        $aiOutput = $this->ensureLegalFooter(is_array($policy->ai_output) ? $policy->ai_output : []);
        $payload = (new PolicyBrandingService)->payload($policy->organization);

        return view($view, array_merge($payload, [
            'title' => $aiOutput['title'] ?? $policy->title,
            'metadata' => is_array($aiOutput['metadata'] ?? null) ? $aiOutput['metadata'] : [],
            'sections' => is_array($aiOutput['sections'] ?? null) ? $aiOutput['sections'] : [],
        ]))->render();
    }

    /**
     * Idempotently ensure the mandatory legal-safety disclaimer is the final
     * section. No-op if a legal_disclaimer node already exists (no duplicates).
     */
    public function ensureLegalFooter(array $aiOutput): array
    {
        $sections = is_array($aiOutput['sections'] ?? null) ? $aiOutput['sections'] : [];
        $language = strtolower($aiOutput['metadata']['language'] ?? 'id') === 'en' ? 'en' : 'id';

        foreach ($sections as $node) {
            if (is_array($node) && ($node['role'] ?? null) === 'legal_disclaimer') {
                $aiOutput['sections'] = $sections;

                return $aiOutput;
            }
        }

        $sections[] = $this->legalDisclaimerNode($language);
        $aiOutput['sections'] = $sections;

        return $aiOutput;
    }

    private function legalDisclaimerNode(string $language = 'id'): array
    {
        return [
            'type' => 'paragraph',
            'text' => $this->disclaimerText($language),
            'role' => 'legal_disclaimer',
        ];
    }

    private function disclaimerText(string $language): string
    {
        return $language === 'en' ? self::LEGAL_DISCLAIMER_EN : self::LEGAL_DISCLAIMER;
    }

    /**
     * Per-element legal-safety source trail tied to ACTUAL coverage: each
     * mandatory element maps to its UU PDP Pasal, whether the draft covered it,
     * and (for covered elements) the grounding source. Uncovered elements carry
     * source=null so the audit distinguishes "AI wrote this, grounded in Pasal X"
     * from "mandatory element absent → manual review". No vector RAG chunks exist,
     * so the source is the shared UuPdpClauseRelevanceService scope.
     *
     * @param  array{elements:array<int,array{key:string,label:string,pasal:string,covered:bool}>}  $coverage
     * @return array<int,array{element:string,label:string,pasal:string,covered:bool,source:?string,review_type:string}>
     */
    private function clauseSources(string $documentType, array $coverage): array
    {
        $reviewType = UuPdpClauseRelevanceService::mapPolicyDocumentTypeToReviewType($documentType);

        return array_map(fn ($el) => [
            'element' => $el['key'],
            'label' => $el['label'],
            'pasal' => $el['pasal'],
            'covered' => (bool) ($el['covered'] ?? false),
            'source' => ! empty($el['covered']) ? 'UuPdpClauseRelevanceService::buildPolicyPromptScope' : null,
            'review_type' => $reviewType,
        ], $coverage['elements'] ?? []);
    }

    /** @return array{0:string,1:string} [system, user] */
    private function buildPrompt(string $documentType, string $audience, string $title, array $inputs, string $language): array
    {
        $scope = UuPdpClauseRelevanceService::buildPolicyPromptScope($documentType);
        $audienceLabel = $this->audienceLabel($audience);
        $audienceScope = $this->audienceScope($audience);
        $checklist = $this->buildElementChecklist($audience);

        $shapeExample = json_encode([
            'title' => 'string — judul kebijakan',
            'metadata' => ['version' => '1.0', 'language' => 'id|en', 'effective_date' => 'YYYY-MM-DD or null'],
            'sections' => [
                ['type' => 'heading_1', 'text' => '1. Identitas Pengendali Data'],
                ['type' => 'paragraph', 'text' => '...'],
                ['type' => 'heading_2', 'text' => '1.1 Kontak'],
                ['type' => 'list', 'items' => ['Item satu', 'Item dua']],
                ['type' => 'table', 'headers' => ['Kategori Data', 'Tujuan'], 'rows' => [['Nama', 'Akun']]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $rules = "ATURAN OUTPUT:\n"
            ."- Output WAJIB JSON valid murni. JANGAN tambahkan teks/markdown di luar JSON.\n"
            ."- Field `sections` WAJIB array. Setiap node WAJIB punya `type`.\n"
            ."- Tipe node yang DIIZINKAN: heading_1, heading_2, heading_3, paragraph, list, table.\n"
            ."- `paragraph.text` plain string. `list.items` array of string. `table.headers`/`table.rows` array of string.\n"
            ."- Susun hierarkis: heading_1 untuk bagian utama, heading_2 untuk sub-bagian.\n"
            .'- Kutip Pasal UU PDP yang sesuai di dalam isi paragraf setiap elemen.';

        $system = "Kamu adalah privacy lawyer & policy drafter ahli UU PDP Indonesia (UU 27/2022).\n"
            ."Tugasmu menyusun Kebijakan Privasi (Privacy Policy) untuk audiens {$audienceLabel}, "
            .($language === 'en' ? 'dalam Bahasa Inggris (English, formal legal register)' : 'dalam Bahasa Indonesia (formal, hukum)')
            .", lengkap dan siap dipublikasikan.\n\n"
            .($audienceScope !== '' ? '=== FOKUS AUDIENS ==='."\n".$audienceScope."\n\n" : '')
            .$rules."\n\n"
            .$scope."\n\n"
            .$checklist."\n\n"
            ."FORMAT OUTPUT (sections JSON):\n".$shapeExample;

        $applicableCount = count(PolicyElementValidator::ELEMENTS) - count(PolicyElementValidator::AUDIENCE_NOT_APPLICABLE[$audience] ?? []);

        $user = "Tipe dokumen: {$documentType} (audiens: {$audienceLabel})\n"
            ."Judul: {$title}\n\n"
            ."Konteks dari wizard:\n".json_encode($inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n"
            ."Susun Kebijakan Privasi lengkap berdasarkan konteks di atas. WAJIB mencakup SEMUA {$applicableCount} elemen wajib UU PDP "
            .'yang relevan untuk audiens ini (lihat checklist di system prompt), masing-masing sebagai bagian/pasal terpisah dengan kutipan Pasal yang sesuai. '
            .'Gunakan placeholder [ ] hanya bila data benar-benar tidak tersedia. Jawab HANYA JSON valid sesuai format.';

        return [$system, $user];
    }

    private function buildElementChecklist(string $audience): string
    {
        $naKeys = PolicyElementValidator::AUDIENCE_NOT_APPLICABLE[$audience] ?? [];
        $applicable = [];
        $notApplicable = [];
        foreach (PolicyElementValidator::ELEMENTS as $el) {
            if (in_array($el['key'], $naKeys, true)) {
                $notApplicable[] = $el['label'];
            } else {
                $applicable[] = $el['label'].' ('.$el['pasal'].')';
            }
        }

        $count = count($applicable);
        $lines = ["=== {$count} ELEMEN WAJIB KEBIJAKAN PRIVASI UU PDP (SEMUA harus hadir untuk audiens ini) ==="];
        foreach ($applicable as $i => $label) {
            $lines[] = ($i + 1).'. '.$label;
        }
        if (! empty($notApplicable)) {
            $lines[] = '';
            $lines[] = '=== TIDAK RELEVAN untuk audiens ini — JANGAN dipaksakan ke dokumen ===';
            $lines[] = '- '.implode(', ', $notApplicable);
        }

        return implode("\n", $lines);
    }

    private function audienceLabel(string $audience): string
    {
        return match ($audience) {
            GeneratedPolicy::AUDIENCE_CUSTOMER => 'Pelanggan / Konsumen',
            GeneratedPolicy::AUDIENCE_EMPLOYEE => 'Karyawan',
            GeneratedPolicy::AUDIENCE_JOB_APPLICANT => 'Pelamar Kerja',
            GeneratedPolicy::AUDIENCE_EXTERNAL => 'Pihak Eksternal (vendor / pengunjung)',
            default => 'Pelanggan / Konsumen',
        };
    }

    /** Per-audience emphasis injected into the generation prompt. */
    private function audienceScope(string $audience): string
    {
        return match ($audience) {
            GeneratedPolicy::AUDIENCE_CUSTOMER => 'Transparansi ke konsumen/pelanggan: kategori data yang dikumpulkan via produk/layanan, tujuan komersial, hak konsumen, cookie & pelacak, transfer data, persetujuan & penarikannya.',
            GeneratedPolicy::AUDIENCE_EMPLOYEE => 'Pemrosesan data karyawan dalam hubungan kerja: data kepegawaian, payroll, kehadiran, monitoring, dasar hukum kontrak kerja/kewajiban hukum, retensi pasca-kerja. Cookie web & data anak TIDAK relevan.',
            GeneratedPolicy::AUDIENCE_JOB_APPLICANT => 'Pemrosesan data pelamar dalam rekrutmen: data lamaran/CV, dasar hukum langkah pra-kontrak, retensi data pelamar yang tidak diterima, hak pelamar. Cookie web & data anak TIDAK relevan.',
            GeneratedPolicy::AUDIENCE_EXTERNAL => 'Pihak eksternal (vendor/mitra/pengunjung): data kontak bisnis, tujuan kerja sama/kunjungan, penerima data, keamanan, transfer lintas negara.',
            default => '',
        };
    }

    /** @return array{provider:?string,model:?string} */
    private function resolveProviderInfo(string $orgId): array
    {
        try {
            $config = AiProviderController::getActiveConfig($orgId, 'chat');
            if ($config) {
                return [
                    'provider' => $config['provider']->slug ?? $config['provider']->name ?? null,
                    'model' => $config['model']->model_id ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('PolicyGenerator: provider info resolve skipped: '.$e->getMessage());
        }

        return ['provider' => null, 'model' => null];
    }
}
