<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use App\Models\Ropa;
use App\Models\Dpia;
use App\Models\GapAssessment;
use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Services\DocxTemplateService;

class TemplateExportController extends Controller
{
    private function t($text): string
    {
        return htmlspecialchars((string) $text, ENT_COMPAT, 'UTF-8');
    }

    // ────────────────────────────────────────────────────────────────
    //  DESIGN SYSTEM  —  book-layouter proportions
    //
    //  Palette (matches reference book design):
    //    --navy     #16284C  primary (cover bg, headings, label accent)
    //    --lav      #F4F2FE  subtle fill for label columns & headers
    //    --ink      #0f172a  body text
    //    --gray     #767171  muted / captions
    //    --rule     #D9D9D9  table borders
    //    --white    #FFFFFF
    //  Risk palette kept for status badges only.
    //
    //  Typography:
    //    Headings  → Poppins bold (navy)
    //    Body      → Roboto 10pt (ink)
    //    Caption   → Roboto 8pt (gray)
    // ────────────────────────────────────────────────────────────────

    private const NAVY  = '16284C';
    private const LAV   = 'F4F2FE';
    private const INK   = '0F172A';
    private const GRAY  = '767171';
    private const RULE  = 'D9D9D9';
    private const HEAD_FONT = 'Poppins';
    private const BODY_FONT = 'Roboto';

    private function applyDefaultFont(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName(self::BODY_FONT);
        $phpWord->setDefaultFontSize(10);
    }

    private function riskColor(string $risk): string
    {
        return match (strtolower($risk)) {
            'low' => '16a34a',
            'medium', 'med' => 'eab308',
            'high' => 'ea580c',
            'critical' => 'dc2626',
            default => '64748b',
        };
    }

    private function statusColor(string $status): string
    {
        return match (strtolower($status)) {
            'approved', 'active', 'completed', 'closed' => '16a34a',
            'review', 'pending' => 'eab308',
            'rejected', 'expired' => 'dc2626',
            default => '64748b', // draft
        };
    }

    /**
     * Cover page — tenant-owned, book-layout style.
     *
     * The document is the tenant's property; PRIVASIMU does NOT appear in
     * the visible output. Hero is solid navy with the tenant's logo or
     * name centered high, then document-type badge, title, reg number,
     * and a 4-column meta strip. Bottom band is a white confidentiality
     * and issue-date block.
     *
     * @param array $orgMeta keys: name, website, dpo_name, dpo_email, logo_path
     */
    private function addCoverPage(PhpWord $phpWord, string $docType, string $title, string $regNumber, string $status, string $riskLevel, array $orgMeta)
    {
        $this->applyDefaultFont($phpWord);
        $section = $phpWord->addSection([
            'marginTop' => 0, 'marginBottom' => 0, 'marginLeft' => 0, 'marginRight' => 0,
        ]);

        $phpWord->addTableStyle('CoverBase', ['borderSize' => 0, 'cellMargin' => 0, 'width' => 11905, 'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP]);

        // ── Hero band ──
        $hero = $section->addTable('CoverBase');
        $heroRow = $hero->addRow(11900, ['exactHeight' => true]);
        $heroCell = $heroRow->addCell(11905, ['bgColor' => self::NAVY, 'valign' => 'center']);

        $heroCell->addTextBreak(3);

        // Tenant logo (if uploaded) — else tenant name as wordmark
        $logoPath = $orgMeta['logo_path'] ?? null;
        if ($logoPath && file_exists($logoPath)) {
            try {
                $heroCell->addImage($logoPath, [
                    'width' => 180, 'height' => 60, 'alignment' => Jc::CENTER,
                ]);
                $heroCell->addTextBreak(1);
            } catch (\Throwable $e) {
                $heroCell->addText(strtoupper($orgMeta['name'] ?? 'Organization'),
                    ['size' => 14, 'color' => 'ffffff', 'bold' => true, 'allCaps' => true, 'name' => self::HEAD_FONT],
                    ['alignment' => Jc::CENTER]);
            }
        } else {
            $heroCell->addText(strtoupper($orgMeta['name'] ?? 'Organization'),
                ['size' => 14, 'color' => 'ffffff', 'bold' => true, 'allCaps' => true, 'name' => self::HEAD_FONT],
                ['alignment' => Jc::CENTER]);
        }

        if (!empty($orgMeta['website'])) {
            $heroCell->addText($this->t($orgMeta['website']),
                ['size' => 9, 'color' => 'D9D9D9', 'italic' => true],
                ['alignment' => Jc::CENTER]);
        }

        $heroCell->addTextBreak(4);

        // Document type chip — white outline on navy bg so it reads like a badge
        $chipTable = $heroCell->addTable(['cellMargin' => 120, 'alignment' => Jc::CENTER]);
        $chipRow = $chipTable->addRow();
        $chipRow->addCell(3500, [
            'bgColor' => 'FFFFFF', 'valign' => 'center',
        ])->addText($this->t(strtoupper($docType)), [
            'size' => 10, 'bold' => true, 'color' => self::NAVY, 'allCaps' => true,
            'name' => self::HEAD_FONT,
        ], ['alignment' => Jc::CENTER]);

        $heroCell->addTextBreak(3);

        // Document title — Poppins bold large
        $heroCell->addText($this->t($title ?: 'Untitled Record'), [
            'size' => 28, 'bold' => true, 'color' => 'ffffff', 'name' => self::HEAD_FONT,
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);

        $heroCell->addTextBreak(1);

        if ($regNumber) {
            $heroCell->addText($this->t($regNumber), [
                'size' => 12, 'color' => 'D9D9D9', 'name' => self::BODY_FONT,
            ], ['alignment' => Jc::CENTER]);
        }

        $heroCell->addTextBreak(5);

        // Divider line
        $heroCell->addText('────────────', ['size' => 8, 'color' => 'FFFFFF', 'bold' => true], ['alignment' => Jc::CENTER]);

        $heroCell->addTextBreak(3);

        // Meta row
        $metaTable = $heroCell->addTable(['cellMargin' => 140, 'alignment' => Jc::CENTER]);
        $metaRow = $metaTable->addRow();

        $this->coverMetaCell($metaRow, 'Controller', $orgMeta['name'] ?? '-');
        $this->coverMetaCell($metaRow, 'Status', strtoupper($status ?: 'DRAFT'), $this->statusColor($status ?: 'draft'));
        $this->coverMetaCell($metaRow, 'Risk', strtoupper($riskLevel ?: 'N/A'), $this->riskColor($riskLevel ?: ''));
        $this->coverMetaCell($metaRow, 'Issued', now()->format('d M Y'));

        // ── Footer band ──
        $foot = $section->addTable('CoverBase');
        $footRow = $foot->addRow(4900, ['exactHeight' => true]);
        $footCell = $footRow->addCell(11905, ['bgColor' => 'ffffff', 'valign' => 'center']);

        $footCell->addTextBreak(4);
        $footCell->addText('CONFIDENTIAL', ['size' => 10, 'color' => 'dc2626', 'bold' => true, 'allCaps' => true], ['alignment' => Jc::CENTER]);
        $footCell->addTextBreak(1);
        $footCell->addText(
            'This document contains confidential personal data processing information.',
            ['size' => 9, 'color' => self::GRAY, 'italic' => true], ['alignment' => Jc::CENTER]
        );
        $footCell->addText(
            'Distribution outside the intended recipient requires Data Protection Officer approval.',
            ['size' => 9, 'color' => self::GRAY, 'italic' => true], ['alignment' => Jc::CENTER]
        );

        $footCell->addTextBreak(3);

        $contactTable = $footCell->addTable(['cellMargin' => 100, 'alignment' => Jc::CENTER]);
        $contactRow = $contactTable->addRow();
        $contactCell = $contactRow->addCell(11000, []);
        if (!empty($orgMeta['dpo_name']) || !empty($orgMeta['dpo_email'])) {
            $contactCell->addText(
                'Data Protection Officer: ' . trim(($orgMeta['dpo_name'] ?? '') . (isset($orgMeta['dpo_email']) ? ' · ' . $orgMeta['dpo_email'] : ''), ' ·'),
                ['size' => 8, 'color' => self::GRAY], ['alignment' => Jc::CENTER]
            );
        }
        $contactCell->addText('Issued on ' . now()->format('d F Y H:i') . ' WIB',
            ['size' => 8, 'color' => self::GRAY], ['alignment' => Jc::CENTER]);

        return $section;
    }

    private function coverMetaCell($metaRow, string $label, string $value, ?string $accent = null): void
    {
        $cell = $metaRow->addCell(2400, ['valign' => 'center']);
        $cell->addText(strtoupper($label), ['size' => 8, 'color' => 'D9D9D9', 'bold' => true, 'name' => self::HEAD_FONT], ['alignment' => Jc::CENTER]);
        $cell->addText($this->t($value), [
            'size' => 11, 'bold' => true, 'color' => $accent ?: 'ffffff',
            'name' => self::HEAD_FONT,
        ], ['alignment' => Jc::CENTER]);
    }

    /**
     * Inner content section — running header uses the TENANT's name (not
     * Privasimu) so the exported document is fully white-labeled.
     *
     * @param string $headerText  Free-text shown on the right of the header
     *                            (typically "<DocType> · <reg number>")
     * @param array  $orgMeta     Optional tenant meta (name, website) to show
     *                            on the left of the header + footer.
     */
    private function addContentSection(PhpWord $phpWord, string $headerText, array $orgMeta = [])
    {
        $section = $phpWord->addSection([
            'marginTop' => 1200, 'marginBottom' => 1000,
            'marginLeft' => 900, 'marginRight' => 900,
            'headerHeight' => 600, 'footerHeight' => 400,
            'bgColor' => 'ffffff',
        ]);

        $tenantName = strtoupper($orgMeta['name'] ?? 'Organization');
        $tenantSite = $orgMeta['website'] ?? '';

        // Running header: tenant name left, doc ref right
        $header = $section->addHeader();
        $hdrTable = $header->addTable(['width' => 100 * 50, 'borderBottomSize' => 6, 'borderBottomColor' => self::NAVY]);
        $hdrTable->addRow(400, ['exactHeight' => true]);
        $hdrLeft = $hdrTable->addCell(5500, ['valign' => 'center']);
        $hdrLeft->addText($this->t($tenantName), ['size' => 8, 'bold' => true, 'color' => self::NAVY, 'allCaps' => true, 'name' => self::HEAD_FONT]);
        $hdrRight = $hdrTable->addCell(5500, ['valign' => 'center']);
        $hdrRight->addText($this->t($headerText), ['size' => 8, 'color' => self::GRAY, 'italic' => true], ['alignment' => Jc::END]);

        // Footer: confidentiality + tenant website + page number
        $footer = $section->addFooter();
        $footTable = $footer->addTable(['width' => 100 * 50, 'borderTopSize' => 4, 'borderTopColor' => self::RULE]);
        $footTable->addRow(300, ['exactHeight' => true]);
        $footTable->addCell(4000, ['valign' => 'center'])
            ->addText('CONFIDENTIAL · Personal Data', ['size' => 7, 'color' => self::GRAY, 'allCaps' => true]);
        $footTable->addCell(3000, ['valign' => 'center'])
            ->addText($this->t($tenantSite), ['size' => 7, 'color' => self::GRAY], ['alignment' => Jc::CENTER]);
        $pageCell = $footTable->addCell(4000, ['valign' => 'center']);
        $pageCell->addPreserveText('Page {PAGE} of {NUMPAGES}',
            ['size' => 7, 'color' => self::NAVY, 'bold' => true],
            ['alignment' => Jc::END]);

        return $section;
    }

    /**
     * Resolve tenant metadata used across the header/footer/cover. Looks
     * up logo path from the Organization row; if a public URL is stored
     * we don't fetch it (would require HTTP), we only use locally stored
     * files reachable via public disk.
     */
    private function resolveOrgMeta(?\App\Models\Organization $org): array
    {
        if (!$org) return ['name' => 'Organization'];
        $meta = [
            'name' => $org->name ?? 'Organization',
            'website' => $org->website ?? '',
            'dpo_name' => $org->dpo_name ?? '',
            'dpo_email' => $org->dpo_email ?? '',
        ];

        if ($org->logo_url) {
            $url = $org->logo_url;
            // Only try local files via the public disk
            if (str_starts_with($url, '/storage/')) {
                $rel = substr($url, strlen('/storage/'));
                $local = storage_path('app/public/' . $rel);
                if (is_file($local)) $meta['logo_path'] = $local;
            }
        }
        return $meta;
    }

    /**
     * Section title — coloured brand bar with section number.
     */
    private function addSectionTitle($section, string $title)
    {
        $section->addTextBreak(1);

        $num = '';
        $clean = $title;
        if (preg_match('/^([0-9]+(?:\.[0-9]+)*)\.?\s+(.*)$/', trim($title), $m)) {
            $num = $m[1];
            $clean = $m[2];
        }

        // Navy title bar, Poppins bold on white — matches the reference book.
        $table = $section->addTable(['cellMargin' => 0, 'width' => 100 * 50]);
        $row = $table->addRow(520, ['exactHeight' => true]);

        if ($num !== '') {
            $badge = $row->addCell(700, ['bgColor' => self::NAVY, 'valign' => 'center']);
            $badge->addText($num, ['size' => 11, 'bold' => true, 'color' => 'ffffff', 'name' => self::HEAD_FONT], ['alignment' => Jc::CENTER]);
        }
        $titleCell = $row->addCell($num !== '' ? 10300 : 11000, ['bgColor' => self::NAVY, 'valign' => 'center']);
        $titleCell->addText('  ' . $this->t($clean), [
            'size' => 12, 'bold' => true, 'color' => 'ffffff',
            'name' => self::HEAD_FONT,
        ]);

        $section->addTextBreak(1);
    }

    /**
     * Label / value 2-column row. Label column sits on the lavender fill
     * so it reads as a tag, value sits on white. Matches the book-style
     * reference design.
     */
    private function addInfoRow($table, string $label, string $value)
    {
        $row = $table->addRow(null, ['cantSplit' => true]);

        $labelCell = $row->addCell(3400, [
            'bgColor' => self::LAV, 'valign' => 'top',
            'borderTopSize' => 4, 'borderTopColor' => self::RULE,
            'borderBottomSize' => 4, 'borderBottomColor' => self::RULE,
            'borderLeftSize' => 4, 'borderLeftColor' => self::RULE,
            'borderRightSize' => 4, 'borderRightColor' => 'FFFFFF',
        ]);
        $labelCell->addText($this->t($label), [
            'size' => 10, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT,
        ], ['spaceBefore' => 100, 'spaceAfter' => 100]);

        $valueCell = $row->addCell(7600, [
            'bgColor' => 'FFFFFF', 'valign' => 'top',
            'borderTopSize' => 4, 'borderTopColor' => self::RULE,
            'borderBottomSize' => 4, 'borderBottomColor' => self::RULE,
            'borderRightSize' => 4, 'borderRightColor' => self::RULE,
        ]);
        $valueCell->addText($this->t($value ?: '—'), [
            'size' => 10, 'color' => self::INK, 'name' => self::BODY_FONT,
        ], ['spaceBefore' => 100, 'spaceAfter' => 100]);
    }

    /**
     * Tabular data (N-column headers + rows). Used for DPO teams, PIC lists,
     * recipient lists — anything that's naturally a grid. Header row is navy
     * with white Poppins text, body rows alternate white / lavender.
     *
     * @param array $headers  ['Nomor', 'Nama', 'Email', 'Phone']
     * @param array $rows     [['1', 'DPO Corp', 'dpo@x.com', '+62…'], ...]
     * @param array $widths   Optional twip widths per col; defaults equal split
     */
    private function addDataTable($section, array $headers, array $rows, array $widths = []): void
    {
        if (empty($headers)) return;
        $n = count($headers);
        if (empty($widths)) {
            $total = 100 * 50;
            $widths = array_fill(0, $n, (int) floor($total / $n));
        }

        $t = $section->addTable([
            'borderSize' => 4, 'borderColor' => self::RULE,
            'cellMargin' => 80, 'width' => 100 * 50,
            'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT,
        ]);

        // Header row
        $hr = $t->addRow(null, ['cantSplit' => true]);
        foreach ($headers as $i => $h) {
            $cell = $hr->addCell($widths[$i] ?? 2000, ['bgColor' => self::NAVY, 'valign' => 'center']);
            $cell->addText($this->t((string) $h), [
                'size' => 10, 'bold' => true, 'color' => 'FFFFFF', 'name' => self::HEAD_FONT,
            ], ['spaceBefore' => 80, 'spaceAfter' => 80]);
        }

        // Body rows
        foreach ($rows as $ri => $row) {
            $bg = $ri % 2 === 0 ? 'FFFFFF' : self::LAV;
            $br = $t->addRow(null, ['cantSplit' => true]);
            for ($i = 0; $i < $n; $i++) {
                $v = $row[$i] ?? '';
                $cell = $br->addCell($widths[$i] ?? 2000, ['bgColor' => $bg, 'valign' => 'top']);
                $cell->addText($this->t((string) ($v === '' ? '—' : $v)), [
                    'size' => 10, 'color' => self::INK, 'name' => self::BODY_FONT,
                ], ['spaceBefore' => 60, 'spaceAfter' => 60]);
            }
        }

        $section->addTextBreak(1);
    }

    private function makeInfoTable($section)
    {
        return $section->addTable([
            'borderSize' => 0,
            'cellMargin' => 120,
            'width' => 100 * 50,
            'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT,
            'alignment' => Jc::CENTER,
        ]);
    }

    /**
     * Coloured status/risk badge rendered inline in a single-cell table.
     */
    private function addBadge($section, string $label, string $value, string $bgColor): void
    {
        $t = $section->addTable(['cellMargin' => 100, 'alignment' => Jc::START]);
        $r = $t->addRow(320, ['exactHeight' => true]);
        $c = $r->addCell(3500, ['bgColor' => $bgColor, 'valign' => 'center']);
        $c->addText(strtoupper($label) . ': ' . $this->t($value), [
            'size' => 10, 'bold' => true, 'color' => 'ffffff', 'allCaps' => true,
        ], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);
    }

    private function fmtArray($arr): string
    {
        if (!$arr) return '-';
        if (is_array($arr)) return implode(', ', array_filter($arr)) ?: '-';
        return (string) $arr ?: '-';
    }

    /**
     * If tenant has uploaded a custom .docx template for `$kind`, render via
     * DocxTemplateService and return a streamed download. Returns null when
     * no tenant template exists (caller falls back to built-in generator).
     *
     * On render error, logs + returns null (fallback to built-in).
     */
    private function tryRenderFromTenantTemplate(string $kind, $model, string $baseFileName)
    {
        $user = auth()->user();
        if (!$user || !$user->org_id) return null;

        $docTpl = DocumentTemplate::activeForOrg($user->org_id);
        if (!$docTpl || empty(($docTpl->docx_templates ?? [])[$kind]['path'] ?? null)) return null;

        $org = Organization::find($user->org_id);
        if (!$org) return null;

        try {
            $svc = app(DocxTemplateService::class);
            $tempFile = match ($kind) {
                'ropa' => $svc->renderRopa($model, $docTpl, $org),
                'dpia' => $svc->renderDpia($model, $docTpl, $org),
                'gap'  => $svc->renderGap($model, $docTpl, $org),
                default => null,
            };
            if (!$tempFile) return null;

            while (ob_get_level() > 0) { ob_end_clean(); }
            return response()->download($tempFile, $baseFileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            \Log::warning("Tenant DOCX template render failed ({$kind}): " . $e->getMessage());
            return null;
        }
    }

    // ================================================================
    //  ROPA EXPORT
    // ================================================================
    public function exportRopa($id)
    {
        $ropa = Ropa::where('org_id', auth()->user()->org_id)->findOrFail($id);

        // Custom tenant template wins when present.
        $outputFileName = 'ROPA_' . ($ropa->registration_number ?? 'export') . '.docx';
        if ($resp = $this->tryRenderFromTenantTemplate('ropa', $ropa, $outputFileName)) return $resp;

        try {
            $phpWord = new PhpWord();
            $this->applyDefaultFont($phpWord);

            $wiz = $ropa->wizard_data ?? [];
            $detail = $wiz['detail_pemrosesan'] ?? [];
            $dpo = $wiz['dpo_team'] ?? [];
            $info = $wiz['informasi_pemrosesan'] ?? [];
            $pengumpulan = $wiz['pengumpulan_data'] ?? [];
            $penggunaan = $wiz['penggunaan_penyimpanan'] ?? [];
            $pengiriman = $wiz['pengiriman_data'] ?? [];
            $retensi = $wiz['retensi_keamanan'] ?? [];
            $orgMeta = $this->resolveOrgMeta(auth()->user()->organization);

            // Cover Page
            $this->addCoverPage(
                $phpWord, 'Record of Processing Activities (ROPA)',
                $ropa->processing_activity ?? $detail['nama_pemrosesan'] ?? 'Untitled ROPA',
                $ropa->registration_number ?? '-',
                $ropa->status ?? 'draft',
                $ropa->risk_level ?? 'low',
                $orgMeta
            );

            // Content Page
            $sec = $this->addContentSection($phpWord, 'ROPA · ' . ($ropa->registration_number ?? ''), $orgMeta);

            // 1. Detail Pemrosesan
            $this->addSectionTitle($sec, '1. Detail Pemrosesan');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Nama Pemrosesan', $ropa->processing_activity ?? $detail['nama_pemrosesan'] ?? '-');
            $this->addInfoRow($t, 'Entitas', $ropa->entity ?? $detail['entitas'] ?? '-');
            $this->addInfoRow($t, 'Divisi / Departemen', $ropa->division ?? $detail['divisi'] ?? '-');
            $this->addInfoRow($t, 'Unit Kerja', $ropa->work_unit ?? $detail['unit_kerja'] ?? '-');
            $this->addInfoRow($t, 'Deskripsi Pemrosesan', $detail['deskripsi'] ?? $ropa->description ?? '-');
            $this->addInfoRow($t, 'Risk Level', strtoupper($ropa->risk_level ?? '-'));
            $this->addInfoRow($t, 'Sistem / Aplikasi Terkait', $detail['sistem_terkait'] ?? '-');

            // 2. Tim DPO
            $this->addSectionTitle($sec, '2. Tim DPO dan PIC');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Nama DPO', $dpo['dpo_name'] ?? '-');
            $this->addInfoRow($t, 'Email DPO', $dpo['dpo_email'] ?? '-');
            $this->addInfoRow($t, 'Telepon DPO', $dpo['dpo_phone'] ?? '-');
            $this->addInfoRow($t, 'Jabatan DPO', $dpo['dpo_jabatan'] ?? '-');
            $this->addInfoRow($t, 'Nama PIC', $dpo['pic_name'] ?? '-');
            $this->addInfoRow($t, 'Kategori Pemrosesan', $ropa->kategori_pemrosesan ?? $dpo['kategori_pemrosesan'] ?? '-');

            // 3. Informasi Pemrosesan
            $this->addSectionTitle($sec, '3. Informasi Pemrosesan');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Tujuan Pemrosesan', $ropa->purpose ?? $info['tujuan'] ?? '-');
            $this->addInfoRow($t, 'Dasar Hukum Pemrosesan', $ropa->legal_basis ?? $info['dasar_pemrosesan'] ?? '-');
            $this->addInfoRow($t, 'Detail / Keterangan Dasar Hukum', $ropa->legal_basis_detail ?? $info['legal_basis_detail'] ?? $info['keterangan_dasar'] ?? '-');

            // 4. Pengumpulan Data
            $this->addSectionTitle($sec, '4. Pengumpulan Data');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Sumber Data', $pengumpulan['sumber_data'] ?? '-');
            $this->addInfoRow($t, 'Jumlah Subjek', $pengumpulan['jumlah_subjek'] ?? '-');
            $this->addInfoRow($t, 'Jenis Data - Umum', $this->fmtArray($pengumpulan['jenis_data_umum'] ?? []));
            $this->addInfoRow($t, 'Jenis Data - Spesifik', $this->fmtArray($pengumpulan['jenis_data_spesifik'] ?? []));
            $this->addInfoRow($t, 'Jenis Data - PII', $this->fmtArray($pengumpulan['jenis_data_pii'] ?? []));

            // 5. Penggunaan dan Penyimpanan
            $this->addSectionTitle($sec, '5. Penggunaan dan Penyimpanan');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Kategori Pihak', $this->fmtArray($penggunaan['kategori_pihak'] ?? []));
            $this->addInfoRow($t, 'Cara Pemrosesan', $penggunaan['cara_pemrosesan'] ?? '-');
            $this->addInfoRow($t, 'Lokasi Penyimpanan', $penggunaan['lokasi_penyimpanan'] ?? '-');
            $this->addInfoRow($t, 'Pihak Ketiga', $penggunaan['pihak_ketiga'] ?? '-');
            $this->addInfoRow($t, 'Nama Pihak Ketiga', $penggunaan['nama_pihak_ketiga'] ?? '-');

            // 6. Pengiriman Data
            $this->addSectionTitle($sec, '6. Pengiriman Data');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Ada Penerima Data', $pengiriman['ada_penerima'] ?? '-');
            $this->addInfoRow($t, 'Penerima Data', $pengiriman['penerima_data'] ?? '-');
            $this->addInfoRow($t, 'Transfer ke Luar Negeri', $pengiriman['transfer_luar'] ?? '-');
            $this->addInfoRow($t, 'Negara Tujuan', $pengiriman['negara_tujuan'] ?? '-');
            $this->addInfoRow($t, 'Safeguards', $pengiriman['safeguards'] ?? '-');
            $penerima = $pengiriman['penerima'] ?? [];
            if (is_array($penerima) && count($penerima) > 0) {
                $this->addInfoRow($t, 'Daftar Penerima', $this->fmtArray(array_map(fn($p) => is_array($p) ? ($p['nama'] ?? $p['name'] ?? json_encode($p)) : $p, $penerima)));
            }

            // 7. Retensi dan Keamanan
            $this->addSectionTitle($sec, '7. Retensi dan Keamanan Data');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Masa Retensi', $ropa->retention_period ?? $retensi['masa_retensi'] ?? '-');
            $this->addInfoRow($t, 'Prosedur Pemusnahan', $retensi['prosedur_pemusnahan'] ?? '-');
            $this->addInfoRow($t, 'Pernah Insiden', $retensi['pernah_insiden'] ?? '-');
            $this->addInfoRow($t, 'Kontrol Keamanan', $this->fmtArray($retensi['kontrol_keamanan'] ?? []));

            // Status
            $this->addSectionTitle($sec, 'Status Dokumen');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Status', strtoupper($ropa->status ?? '-'));
            $this->addInfoRow($t, 'Progress', ($ropa->progress ?? 0) . '%');
            $this->addInfoRow($t, 'Regulasi', $ropa->regulation_code ?? '-');
            $this->addInfoRow($t, 'Dibuat', $ropa->created_at ? $ropa->created_at->format('d F Y H:i') : '-');
            $this->addInfoRow($t, 'Terakhir Diupdate', $ropa->updated_at ? $ropa->updated_at->format('d F Y H:i') : '-');

            $outputFileName = 'ROPA_' . ($ropa->registration_number ?? 'export') . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'ropa_');
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);

            while (ob_get_level() > 0) { ob_end_clean(); }

            return response()->download($tempFile, $outputFileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    // ================================================================
    //  DPIA EXPORT
    // ================================================================
    public function exportDpia($id)
    {
        $dpia = Dpia::with('ropa')->where('org_id', auth()->user()->org_id)->findOrFail($id);

        $outputFileName = 'DPIA_' . ($dpia->registration_number ?? 'export') . '.docx';
        if ($resp = $this->tryRenderFromTenantTemplate('dpia', $dpia, $outputFileName)) return $resp;

        try {
            $phpWord = new PhpWord();
            $this->applyDefaultFont($phpWord);

            $wiz = $dpia->wizard_data ?? [];
            $infoD = $wiz['informasi_dpia'] ?? [];
            $koneksi = $wiz['koneksi_ropa'] ?? [];
            $risiko = $wiz['potensi_risiko'] ?? [];
            $ra = $dpia->risk_assessment ?? [];
            $orgMeta = $this->resolveOrgMeta(auth()->user()->organization);

            // Cover Page
            $this->addCoverPage(
                $phpWord, 'Data Protection Impact Assessment (DPIA)',
                $dpia->description ?? $infoD['description'] ?? 'Untitled DPIA',
                $dpia->registration_number ?? '-',
                $dpia->status ?? 'draft',
                $dpia->risk_level ?? 'low',
                $orgMeta
            );

            // Content Page
            $sec = $this->addContentSection($phpWord, 'DPIA · ' . ($dpia->registration_number ?? ''), $orgMeta);

            // 1. Informasi DPIA
            $this->addSectionTitle($sec, '1. Informasi DPIA');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Nomor DPIA', $dpia->registration_number ?? '-');
            $this->addInfoRow($t, 'Deskripsi', $dpia->description ?? $infoD['description'] ?? '-');
            $this->addInfoRow($t, 'Nama PIC', $infoD['pic_name'] ?? '-');
            $this->addInfoRow($t, 'DPO In Charge', $infoD['dpo_name'] ?? '-');
            $this->addInfoRow($t, 'Email DPO', $infoD['dpo_email'] ?? '-');
            $this->addInfoRow($t, 'Risk Level', strtoupper($dpia->risk_level ?? '-'));
            $this->addInfoRow($t, 'Regulasi', $dpia->regulation_code ?? '-');

            // 2. Koneksi ROPA
            $this->addSectionTitle($sec, '2. Koneksi ROPA');
            $connectedRopas = $koneksi['connected_ropas'] ?? [];
            $hasRopaConnection = false;
            if (!empty($connectedRopas)) {
                $ropaRecords = Ropa::whereIn('id', $connectedRopas)->get();
                if ($ropaRecords->isNotEmpty()) {
                    $hasRopaConnection = true;
                    $rt = $sec->addTable(['borderSize' => 4, 'borderColor' => 'e0e0e0', 'cellMargin' => 80]);
                    $headerRow = $rt->addRow();
                    foreach (['No. ROPA', 'Nama Pemrosesan', 'Divisi', 'Risk'] as $h) {
                        $headerRow->addCell(2500, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                            ->addText($h, ['size' => 9, 'bold' => true, 'color' => 'ffffff']);
                    }
                    foreach ($ropaRecords as $ropaRec) {
                        $row = $rt->addRow();
                        $row->addCell(2500, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t($ropaRec->registration_number ?? '-'), ['size' => 9]);
                        $row->addCell(2500, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t($ropaRec->processing_activity ?? '-'), ['size' => 9]);
                        $row->addCell(2500, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t($ropaRec->division ?? '-'), ['size' => 9]);
                        $row->addCell(2500, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t(strtoupper($ropaRec->risk_level ?? '-')), ['size' => 9, 'bold' => true]);
                    }
                }
            }
            if (!$hasRopaConnection && $dpia->ropa) {
                $t = $this->makeInfoTable($sec);
                $this->addInfoRow($t, 'ROPA Terhubung', $this->t(($dpia->ropa->registration_number ?? '') . ' - ' . ($dpia->ropa->processing_activity ?? '')));
            } elseif (!$hasRopaConnection) {
                $sec->addText($this->t('Tidak ada ROPA yang terhubung.'), ['size' => 10, 'color' => '888888', 'italic' => true]);
            }

            // 3. Tabel 21 Kategori Risiko
            $this->addSectionTitle($sec, '3. Penilaian Potensi Risiko (21 Kategori)');

            $RISK_CATEGORIES = [
                'Dasar Hukum Pemrosesan', 'Pemrosesan Data Pribadi yang Sah',
                'Kesesuaian Tujuan Pemrosesan', 'Minimisasi Data', 'Keakuratan Data',
                'Pembatasan Penyimpanan', 'Integritas dan Kerahasiaan', 'Akuntabilitas',
                'Hak Subjek Data - Akses', 'Hak Subjek Data - Koreksi',
                'Hak Subjek Data - Hapus', 'Hak Subjek Data - Portabilitas',
                'Persetujuan dan Consent', 'Transfer Data Lintas Batas',
                'Enkripsi dan Pseudonymization', 'Kontrol Akses', 'Monitoring dan Logging',
                'Retensi Data', 'Manajemen Insiden', 'Pelatihan dan Kesadaran',
                'Penilaian Dampak Berkala',
            ];

            $answerLabels = [
                'sudah' => 'Sudah Memenuhi', 'sebagian' => 'Memenuhi Sebagian',
                'belum' => 'Belum Memenuhi', 'tidak_berlaku' => 'Tidak Berlaku',
            ];
            $answerColors = ['sudah' => '22c55e', 'sebagian' => 'f59e0b', 'belum' => 'ef4444', 'tidak_berlaku' => '94a3b8'];

            $riskTable = $sec->addTable(['borderSize' => 4, 'borderColor' => 'e0e0e0', 'cellMargin' => 60]);
            $hRow = $riskTable->addRow();
            $hRow->addCell(800, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('No', ['size' => 8, 'bold' => true, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);
            $hRow->addCell(3500, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('Kategori Risiko', ['size' => 8, 'bold' => true, 'color' => 'ffffff']);
            $hRow->addCell(2000, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('Status', ['size' => 8, 'bold' => true, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);
            $hRow->addCell(3700, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('Deskripsi / Keterangan', ['size' => 8, 'bold' => true, 'color' => 'ffffff']);

            foreach ($RISK_CATEGORIES as $idx => $cat) {
                $r = $risiko[$cat] ?? [];
                $answer = $r['answer'] ?? '-';
                $desc = $r['description'] ?? '-';
                $color = $answerColors[$answer] ?? '333333';
                $label = $answerLabels[$answer] ?? '-';
                $bgColor = ($idx % 2 === 0) ? 'ffffff' : 'f8f9fa';

                $row = $riskTable->addRow();
                $row->addCell(800, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t((string)($idx + 1)), ['size' => 8, 'color' => '666666'], ['alignment' => Jc::CENTER]);
                $row->addCell(3500, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($cat), ['size' => 8, 'bold' => true, 'color' => '333333']);
                $row->addCell(2000, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($label), ['size' => 8, 'bold' => true, 'color' => $color], ['alignment' => Jc::CENTER]);
                $row->addCell(3700, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($desc), ['size' => 8, 'color' => '555555']);
            }

            // 4. Ringkasan
            $sudah = count(array_filter($risiko, fn($r) => ($r['answer'] ?? '') === 'sudah'));
            $sebagian = count(array_filter($risiko, fn($r) => ($r['answer'] ?? '') === 'sebagian'));
            $belum = count(array_filter($risiko, fn($r) => ($r['answer'] ?? '') === 'belum'));
            $tidakBerlaku = count(array_filter($risiko, fn($r) => ($r['answer'] ?? '') === 'tidak_berlaku'));

            $this->addSectionTitle($sec, '4. Ringkasan Penilaian');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Sudah Memenuhi', "$sudah kategori");
            $this->addInfoRow($t, 'Memenuhi Sebagian', "$sebagian kategori");
            $this->addInfoRow($t, 'Belum Memenuhi', "$belum kategori");
            $this->addInfoRow($t, 'Tidak Berlaku', "$tidakBerlaku kategori");

            // 5. Risk Assessment Matrix
            if (!empty($ra['risks']) && is_array($ra['risks'])) {
                $this->addSectionTitle($sec, '5. Risk Assessment Matrix');
                $raTable = $sec->addTable(['borderSize' => 4, 'borderColor' => 'e0e0e0', 'cellMargin' => 60]);
                $h = $raTable->addRow();
                foreach (['Risiko', 'Likelihood', 'Impact', 'Score', 'Mitigasi', 'Status'] as $hdr) {
                    $h->addCell(1667, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                        ->addText($hdr, ['size' => 8, 'bold' => true, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);
                }
                foreach ($ra['risks'] as $risk) {
                    $row = $raTable->addRow();
                    $row->addCell(1667, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t($risk['risk'] ?? '-'), ['size' => 8]);
                    $row->addCell(1667, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t((string)($risk['likelihood'] ?? '-')), ['size' => 8], ['alignment' => Jc::CENTER]);
                    $row->addCell(1667, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t((string)($risk['impact'] ?? '-')), ['size' => 8], ['alignment' => Jc::CENTER]);
                    $score = (int)($risk['likelihood'] ?? 0) * (int)($risk['impact'] ?? 0);
                    $row->addCell(1667, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t((string)$score), ['size' => 8, 'bold' => true], ['alignment' => Jc::CENTER]);
                    $row->addCell(1667, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t($risk['mitigation'] ?? '-'), ['size' => 8]);
                    $row->addCell(1667, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])->addText($this->t($risk['status'] ?? '-'), ['size' => 8], ['alignment' => Jc::CENTER]);
                }
            }

            // 6. Status Dokumen
            $this->addSectionTitle($sec, 'Status Dokumen');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Status', strtoupper($dpia->status ?? '-'));
            $this->addInfoRow($t, 'Progress', ($dpia->progress ?? 0) . '%');
            $this->addInfoRow($t, 'Dibuat', $dpia->created_at ? $dpia->created_at->format('d F Y H:i') : '-');
            $this->addInfoRow($t, 'Terakhir Diupdate', $dpia->updated_at ? $dpia->updated_at->format('d F Y H:i') : '-');

            $outputFileName = 'DPIA_' . ($dpia->registration_number ?? 'export') . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'dpia_');
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);

            while (ob_get_level() > 0) { ob_end_clean(); }

            return response()->download($tempFile, $outputFileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    // ================================================================
    //  GAP ASSESSMENT EXPORT
    // ================================================================
    public function exportGap($id)
    {
        $gap = GapAssessment::where('org_id', auth()->user()->org_id)->findOrFail($id);
        $templatePath = storage_path('app/templates/gap-template.xls');

        // Fallback to a blank spreadsheet if template is missing
        $spreadsheet = file_exists($templatePath) 
            ? \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath)
            : new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Gap Analysis');

        // Styles
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];

        // 1. Metadata Header
        $sheet->setCellValue('A1', 'PRIVASIMU NEXUS - GAP ASSESSMENT REPORT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        
        $sheet->setCellValue('A3', 'Version:'); $sheet->setCellValue('B3', $gap->version);
        $sheet->setCellValue('A4', 'Compliance Score:'); $sheet->setCellValue('B4', $gap->overall_score . '%');
        $sheet->setCellValue('A5', 'Compliance Level:'); $sheet->setCellValue('B5', strtoupper($gap->compliance_level));
        $sheet->setCellValue('A6', 'Date Generated:'); $sheet->setCellValue('B6', now()->format('d M Y H:i'));
        $sheet->setCellValue('A7', 'Framework:'); $sheet->setCellValue('B7', $gap->regulation_code ?: 'UU PDP');

        // 2. Category Breakdown
        $sheet->setCellValue('A9', 'Category Breakdown');
        $sheet->getStyle('A9')->getFont()->setBold(true);
        
        $row = 10;
        $scoreData = GapAssessment::calculateScore($gap->answers ?: [], $gap->regulation_code ?: 'uupdp');
        foreach ($scoreData['category_breakdown'] as $cat => $score) {
            $sheet->setCellValue('A' . $row, $cat);
            $sheet->setCellValue('B' . $row, $score . '%');
            $row++;
        }

        // 3. Detailed Results Table
        $startRow = $row + 2;
        $sheet->setCellValue('A' . $startRow, 'ID');
        $sheet->setCellValue('B' . $startRow, 'Category');
        $sheet->setCellValue('C' . $startRow, 'Question');
        $sheet->setCellValue('D' . $startRow, 'Answer');
        $sheet->setCellValue('E' . $startRow, 'Recommendation');
        
        $sheet->getStyle("A{$startRow}:E{$startRow}")->applyFromArray($headerStyle);
        
        $questions = GapAssessment::getQuestionBank($gap->regulation_code ?: 'uupdp');
        $currentRow = $startRow + 1;
        
        foreach ($questions as $q) {
            $ansCode = $gap->answers[$q['id']] ?? 'n/a';
            $ansLabel = match($ansCode) {
                'yes' => 'Sudah Memenuhi',
                'partial' => 'Memenuhi Sebagian',
                'no' => 'Belum Memenuhi',
                'na' => 'N/A',
                default => 'Belum Dijawab'
            };
            
            $sheet->setCellValue('A' . $currentRow, $q['id']);
            $sheet->setCellValue('B' . $currentRow, $q['category']);
            $sheet->setCellValue('C' . $currentRow, $q['question']);
            $sheet->setCellValue('D' . $currentRow, $ansLabel);
            
            // Set color for answer
            $color = match($ansCode) {
                'yes' => '22C55E',
                'partial' => 'F59E0B',
                'no' => 'EF4444',
                default => '94A3B8'
            };
            $sheet->getStyle('D' . $currentRow)->getFont()->getColor()->setRGB($color);
            $sheet->getStyle('D' . $currentRow)->getFont()->setBold(true);

            // Recommendation (only if not 'yes')
            if ($ansCode !== 'yes' && $ansCode !== 'na') {
                $sheet->setCellValue('E' . $currentRow, $q['recommendation']);
            }
            
            $currentRow++;
        }

        // Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Limit width for Question and Recommendation
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(50);
        $sheet->getColumnDimension('E')->setAutoSize(false)->setWidth(60);
        $sheet->getStyle("C1:E{$currentRow}")->getAlignment()->setWrapText(true);

        try {
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xls');
            $outputFileName = 'Gap_Assessment_' . str_replace(' ', '_', $gap->version) . '.xls';
            $tempFile = tempnam(sys_get_temp_dir(), 'phpxls');
            $writer->save($tempFile);

            while (ob_get_level() > 0) { ob_end_clean(); }

            return response()->download($tempFile, $outputFileName, [
                'Content-Type' => 'application/vnd.ms-excel',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal export: ' . $e->getMessage()], 500);
        }
    }

    // ================================================================
    //  GAP ASSESSMENT — DOCX REPORT (Sprint B3 Step 5)
    // ================================================================
    public function exportGapReport($id)
    {
        $gap = GapAssessment::where('org_id', auth()->user()->org_id)->findOrFail($id);

        $outputFileName = 'Gap_Assessment_Report_' . str_replace(' ', '_', $gap->version ?? 'export') . '.docx';
        if ($resp = $this->tryRenderFromTenantTemplate('gap', $gap, $outputFileName)) return $resp;

        try {
            $phpWord = new PhpWord();
            $this->applyDefaultFont($phpWord);
            $orgMeta = $this->resolveOrgMeta(auth()->user()->organization);
            $regCode = $gap->regulation_code ?? 'uupdp';
            $regName = \App\Models\RegulationFramework::where('code', $regCode)->value('name') ?? 'UU No. 27 Tahun 2022 (UU PDP)';
            $scoreData = GapAssessment::calculateScore($gap->answers ?: [], $regCode);

            // Cover Page
            $this->addCoverPage(
                $phpWord, 'Gap Assessment Report',
                $gap->version ?? 'Untitled',
                $regName,
                $gap->compliance_level ?? 'low',
                $gap->compliance_level ?? 'low',
                $orgMeta
            );

            // Content Page
            $sec = $this->addContentSection($phpWord, 'GAP ASSESSMENT · ' . ($gap->version ?? ''), $orgMeta);

            // 1. Informasi Assessment
            $this->addSectionTitle($sec, '1. Informasi Assessment');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Versi Assessment', $gap->version ?? '-');
            $this->addInfoRow($t, 'Organisasi', $orgMeta['name'] ?? '-');
            $this->addInfoRow($t, 'Framework Regulasi', $regName);
            $this->addInfoRow($t, 'Skor Kepatuhan', round($gap->overall_score ?? 0) . '%');
            $this->addInfoRow($t, 'Level Kepatuhan', strtoupper($gap->compliance_level ?? '-'));
            $this->addInfoRow($t, 'Progress', ($gap->progress ?? 0) . '%');
            $this->addInfoRow($t, 'Tanggal Dibuat', $gap->created_at ? $gap->created_at->format('d F Y H:i') : '-');
            $this->addInfoRow($t, 'Terakhir Diupdate', $gap->updated_at ? $gap->updated_at->format('d F Y H:i') : '-');

            // 2. Ringkasan Skor per Kategori
            $this->addSectionTitle($sec, '2. Skor per Kategori');
            $catTable = $sec->addTable(['borderSize' => 4, 'borderColor' => 'e0e0e0', 'cellMargin' => 80]);
            $hRow = $catTable->addRow();
            $hRow->addCell(6000, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('Kategori', ['size' => 9, 'bold' => true, 'color' => 'ffffff']);
            $hRow->addCell(2000, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('Skor', ['size' => 9, 'bold' => true, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);
            $hRow->addCell(2000, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                ->addText('Status', ['size' => 9, 'bold' => true, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);

            foreach ($scoreData['category_breakdown'] as $cat => $catScore) {
                $scoreColor = $catScore >= 70 ? '22c55e' : ($catScore >= 40 ? 'f59e0b' : 'ef4444');
                $statusLabel = $catScore >= 70 ? 'COMPLY' : ($catScore >= 40 ? 'SEBAGIAN' : 'BELUM COMPLY');
                $row = $catTable->addRow();
                $row->addCell(6000, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($cat), ['size' => 9, 'bold' => true]);
                $row->addCell(2000, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t(round($catScore) . '%'), ['size' => 9, 'bold' => true, 'color' => $scoreColor], ['alignment' => Jc::CENTER]);
                $row->addCell(2000, ['borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($statusLabel), ['size' => 8, 'bold' => true, 'color' => $scoreColor], ['alignment' => Jc::CENTER]);
            }

            // 3. Detail Pertanyaan & Jawaban
            $this->addSectionTitle($sec, '3. Detail Jawaban per Pertanyaan');

            $questions = GapAssessment::getQuestionBank($regCode);
            $answerLabels = [
                'yes' => ['label' => 'Sudah Memenuhi', 'color' => '22c55e'],
                'partial' => ['label' => 'Memenuhi Sebagian', 'color' => 'f59e0b'],
                'no' => ['label' => 'Belum Memenuhi', 'color' => 'ef4444'],
                'na' => ['label' => 'N/A', 'color' => self::GRAY],
            ];

            $detailTable = $sec->addTable(['borderSize' => 4, 'borderColor' => 'e0e0e0', 'cellMargin' => 60]);
            $dh = $detailTable->addRow();
            foreach (['ID', 'Kategori', 'Pertanyaan', 'Jawaban', 'Rekomendasi'] as $h) {
                $w = match($h) {
                    'ID' => 800, 'Kategori' => 1500, 'Pertanyaan' => 3500, 'Jawaban' => 1600, 'Rekomendasi' => 2600,
                    default => 2000,
                };
                $dh->addCell($w, ['bgColor' => '6366f1', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($h, ['size' => 8, 'bold' => true, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);
            }

            foreach ($questions as $idx => $q) {
                $ansCode = $gap->answers[$q['id']] ?? 'n/a';
                $ansInfo = $answerLabels[$ansCode] ?? ['label' => 'Belum Dijawab', 'color' => self::GRAY];
                $bgColor = ($idx % 2 === 0) ? 'ffffff' : 'f8f9fa';

                $row = $detailTable->addRow();
                $row->addCell(800, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($q['id']), ['size' => 7, 'color' => '666666'], ['alignment' => Jc::CENTER]);
                $row->addCell(1500, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($q['category']), ['size' => 7, 'bold' => true]);
                $row->addCell(3500, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($q['question']), ['size' => 7]);
                $row->addCell(1600, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t($ansInfo['label']), ['size' => 7, 'bold' => true, 'color' => $ansInfo['color']], ['alignment' => Jc::CENTER]);
                $row->addCell(2600, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                    ->addText($this->t(($ansCode !== 'yes' && $ansCode !== 'na') ? ($q['recommendation'] ?? '-') : '-'), ['size' => 7, 'color' => '555555']);
            }

            // 4. Rekomendasi Prioritas
            $recs = $scoreData['recommendations'] ?? [];
            if (count($recs) > 0) {
                $this->addSectionTitle($sec, '4. Rekomendasi Prioritas');
                $t = $this->makeInfoTable($sec);
                $critCount = count(array_filter($recs, fn($r) => $r['priority'] === 'critical'));
                $highCount = count(array_filter($recs, fn($r) => $r['priority'] === 'high'));
                $medCount = count(array_filter($recs, fn($r) => $r['priority'] === 'medium'));
                $this->addInfoRow($t, 'Total Rekomendasi', (string)count($recs));
                $this->addInfoRow($t, 'Critical', "$critCount item");
                $this->addInfoRow($t, 'High', "$highCount item");
                $this->addInfoRow($t, 'Medium', "$medCount item");

                $recTable = $sec->addTable(['borderSize' => 4, 'borderColor' => 'e0e0e0', 'cellMargin' => 60]);
                $rh = $recTable->addRow();
                foreach (['Prioritas', 'Artikel', 'Pertanyaan', 'Rekomendasi'] as $h) {
                    $w = match($h) { 'Prioritas' => 1200, 'Artikel' => 1300, 'Pertanyaan' => 3500, 'Rekomendasi' => 4000, default => 2500 };
                    $rh->addCell($w, ['bgColor' => 'ef4444', 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                        ->addText($h, ['size' => 8, 'bold' => true, 'color' => 'ffffff']);
                }

                foreach ($recs as $idx => $rec) {
                    $pColor = match($rec['priority']) { 'critical' => 'ef4444', 'high' => 'f59e0b', default => '3b82f6' };
                    $bgColor = ($idx % 2 === 0) ? 'ffffff' : 'fff5f5';
                    $row = $recTable->addRow();
                    $row->addCell(1200, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                        ->addText($this->t(strtoupper($rec['priority'])), ['size' => 8, 'bold' => true, 'color' => $pColor], ['alignment' => Jc::CENTER]);
                    $row->addCell(1300, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                        ->addText($this->t($rec['article'] ?? '-'), ['size' => 8]);
                    $row->addCell(3500, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                        ->addText($this->t($rec['question'] ?? '-'), ['size' => 8]);
                    $row->addCell(4000, ['bgColor' => $bgColor, 'borderSize' => 4, 'borderColor' => 'e0e0e0'])
                        ->addText($this->t($rec['recommendation'] ?? '-'), ['size' => 8]);
                }
            }

            // Status Dokumen
            $this->addSectionTitle($sec, 'Status Dokumen');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Status', strtoupper($gap->compliance_level ?? '-'));
            $this->addInfoRow($t, 'Digenerate Pada', now()->format('d F Y H:i'));

            $outputFileName = 'Gap_Assessment_Report_' . str_replace(' ', '_', $gap->version ?? 'export') . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'gap_report_');
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);

            while (ob_get_level() > 0) { ob_end_clean(); }

            return response()->download($tempFile, $outputFileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    // ================================================================
    //  CONSOLIDATED COMPLIANCE REPORT (all modules summary → DOCX)
    // ================================================================

    /**
     * Render a 4-column stat card row. `$stats` is [['label','value','color'], ...]
     */
    private function addStatGrid($section, array $stats): void
    {
        $t = $section->addTable(['cellMargin' => 120, 'width' => 100 * 50, 'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT]);
        $colCount = min(4, max(1, count($stats)));
        $cellWidth = (int) floor(100 * 50 / $colCount);

        for ($i = 0; $i < count($stats); $i += $colCount) {
            $row = $t->addRow(null, ['cantSplit' => true]);
            for ($j = 0; $j < $colCount; $j++) {
                $s = $stats[$i + $j] ?? null;
                if ($s === null) { $row->addCell($cellWidth); continue; }
                $cell = $row->addCell($cellWidth, [
                    'bgColor' => self::LAV, 'valign' => 'center',
                    'borderTopSize' => 24, 'borderTopColor' => $s['color'] ?? self::NAVY,
                ]);
                $cell->addText(strtoupper($s['label'] ?? ''), [
                    'size' => 8, 'color' => self::GRAY, 'bold' => true,
                ], ['alignment' => Jc::CENTER, 'spaceBefore' => 120, 'spaceAfter' => 60]);
                $cell->addText($this->t((string) ($s['value'] ?? '0')), [
                    'size' => 22, 'color' => self::NAVY, 'bold' => true, 'name' => self::HEAD_FONT,
                ], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
            }
        }
    }

    /**
     * Render a simple breakdown as key/value rows styled as chips.
     * `$items` is ['label' => count, ...]
     */
    private function addBreakdown($section, array $items, ?string $emptyMessage = null): void
    {
        if (empty($items)) {
            $section->addText($this->t($emptyMessage ?? 'Belum ada data.'),
                ['size' => 10, 'italic' => true, 'color' => self::GRAY],
                ['spaceBefore' => 100, 'spaceAfter' => 100]);
            return;
        }
        $t = $this->makeInfoTable($section);
        foreach ($items as $label => $count) {
            $this->addInfoRow($t, (string) $label, (string) $count);
        }
    }

    public function exportComplianceReport(Request $request)
    {
        try {
            $user = auth()->user();
            $orgMeta = $this->resolveOrgMeta($user->organization);
            $orgId = $user->org_id;

            // Same queries as ExportController::complianceReport, scoped to org.
            $q = fn ($cls) => $cls::where('org_id', $orgId);

            $ropaTotal      = $q(\App\Models\Ropa::class)->whereNull('deleted_at')->count();
            $ropaByStatus   = $q(\App\Models\Ropa::class)->whereNull('deleted_at')->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->toArray();
            $ropaByRisk     = $q(\App\Models\Ropa::class)->whereNull('deleted_at')->select('risk_level', DB::raw('count(*) as c'))->groupBy('risk_level')->pluck('c', 'risk_level')->toArray();

            $dpiaTotal      = $q(\App\Models\Dpia::class)->whereNull('deleted_at')->count();
            $dpiaByStatus   = $q(\App\Models\Dpia::class)->whereNull('deleted_at')->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->toArray();
            $dpiaApproved   = $q(\App\Models\Dpia::class)->whereNull('deleted_at')->where('status', 'approved')->count();

            $breachTotal    = $q(\App\Models\BreachIncident::class)->whereNull('deleted_at')->where('is_simulation', false)->count();
            $breachActive   = $q(\App\Models\BreachIncident::class)->whereNull('deleted_at')->where('is_simulation', false)->whereNotIn('status', ['closed'])->count();
            $breachBySev    = $q(\App\Models\BreachIncident::class)->whereNull('deleted_at')->where('is_simulation', false)->select('severity', DB::raw('count(*) as c'))->groupBy('severity')->pluck('c', 'severity')->toArray();

            $dsrTotal       = $q(\App\Models\DsrRequest::class)->whereNull('deleted_at')->count();
            $dsrByStatus    = $q(\App\Models\DsrRequest::class)->whereNull('deleted_at')->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->toArray();
            $dsrByType      = $q(\App\Models\DsrRequest::class)->whereNull('deleted_at')->select('request_type', DB::raw('count(*) as c'))->groupBy('request_type')->pluck('c', 'request_type')->toArray();

            $consentPoints  = $q(\App\Models\ConsentCollectionPoint::class)->whereNull('deleted_at')->count();

            $discoveryTotal = $q(\App\Models\InformationSystem::class)->whereNull('deleted_at')->count();
            $discoveryScanned = $q(\App\Models\InformationSystem::class)->whereNull('deleted_at')->where('scanning_status', 'done')->count();
            $pdpAlerts      = (int) $q(\App\Models\InformationSystem::class)->whereNull('deleted_at')->sum('pdp_alert_count');
            $piiAlerts      = (int) $q(\App\Models\InformationSystem::class)->whereNull('deleted_at')->sum('pii_alert_count');

            // Prefer the most recent COMPLETED assessment (progress=100). If
            // none is completed yet, fall back to the latest draft so the
            // report still shows something — but label it as draft.
            $latestCompleted = $q(\App\Models\GapAssessment::class)->whereNull('deleted_at')
                ->where('progress', 100)->latest()->first();
            $latestAny = $q(\App\Models\GapAssessment::class)->whereNull('deleted_at')->latest()->first();
            $latestGap = $latestCompleted ?: $latestAny;
            $gapScore = $latestGap->overall_score ?? $latestGap->score ?? 0;
            $gapLevel = $latestGap->compliance_level ?? '-';
            $gapVersion = $latestGap
                ? ($latestGap->version ?? '-') . ($latestCompleted ? '' : ' (draft — ' . (int) ($latestGap->progress ?? 0) . '%)')
                : '-';

            $aiTotal        = \App\Models\AiResult::where('org_id', $orgId)->count();

            $phpWord = new PhpWord();
            $this->applyDefaultFont($phpWord);

            $this->addCoverPage(
                $phpWord,
                'Consolidated Compliance Report',
                'Privacy Compliance Snapshot',
                now()->format('Y-m-d'),
                'final',
                strtolower($gapLevel),
                $orgMeta
            );

            $sec = $this->addContentSection($phpWord, 'Compliance Report · ' . now()->format('d M Y'), $orgMeta);

            // Executive summary
            $this->addSectionTitle($sec, '1. Executive Summary');
            $sec->addText('Ringkasan status kepatuhan PDP organisasi pada saat laporan ini digenerate. Angka mencerminkan seluruh data aktif (tidak termasuk yang terhapus).',
                ['size' => 10, 'color' => '475569'], ['spaceBefore' => 0, 'spaceAfter' => 160]);

            $this->addStatGrid($sec, [
                ['label' => 'ROPA Records',      'value' => $ropaTotal,      'color' => self::NAVY],
                ['label' => 'DPIA Records',      'value' => $dpiaTotal,      'color' => '8b5cf6'],
                ['label' => 'Active Breaches',   'value' => $breachActive,   'color' => $breachActive > 0 ? 'dc2626' : '16a34a'],
                ['label' => 'Open DSR',          'value' => ($dsrByStatus['new'] ?? 0) + ($dsrByStatus['in_progress'] ?? 0), 'color' => 'f59e0b'],
            ]);
            $sec->addTextBreak(1);
            $this->addStatGrid($sec, [
                ['label' => 'Gap Score',         'value' => round((float) $gapScore) . '%', 'color' => $this->riskColor($gapLevel)],
                ['label' => 'Consent Points',    'value' => $consentPoints,                 'color' => '06b6d4'],
                ['label' => 'Systems Scanned',   'value' => $discoveryScanned . ' / ' . $discoveryTotal, 'color' => '0ea5e9'],
                ['label' => 'PDP / PII Alerts',  'value' => $pdpAlerts + $piiAlerts,        'color' => ($pdpAlerts + $piiAlerts) > 0 ? 'ea580c' : '16a34a'],
            ]);

            // ROPA
            $this->addSectionTitle($sec, '2. Record of Processing Activities (ROPA)');
            $sec->addText('Status distribusi + sebaran risiko untuk ROPA aktif.',
                ['size' => 10, 'color' => '475569'], ['spaceAfter' => 120]);
            $sec->addText('Breakdown by Status', ['size' => 11, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT], ['spaceBefore' => 60, 'spaceAfter' => 60]);
            $this->addBreakdown($sec, $ropaByStatus, 'Belum ada ROPA.');
            $sec->addText('Breakdown by Risk Level', ['size' => 11, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT], ['spaceBefore' => 120, 'spaceAfter' => 60]);
            $this->addBreakdown($sec, $ropaByRisk, 'Belum ada data risk.');

            // DPIA
            $this->addSectionTitle($sec, '3. Data Protection Impact Assessment (DPIA)');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Total DPIA', (string) $dpiaTotal);
            $this->addInfoRow($t, 'Approved', (string) $dpiaApproved);
            $sec->addText('Breakdown by Status', ['size' => 11, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT], ['spaceBefore' => 120, 'spaceAfter' => 60]);
            $this->addBreakdown($sec, $dpiaByStatus, 'Belum ada DPIA.');

            // Breach
            $this->addSectionTitle($sec, '4. Data Breach Management');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Total Insiden', (string) $breachTotal);
            $this->addInfoRow($t, 'Insiden Aktif (belum closed)', (string) $breachActive);
            $sec->addText('Breakdown by Severity', ['size' => 11, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT], ['spaceBefore' => 120, 'spaceAfter' => 60]);
            $this->addBreakdown($sec, $breachBySev, 'Tidak ada insiden tercatat.');

            // DSR
            $this->addSectionTitle($sec, '5. Data Subject Rights (DSR)');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Total Permintaan', (string) $dsrTotal);
            $sec->addText('Breakdown by Status', ['size' => 11, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT], ['spaceBefore' => 120, 'spaceAfter' => 60]);
            $this->addBreakdown($sec, $dsrByStatus, 'Belum ada permintaan DSR.');
            $sec->addText('Breakdown by Type', ['size' => 11, 'bold' => true, 'color' => self::NAVY, 'name' => self::HEAD_FONT], ['spaceBefore' => 120, 'spaceAfter' => 60]);
            $this->addBreakdown($sec, $dsrByType, '—');

            // Consent + Data Discovery
            $this->addSectionTitle($sec, '6. Consent & Data Discovery');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Consent Collection Points', (string) $consentPoints);
            $this->addInfoRow($t, 'Systems Registered', (string) $discoveryTotal);
            $this->addInfoRow($t, 'Systems Scanned (done)', (string) $discoveryScanned);
            $this->addInfoRow($t, 'PDP Alerts (total)', (string) $pdpAlerts);
            $this->addInfoRow($t, 'PII Alerts (total)', (string) $piiAlerts);

            // Gap + AI
            $this->addSectionTitle($sec, '7. Gap Assessment & AI Usage');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Latest Gap Version', (string) $gapVersion);
            $this->addInfoRow($t, 'Overall Score', round((float) $gapScore) . '%');
            $this->addInfoRow($t, 'Compliance Level', strtoupper((string) $gapLevel));
            $this->addInfoRow($t, 'AI Analysis Runs (total)', (string) $aiTotal);

            // Meta
            $this->addSectionTitle($sec, '8. Report Metadata');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Report Generated', now()->format('d F Y H:i') . ' WIB');
            $this->addInfoRow($t, 'Generated By', $user->name ?? '-');
            $this->addInfoRow($t, 'Organization', $orgMeta['name'] ?? '-');
            $this->addInfoRow($t, 'DPO', ($orgMeta['dpo_name'] ?? '—') . (isset($orgMeta['dpo_email']) && $orgMeta['dpo_email'] ? ' · ' . $orgMeta['dpo_email'] : ''));

            $safeOrgSlug = preg_replace('/[^a-z0-9]+/i', '_', $orgMeta['name'] ?? 'org') ?: 'org';
            $outputFileName = 'Compliance_Report_' . $safeOrgSlug . '_' . now()->format('Ymd') . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'compliance_');
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);

            while (ob_get_level() > 0) { ob_end_clean(); }

            return response()->download($tempFile, $outputFileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }
}
