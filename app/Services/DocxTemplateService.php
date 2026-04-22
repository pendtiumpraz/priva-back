<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\Dpia;
use App\Models\GapAssessment;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Renders tenant-uploaded .docx templates via PhpWord TemplateProcessor.
 *
 * Placeholder conventions:
 *   - Scalars: `${field_name}` (e.g. `${ropa_name}`, `${org_name}`)
 *   - Tables (clone row): surround table rows with `${#loop_name}` / `${/loop_name}`
 *     pattern. TemplateProcessor's `cloneRow(placeholder, rowCount)` is used.
 *
 * Returns a local tmp file path — caller downloads + unlinks.
 */
class DocxTemplateService
{
    public function __construct(private TenantStorageService $storage) {}

    /**
     * Render ROPA into tenant's uploaded .docx template.
     * Returns absolute tmp file path (docx). Caller must unlink after download.
     */
    public function renderRopa(Ropa $ropa, DocumentTemplate $tpl, Organization $org): string
    {
        $templatePath = $this->resolveTemplatePath($tpl, 'ropa');
        [$localTpl, $cleanup] = $this->storage->getLocalPathForProcessing($org, $templatePath);

        try {
            $proc = new TemplateProcessor($localTpl);
            $data = $this->ropaPlaceholders($ropa, $org);

            foreach ($data['scalars'] as $k => $v) {
                $proc->setValue($k, $this->safe($v));
            }

            // Simple arrays — clone blocks or leave as concatenated text.
            foreach ($data['lists'] as $k => $items) {
                $proc->setValue($k, implode(', ', array_map([$this, 'safe'], $items)));
            }

            $out = tempnam(sys_get_temp_dir(), 'ropa_') . '.docx';
            $proc->saveAs($out);
            return $out;
        } finally {
            $cleanup();
        }
    }

    public function renderDpia(Dpia $dpia, DocumentTemplate $tpl, Organization $org): string
    {
        $templatePath = $this->resolveTemplatePath($tpl, 'dpia');
        [$localTpl, $cleanup] = $this->storage->getLocalPathForProcessing($org, $templatePath);

        try {
            $proc = new TemplateProcessor($localTpl);
            $data = $this->dpiaPlaceholders($dpia, $org);
            foreach ($data['scalars'] as $k => $v) {
                $proc->setValue($k, $this->safe($v));
            }
            foreach ($data['lists'] as $k => $items) {
                $proc->setValue($k, implode(', ', array_map([$this, 'safe'], $items)));
            }
            $out = tempnam(sys_get_temp_dir(), 'dpia_') . '.docx';
            $proc->saveAs($out);
            return $out;
        } finally {
            $cleanup();
        }
    }

    public function renderGap(GapAssessment $gap, DocumentTemplate $tpl, Organization $org): string
    {
        $templatePath = $this->resolveTemplatePath($tpl, 'gap');
        [$localTpl, $cleanup] = $this->storage->getLocalPathForProcessing($org, $templatePath);

        try {
            $proc = new TemplateProcessor($localTpl);
            $data = $this->gapPlaceholders($gap, $org);
            foreach ($data['scalars'] as $k => $v) {
                $proc->setValue($k, $this->safe($v));
            }
            foreach ($data['lists'] as $k => $items) {
                $proc->setValue($k, implode(', ', array_map([$this, 'safe'], $items)));
            }
            $out = tempnam(sys_get_temp_dir(), 'gap_') . '.docx';
            $proc->saveAs($out);
            return $out;
        } finally {
            $cleanup();
        }
    }

    /**
     * Catalog of placeholder variables per export kind — returned by API
     * so the UI can show available tokens.
     */
    public static function placeholderCatalog(): array
    {
        return [
            'ropa' => [
                'scalars' => [
                    'ropa_name' => 'Nama aktivitas pemrosesan',
                    'registration_number' => 'Nomor registrasi (ROPA-YYYY-NNN)',
                    'processing_activity' => 'Deskripsi aktivitas',
                    'processing_purpose' => 'Tujuan pemrosesan',
                    'legal_basis' => 'Dasar hukum (consent, contract, legal_obligation, dst)',
                    'risk' => 'Tingkat risiko (LOW/MEDIUM/HIGH)',
                    'data_controller' => 'Nama pengendali data',
                    'data_processor' => 'Nama pemroses data',
                    'retention_period' => 'Periode retensi',
                    'security_measures' => 'Langkah keamanan (ringkas)',
                    'dpo_name' => 'Nama DPO',
                    'dpo_email' => 'Email DPO',
                    'status' => 'Status dokumen (draft/approved/archived)',
                    'created_at' => 'Tanggal dibuat',
                    'org_name' => 'Nama organisasi',
                    'org_address' => 'Alamat organisasi',
                    'today' => 'Tanggal hari ini (format Indonesia)',
                ],
                'lists' => [
                    'data_categories' => 'Kategori data (comma-separated)',
                    'data_subjects' => 'Subjek data',
                    'data_recipients' => 'Penerima data',
                    'sensitive_categories' => 'Kategori sensitif',
                ],
            ],
            'dpia' => [
                'scalars' => [
                    'dpia_name' => 'Nama DPIA',
                    'registration_number' => 'Nomor registrasi (DPIA-YYYY-NNN)',
                    'linked_ropa_name' => 'Nama ROPA terkait',
                    'risk_level' => 'Tingkat risiko akhir',
                    'necessity_assessment' => 'Assessment necessity',
                    'proportionality' => 'Proportionality',
                    'residual_risk' => 'Residual risk',
                    'mitigation_plan' => 'Rencana mitigasi',
                    'status' => 'Status dokumen',
                    'org_name' => 'Nama organisasi',
                    'today' => 'Tanggal hari ini',
                ],
                'lists' => [
                    'risk_categories' => 'Kategori risiko yang dinilai',
                    'mitigations' => 'Mitigasi yang diterapkan',
                ],
            ],
            'gap' => [
                'scalars' => [
                    'version' => 'Versi GAP',
                    'overall_score' => 'Skor keseluruhan',
                    'maturity_level' => 'Tingkat maturity',
                    'assessment_date' => 'Tanggal assessment',
                    'status' => 'Status dokumen',
                    'org_name' => 'Nama organisasi',
                    'today' => 'Tanggal hari ini',
                    'assessor_name' => 'Nama assessor',
                    'total_questions' => 'Jumlah pertanyaan',
                    'compliant_count' => 'Compliant',
                    'partial_count' => 'Partial',
                    'noncompliant_count' => 'Non-compliant',
                ],
                'lists' => [
                    'categories' => 'Kategori yang dinilai',
                    'priority_recommendations' => 'Rekomendasi prioritas',
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------

    private function resolveTemplatePath(DocumentTemplate $tpl, string $kind): string
    {
        $map = $tpl->docx_templates ?? [];
        if (empty($map[$kind]['path'])) {
            throw new \RuntimeException("Template DOCX untuk {$kind} belum di-upload.");
        }
        return $map[$kind]['path'];
    }

    private function ropaPlaceholders(Ropa $r, Organization $org): array
    {
        $data = [
            'scalars' => [
                'ropa_name' => $r->name ?? '',
                'registration_number' => $r->registration_number ?? '',
                'processing_activity' => $r->processing_activity ?? $r->activity ?? '',
                'processing_purpose' => $r->processing_purpose ?? $r->purpose ?? '',
                'legal_basis' => $r->legal_basis ?? '',
                'risk' => strtoupper($r->risk ?? ''),
                'data_controller' => $r->data_controller ?? '',
                'data_processor' => $r->data_processor ?? '',
                'retention_period' => $r->retention_period ?? '',
                'security_measures' => is_array($r->security_measures)
                    ? implode(', ', $r->security_measures)
                    : ($r->security_measures ?? ''),
                'dpo_name' => $r->dpo_name ?? '',
                'dpo_email' => $r->dpo_email ?? '',
                'status' => $r->status ?? '',
                'created_at' => optional($r->created_at)->isoFormat('D MMMM Y') ?? '',
                'org_name' => $org->name ?? '',
                'org_address' => $org->address ?? '',
                'today' => now()->locale('id')->isoFormat('D MMMM Y'),
            ],
            'lists' => [
                'data_categories' => $this->arr($r->data_categories),
                'data_subjects' => $this->arr($r->data_subjects),
                'data_recipients' => $this->arr($r->data_recipients),
                'sensitive_categories' => $this->arr($r->sensitive_categories ?? $r->sensitive_data_categories ?? []),
            ],
        ];
        return $data;
    }

    private function dpiaPlaceholders(Dpia $d, Organization $org): array
    {
        return [
            'scalars' => [
                'dpia_name' => $d->name ?? '',
                'registration_number' => $d->registration_number ?? '',
                'linked_ropa_name' => optional($d->ropa)->name ?? '',
                'risk_level' => strtoupper($d->risk_level ?? $d->risk ?? ''),
                'necessity_assessment' => $d->necessity_assessment ?? '',
                'proportionality' => $d->proportionality ?? '',
                'residual_risk' => $d->residual_risk ?? '',
                'mitigation_plan' => is_array($d->mitigation_plan)
                    ? implode("\n", $d->mitigation_plan)
                    : ($d->mitigation_plan ?? ''),
                'status' => $d->status ?? '',
                'org_name' => $org->name ?? '',
                'today' => now()->locale('id')->isoFormat('D MMMM Y'),
            ],
            'lists' => [
                'risk_categories' => $this->arr($d->risk_categories ?? []),
                'mitigations' => $this->arr($d->mitigations ?? []),
            ],
        ];
    }

    private function gapPlaceholders(GapAssessment $g, Organization $org): array
    {
        $answers = is_array($g->answers) ? $g->answers : [];
        $compliant = $partial = $non = 0;
        foreach ($answers as $a) {
            $lvl = is_array($a) ? ($a['level'] ?? null) : null;
            if ($lvl === 'compliant') $compliant++;
            elseif ($lvl === 'partial') $partial++;
            elseif ($lvl === 'non_compliant' || $lvl === 'noncompliant') $non++;
        }

        return [
            'scalars' => [
                'version' => $g->version ?? '',
                'overall_score' => (string)($g->overall_score ?? ''),
                'maturity_level' => $g->maturity_level ?? '',
                'assessment_date' => optional($g->assessment_date)->isoFormat('D MMMM Y') ?? '',
                'status' => $g->status ?? '',
                'org_name' => $org->name ?? '',
                'today' => now()->locale('id')->isoFormat('D MMMM Y'),
                'assessor_name' => $g->assessor_name ?? optional($g->creator)->name ?? '',
                'total_questions' => (string)count($answers),
                'compliant_count' => (string)$compliant,
                'partial_count' => (string)$partial,
                'noncompliant_count' => (string)$non,
            ],
            'lists' => [
                'categories' => $this->arr($g->categories ?? []),
                'priority_recommendations' => $this->arr($g->priority_recommendations ?? []),
            ],
        ];
    }

    private function arr($v): array
    {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v), fn($x) => $x !== ''));
        if (is_string($v) && $v !== '') return [$v];
        return [];
    }

    private function safe($v): string
    {
        return htmlspecialchars((string) $v, ENT_COMPAT, 'UTF-8');
    }
}
