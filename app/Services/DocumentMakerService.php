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

        $system = "Kamu adalah {$expertLabel}.\n"
            ."Tugasmu menyusun {$kindLabel} berbahasa "
            .($language === 'en' ? 'Inggris (English, formal legal register)' : 'Indonesia (formal, hukum)')
            .", lengkap, siap-tanda-tangan, dengan struktur pasal yang jelas.\n\n"
            .$rules
            ."\nFORMAT OUTPUT (sections JSON):\n".$shapeExample;

        $user = "Tipe dokumen ({$kind}): {$documentType}\n"
            ."Judul: {$title}\n\n"
            ."Konteks dari wizard:\n{$contextStr}\n\n"
            ."Susun {$kindLabel} lengkap berdasarkan konteks di atas. Sertakan minimal 6-12 pasal/bagian utama.\n"
            .'Jawab HANYA JSON valid sesuai format.';

        return [$system, $user];
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
