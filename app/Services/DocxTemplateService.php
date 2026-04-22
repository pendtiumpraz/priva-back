<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\Dpia;
use App\Models\GapAssessment;
use App\Models\User;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Renders tenant-uploaded .docx templates via PhpWord TemplateProcessor.
 *
 * Two canonical templates drive the schema (ROPA_DATA_EXPORT.docx and
 * DPIA_DATA_EXPORT.docx). The service supports three PhpWord patterns:
 *
 *   - setValue("key", string)              → flat scalar
 *   - cloneRowAndSetValues("row_marker",   → repeat a table row N times
 *       [[field=>val, ...], ...])
 *   - cloneBlock("block_name", N, true,    → clone an entire wrapped block,
 *       false, [[field=>val, ...], ...])      with `${block}...${/block}` fences
 *
 * Cover page, headers, footers, watermarks, logos, table borders live inside
 * the .docx itself — PhpWord preserves all non-placeholder XML untouched, so
 * the caller never has to rebuild layout in code.
 */
class DocxTemplateService
{
    public function __construct(private TenantStorageService $storage) {}

    // ================================================================
    // ROPA
    // ================================================================

    public function renderRopa(Ropa $ropa, DocumentTemplate $tpl, Organization $org): string
    {
        $templatePath = $this->resolveTemplatePath($tpl, 'ropa');
        [$localTpl, $cleanup] = $this->storage->getLocalPathForProcessing($org, $templatePath);

        try {
            $proc = new TemplateProcessor($localTpl);
            $this->fillRopa($proc, $ropa, $org);
            $out = tempnam(sys_get_temp_dir(), 'ropa_') . '.docx';
            $proc->saveAs($out);
            return $out;
        } finally {
            $cleanup();
        }
    }

    private function fillRopa(TemplateProcessor $proc, Ropa $ropa, Organization $org): void
    {
        $wiz = $ropa->wizard_data ?? [];
        $detail = $wiz['detail_pemrosesan'] ?? [];
        $dpoTeam = $wiz['dpo_team'] ?? [];
        $info = $wiz['informasi_pemrosesan'] ?? [];
        $peng = $wiz['pengumpulan_data'] ?? [];
        $peny = $wiz['penggunaan_penyimpanan'] ?? [];
        $kirim = $wiz['pengiriman_data'] ?? [];
        $ret = $wiz['retensi_keamanan'] ?? [];

        // ─── flat scalars ─────────────────────────────────────────────
        $scalars = [
            'name' => $ropa->processing_activity ?? $detail['nama_pemrosesan'] ?? '',
            'organization_name' => $org->name ?? '',
            'ropa_number' => $ropa->registration_number ?? '',
            'division' => $ropa->division ?? $detail['divisi'] ?? '',
            'work_unit' => $ropa->work_unit ?? $detail['unit_kerja'] ?? '',
            'entity' => $ropa->entity ?? $detail['entitas'] ?? '',
            'description' => $ropa->description ?? $detail['deskripsi'] ?? '',
            'category' => $dpoTeam['kategori_pemrosesan'] ?? '',

            'tujuan' => $info['tujuan'] ?? $ropa->purpose ?? '',
            'aktivitas' => $info['penjelasan'] ?? '',
            'answer_ai' => $info['bantuan_ai'] ?? 'Tidak menggunakan bantuan AI',
            'teknologi_pengambilan' => $info['otomatis'] ?? 'Tidak',
            'teknologi_baru' => $info['teknologi_baru'] ?? 'Tidak',
            'answer_pemrofilan' => $this->formatList($info['pemrofilan'] ?? null, 'Not Applicable'),

            'jumlah_subjek' => $peng['jumlah_subjek'] ?? '-',
            'deskripsi_jumlah_subjek' => $peng['deskripsi_jumlah_subjek'] ?? '-',
            'pihak_proses' => $peny['pihak_pemroses'] ?? '-',
            'pihak_ketiga' => $peny['pihak_ketiga'] ?? 'Tidak',

            'penerima_internal' => $kirim['ada_penerima_internal'] ?? $kirim['ada_penerima'] ?? 'Tidak',
            'penerima_eksternal' => $kirim['ada_penerima_eksternal'] ?? 'Tidak',
            'answer_transfer_luar' => $kirim['transfer_luar'] ?? 'Tidak',

            'document_name' => $ret['document_name'] ?? ($ropa->processing_activity ?? ''),
            'retention_period' => (string) ($ret['masa_retensi_bulan'] ?? $ret['masa_retensi'] ?? $ropa->retention_period ?? '-'),
            'start_at' => $this->formatDate($ret['retensi_start_at'] ?? $ropa->created_at ?? null),
            'end_at' => $this->formatDate($ret['retensi_end_at'] ?? $ropa->retention_due_date ?? null),
            'penghapusan_answer' => $ret['penghapusan_answer'] ?? 'Tidak',
            'insiden' => $ret['pernah_insiden'] ?? 'Tidak',
        ];
        foreach ($scalars as $k => $v) {
            $proc->setValue($k, $this->safe($v));
        }

        // ─── cloneRow: DPO table ──────────────────────────────────────
        $dpoRows = $this->buildDpoRows($ropa);
        $this->cloneRowSafe($proc, 'dpo_no', $dpoRows ?: [['dpo_no' => '-', 'dpo_Nama' => '-', 'dpo_Email' => '-', 'dpo_Phone' => '-']]);

        // ─── cloneRow: PIC table ──────────────────────────────────────
        $picRows = $this->buildPicRows($ropa);
        $this->cloneRowSafe($proc, 'pic_no', $picRows ?: [[
            'pic_no' => '-', 'pic_Nama' => '-', 'pic_Jabatan' => '-',
            'pic_Nomor Induk Karyawan' => '-', 'pic_Unit Bisnis' => '-', 'pic_Email' => '-',
        ]]);

        // ─── cloneRow: Sistem Informasi ───────────────────────────────
        $sisfoRows = $this->buildSistemRows($ropa);
        $this->cloneRowSafe($proc, 'sisfo_no', $sisfoRows ?: [[
            'sisfo_no' => '-', 'sisfo_Nama Sistem Informasi' => '-',
            'sisfo_Lokasi data disimpan' => '-', 'sisfo_Lokasi data pribadi digunakan' => '-',
            'sisfo_Sumber Data (DB)' => '-',
        ]]);

        // ─── cloneRow: Pihak Ketiga ───────────────────────────────────
        $thirdRows = $this->buildThirdPartyRows($peny);
        $this->cloneRowSafe($proc, 'third_no', $thirdRows ?: [[
            'third_no' => '-', 'third_Nama Entitas' => '-', 'third_Alamat Kantor' => '-',
            'third_Nama PIC' => '-', 'third_Email PIC' => '-', 'third_Telp PIC' => '-',
        ]]);

        // ─── cloneRow: Internal + External receivers ──────────────────
        $internalRows = $this->buildInternalReceiverRows($kirim);
        $this->cloneRowSafe($proc, 'internal_no', $internalRows ?: [[
            'internal_no' => '-', 'internal_Nama Divisi' => '-', 'internal_Nama PIC' => '-',
            'internal_Email PIC' => '-', 'internal_No Telpon PIC' => '-',
        ]]);

        $eksternalRows = $this->buildExternalReceiverRows($kirim);
        $this->cloneRowSafe($proc, 'eksternal_no', $eksternalRows ?: [[
            'eksternal_no' => '-', 'eksternal_Nama Organisasi' => '-', 'eksternal_Alamat' => '-',
            'eksternal_Nama PIC' => '-', 'eksternal_Email PIC' => '-',
            'eksternal_Lokasi data diterima' => '-', 'eksternal_Sistem Informasi yang digunakan' => '-',
        ]]);

        // ─── cloneBlock lists — categories, legal bases, data types ────
        // The template uses `${block}…${/block}` to repeat content per item.
        $this->cloneBlockSafe($proc, 'cat_process', $this->blocksSimple($info['jenis_pemrosesan'] ?? [], 'cat_process_name'));
        $this->cloneBlockSafe($proc, 'dasar_proses', $this->blocksWithSub(
            [$info['dasar_pemrosesan'] ?? null], 'dasar_proses_name', $info['legal_basis_detail'] ?? null
        ));
        $this->cloneBlockSafe($proc, 'sub_question_ai', $this->blockSubQuestion(
            ($info['bantuan_ai'] ?? '') !== '' && strpos(strtolower($info['bantuan_ai']), 'tidak') === false
                ? 'Penjelasan' : null,
            $info['bantuan_ai_keterangan'] ?? null
        ));
        $this->cloneBlockSafe($proc, 'profil', $this->blocksSimple(
            is_array($info['pemrofilan'] ?? null)
                ? array_filter($info['pemrofilan'], fn($v) => strtolower(trim((string)$v)) !== 'not applicable')
                : []
        ));

        $this->cloneBlockSafe($proc, 'jenis_subjek', $this->blocksSimple($peng['kategori_subjek'] ?? [], 'jenis_subjek_name'));
        $this->cloneBlockSafe($proc, 'jenis_subjek_ws', []); // reserved for subject types with sub-answers
        $this->cloneBlockSafe($proc, 'jenis_umum', $this->blocksSimple($peng['jenis_data_umum'] ?? [], 'jenis_umum_name'));
        $this->cloneBlockSafe($proc, 'jenis_spesifik', $this->blocksSimple($peng['jenis_data_spesifik'] ?? [], 'jenis_spesifik_name'));
        $this->cloneBlockSafe($proc, 'jenis_pii', $this->blocksSimple($peng['jenis_data_pii'] ?? [], 'jenis_pii_name'));
        $this->cloneBlockSafe($proc, 'sumber', $this->blocksSimple(
            !empty($peng['sumber_data']) ? [$peng['sumber_data']] : [], 'sumber_name'
        ));

        $this->cloneBlockSafe($proc, 'kategori_pihak', $this->blocksSimple($peny['kategori_pihak'] ?? [], 'kategori_pihak_name'));
        $this->cloneBlockSafe($proc, 'kategori_pihak_ws', []); // ws variant — sub-questions

        $this->cloneBlockSafe($proc, 'dikirim_umum', $this->blocksSimple($kirim['dikirim_umum'] ?? $peng['jenis_data_umum'] ?? [], 'dikirim_umum_name'));
        $this->cloneBlockSafe($proc, 'dikirim_spesifik', $this->blocksSimple($kirim['dikirim_spesifik'] ?? $peng['jenis_data_spesifik'] ?? [], 'dikirim_spesifik_name'));
        $this->cloneBlockSafe($proc, 'dikirim_pii', $this->blocksSimple($kirim['dikirim_pii'] ?? $peng['jenis_data_pii'] ?? [], 'dikirim_pii_name'));

        $this->cloneBlockSafe($proc, 'transfer_luar', $this->blockSubQuestion(
            ($kirim['transfer_luar'] ?? null) === 'Ya' ? 'Negara Tujuan' : null,
            $kirim['negara_tujuan'] ?? null
        ));

        $this->cloneBlockSafe($proc, 'hapus', $this->blockSubQuestion(
            ($ret['penghapusan_answer'] ?? null) === 'Ya' ? 'Prosedur' : null,
            $ret['prosedur_pemusnahan'] ?? null
        ));
        $this->cloneBlockSafe($proc, 'kontrol', $this->blocksSimple($ret['kontrol_keamanan'] ?? [], 'kontrol_name'));
        $this->cloneBlockSafe($proc, 'kontrol_ws', []);
        $this->cloneBlockSafe($proc, 'kasus', $this->blockSubQuestion(
            ($ret['pernah_insiden'] ?? null) === 'Ya' ? 'Detail Insiden' : null,
            $ret['detail_insiden'] ?? null
        ));
    }

    // ================================================================
    // DPIA
    // ================================================================

    public function renderDpia(Dpia $dpia, DocumentTemplate $tpl, Organization $org): string
    {
        $templatePath = $this->resolveTemplatePath($tpl, 'dpia');
        [$localTpl, $cleanup] = $this->storage->getLocalPathForProcessing($org, $templatePath);

        try {
            $proc = new TemplateProcessor($localTpl);
            $this->fillDpia($proc, $dpia, $org);
            $out = tempnam(sys_get_temp_dir(), 'dpia_') . '.docx';
            $proc->saveAs($out);
            return $out;
        } finally {
            $cleanup();
        }
    }

    private function fillDpia(TemplateProcessor $proc, Dpia $dpia, Organization $org): void
    {
        $wiz = $dpia->wizard_data ?? [];
        $risk = $wiz['risk_assessment']['potensi_risiko'] ?? $wiz['potensi_risiko'] ?? [];

        // ─── scalars ─────────────────────────────────────────────────
        $scalars = [
            'registration_number' => $dpia->registration_number ?? '',
            'organization_name' => $org->name ?? '',
            'dpia_number' => $dpia->registration_number ?? '',
            'count_ropa' => (string) ($dpia->ropa_id ? 1 : 0),
            'ropa_connections' => optional($dpia->ropa)->registration_number ?? '-',
        ];
        foreach ($scalars as $k => $v) {
            $proc->setValue($k, $this->safe($v));
        }

        // ─── cloneRow: DPO + PIC (shares name/email/phone placeholder) ─
        $dpoRows = $this->buildDpoRows(null, $dpia, $org);
        $this->cloneRowSafe($proc, 'dpo_no', $dpoRows ?: [[
            'dpo_no' => '-', 'name' => '-', 'email' => '-', 'phone_number' => '-',
        ]]);

        $picRows = $this->buildDpiaPicRows($dpia, $org);
        $this->cloneRowSafe($proc, 'pic_no', $picRows ?: [[
            'pic_no' => '-', 'name' => '-', 'email' => '-',
            'phone_number' => '-', 'position' => '-', 'work_unit' => '-',
        ]]);

        // ─── 5×5 risk matrix cells ${1_1}..${5_5} (count per bucket) ──
        $matrix = $this->buildRiskMatrix($risk);
        for ($impact = 1; $impact <= 5; $impact++) {
            for ($prob = 1; $prob <= 5; $prob++) {
                $count = $matrix[$prob][$impact] ?? 0;
                $proc->setValue("{$impact}_{$prob}", $count > 0 ? (string)$count : '');
            }
        }

        // ─── cloneBlock: page (one per risk question) ─────────────────
        $pageBlocks = $this->buildDpiaPageBlocks($risk);
        $this->cloneBlockSafe($proc, 'page', $pageBlocks ?: [[
            'keyword' => '-', 'question' => '-', 'answer' => '-', 'text' => '-',
            'risk_number' => '', 'risk_event' => '', 'a' => '', 'b' => '',
            'c' => '', 'd' => '', 'e' => '', 'f' => '',
            'description' => '-', 'recommendation' => '-',
        ]]);
    }

    // ================================================================
    // GAP (legacy — unchanged)
    // ================================================================

    public function renderGap(GapAssessment $gap, DocumentTemplate $tpl, Organization $org): string
    {
        $templatePath = $this->resolveTemplatePath($tpl, 'gap');
        [$localTpl, $cleanup] = $this->storage->getLocalPathForProcessing($org, $templatePath);

        try {
            $proc = new TemplateProcessor($localTpl);
            $d = $this->gapPlaceholders($gap, $org);
            foreach ($d['scalars'] as $k => $v) $proc->setValue($k, $this->safe($v));
            foreach ($d['lists'] as $k => $items) $proc->setValue($k, implode(', ', array_map([$this, 'safe'], $items)));
            $out = tempnam(sys_get_temp_dir(), 'gap_') . '.docx';
            $proc->saveAs($out);
            return $out;
        } finally {
            $cleanup();
        }
    }

    // ================================================================
    // Placeholder catalog (GET /document-templates/docx-placeholders)
    // ================================================================

    public static function placeholderCatalog(): array
    {
        return [
            'ropa' => [
                'scalars' => [
                    'name' => 'Nama aktivitas pemrosesan',
                    'organization_name' => 'Nama organisasi',
                    'ropa_number' => 'Nomor ROPA (ROPA-YYYY-NNN)',
                    'division' => 'Divisi', 'work_unit' => 'Unit kerja', 'entity' => 'Entitas',
                    'description' => 'Deskripsi singkat',
                    'category' => 'Kategori perusahaan (Pengendali/Pemroses/Joint)',
                    'tujuan' => 'Tujuan pemrosesan', 'aktivitas' => 'Penjelasan aktivitas',
                    'answer_ai' => 'Jawaban "bantuan AI"',
                    'teknologi_pengambilan' => 'Jawaban "teknologi otomatis"',
                    'teknologi_baru' => 'Ya/Tidak teknologi baru',
                    'answer_pemrofilan' => 'Pemrofilan (comma)',
                    'jumlah_subjek' => '≤ 1.000 / > 1.000',
                    'deskripsi_jumlah_subjek' => 'Angka spesifik subjek',
                    'pihak_proses' => 'Nama pihak pemroses',
                    'pihak_ketiga' => 'Ya/Tidak diproses pihak ketiga',
                    'penerima_internal' => 'Ya/Tidak penerima internal',
                    'penerima_eksternal' => 'Ya/Tidak penerima eksternal',
                    'answer_transfer_luar' => 'Ya/Tidak transfer ke luar negeri',
                    'document_name' => 'Nama dokumen retensi',
                    'retention_period' => 'Masa retensi (bulan)',
                    'start_at' => 'Retensi mulai', 'end_at' => 'Retensi berakhir',
                    'penghapusan_answer' => 'Ya/Tidak prosedur pemusnahan',
                    'insiden' => 'Ya/Tidak pernah insiden',
                ],
                'rows' => [
                    'dpo_no' => 'Table DPO: ${dpo_no}, ${dpo_Nama}, ${dpo_Email}, ${dpo_Phone}',
                    'pic_no' => 'Table PIC: ${pic_no}, ${pic_Nama}, ${pic_Jabatan}, ${pic_Nomor Induk Karyawan}, ${pic_Unit Bisnis}, ${pic_Email}',
                    'sisfo_no' => 'Table Sistem: ${sisfo_no}, ${sisfo_Nama Sistem Informasi}, ${sisfo_Lokasi data disimpan}, ${sisfo_Lokasi data pribadi digunakan}, ${sisfo_Sumber Data (DB)}',
                    'third_no' => 'Table Pihak Ketiga',
                    'internal_no' => 'Table Penerima Internal',
                    'eksternal_no' => 'Table Penerima Eksternal',
                ],
                'blocks' => [
                    'cat_process', 'dasar_proses', 'sub_question_ai', 'profil',
                    'jenis_subjek', 'jenis_subjek_ws', 'jenis_umum', 'jenis_spesifik', 'jenis_pii',
                    'sumber', 'kategori_pihak', 'kategori_pihak_ws',
                    'dikirim_umum', 'dikirim_spesifik', 'dikirim_pii',
                    'transfer_luar', 'hapus', 'kontrol', 'kontrol_ws', 'kasus',
                ],
            ],
            'dpia' => [
                'scalars' => [
                    'registration_number' => 'Nomor DPIA',
                    'organization_name' => 'Nama organisasi',
                    'dpia_number' => 'Nomor DPIA',
                    'count_ropa' => 'Jumlah ROPA terhubung',
                    'ropa_connections' => 'Daftar ROPA terhubung (comma)',
                ],
                'rows' => [
                    'dpo_no' => 'Table DPO: ${dpo_no}, ${name}, ${email}, ${phone_number}',
                    'pic_no' => 'Table PIC: ${pic_no}, ${name}, ${email}, ${phone_number}, ${position}, ${work_unit}',
                ],
                'matrix' => '${1_1}..${5_5} — 5×5 risk matrix (probability × impact), cell = jumlah risiko',
                'blocks' => [
                    'page' => 'Block per risk question, berisi ${keyword} ${question} ${answer} ${text} ${risk_number} ${risk_event} ${a} ${b} ${c} ${d} ${e} ${f} ${description} ${recommendation}',
                ],
            ],
        ];
    }

    // ================================================================
    // Row / block builders
    // ================================================================

    private function buildDpoRows(?Ropa $ropa, ?Dpia $dpia = null, ?Organization $org = null): array
    {
        $list = [];
        if ($ropa) $list = $ropa->dpo_list ?? [];
        elseif ($dpia && $org) {
            // DPIA: pull DPO users from the org; wizard_data doesn't track DPOs directly
            $list = User::where('org_id', $org->id)->where('role', 'dpo')
                ->get(['id', 'name', 'email', 'phone'])
                ->map(fn($u) => ['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone ?? ''])
                ->toArray();
        }
        $rows = [];
        foreach ($list as $i => $d) {
            $rows[] = [
                'dpo_no' => (string)($i + 1),
                'dpo_Nama' => $this->safe($d['name'] ?? ''),
                'dpo_Email' => $this->safe($d['email'] ?? ''),
                'dpo_Phone' => $this->safe($d['phone'] ?? ''),
                // DPIA variant shares placeholders name/email/phone_number
                'name' => $this->safe($d['name'] ?? ''),
                'email' => $this->safe($d['email'] ?? ''),
                'phone_number' => $this->safe($d['phone'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildPicRows(Ropa $ropa): array
    {
        $rows = [];
        foreach ($ropa->pic_list ?? [] as $i => $p) {
            $rows[] = [
                'pic_no' => (string)($i + 1),
                'pic_Nama' => $this->safe($p['name'] ?? ''),
                'pic_Jabatan' => $this->safe($p['jabatan'] ?? ''),
                'pic_Nomor Induk Karyawan' => $this->safe($p['nik'] ?? $p['employee_id'] ?? ''),
                'pic_Unit Bisnis' => $this->safe($p['divisi'] ?? $p['unit_bisnis'] ?? ''),
                'pic_Email' => $this->safe($p['email'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildDpiaPicRows(Dpia $dpia, Organization $org): array
    {
        // DPIA doesn't have a dedicated PIC list in wizard_data; fall back to
        // the ROPA it connects to OR to admin users on the org.
        $source = $dpia->ropa?->pic_list ?? [];
        if (empty($source)) {
            $source = User::where('org_id', $org->id)
                ->whereIn('role', ['admin', 'dpo', 'maker'])
                ->limit(3)
                ->get(['name', 'email', 'phone', 'position'])
                ->map(fn($u) => [
                    'name' => $u->name, 'email' => $u->email,
                    'phone' => $u->phone ?? '', 'jabatan' => $u->position ?? '',
                ])
                ->toArray();
        }

        $rows = [];
        foreach ($source as $i => $p) {
            $rows[] = [
                'pic_no' => (string)($i + 1),
                'name' => $this->safe($p['name'] ?? ''),
                'email' => $this->safe($p['email'] ?? ''),
                'phone_number' => $this->safe($p['phone'] ?? ''),
                'position' => $this->safe($p['jabatan'] ?? $p['position'] ?? ''),
                'work_unit' => $this->safe($p['divisi'] ?? $p['unit_bisnis'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildSistemRows(Ropa $ropa): array
    {
        $rows = [];
        foreach ($ropa->sistem_list ?? [] as $i => $s) {
            $rows[] = [
                'sisfo_no' => (string)($i + 1),
                'sisfo_Nama Sistem Informasi' => $this->safe($s['name'] ?? ''),
                'sisfo_Lokasi data disimpan' => $this->safe($s['lokasi'] ?? 'Indonesia'),
                'sisfo_Lokasi data pribadi digunakan' => $this->safe($s['lokasi'] ?? 'Indonesia'),
                'sisfo_Sumber Data (DB)' => $this->safe($s['source_type'] ?? '-'),
            ];
        }
        return $rows;
    }

    private function buildThirdPartyRows(array $peny): array
    {
        $third = $peny['third_parties'] ?? [];
        if (empty($third) && !empty($peny['nama_pihak_ketiga'])) {
            $third = [['nama' => $peny['nama_pihak_ketiga']]];
        }
        $rows = [];
        foreach ($third as $i => $t) {
            $rows[] = [
                'third_no' => (string)($i + 1),
                'third_Nama Entitas' => $this->safe($t['nama'] ?? $t['name'] ?? ''),
                'third_Alamat Kantor' => $this->safe($t['alamat'] ?? ''),
                'third_Nama PIC' => $this->safe($t['pic_name'] ?? ''),
                'third_Email PIC' => $this->safe($t['pic_email'] ?? ''),
                'third_Telp PIC' => $this->safe($t['pic_phone'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildInternalReceiverRows(array $kirim): array
    {
        $rows = [];
        foreach ($kirim['penerima_internal_list'] ?? [] as $i => $r) {
            $rows[] = [
                'internal_no' => (string)($i + 1),
                'internal_Nama Divisi' => $this->safe($r['divisi'] ?? ''),
                'internal_Nama PIC' => $this->safe($r['pic_name'] ?? ''),
                'internal_Email PIC' => $this->safe($r['pic_email'] ?? ''),
                'internal_No Telpon PIC' => $this->safe($r['pic_phone'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildExternalReceiverRows(array $kirim): array
    {
        $rows = [];
        foreach ($kirim['penerima_eksternal_list'] ?? $kirim['penerima'] ?? [] as $i => $r) {
            $rows[] = [
                'eksternal_no' => (string)($i + 1),
                'eksternal_Nama Organisasi' => $this->safe($r['nama'] ?? $r['name'] ?? ''),
                'eksternal_Alamat' => $this->safe($r['alamat'] ?? ''),
                'eksternal_Nama PIC' => $this->safe($r['pic_name'] ?? ''),
                'eksternal_Email PIC' => $this->safe($r['pic_email'] ?? ''),
                'eksternal_Lokasi data diterima' => $this->safe($r['lokasi'] ?? ''),
                'eksternal_Sistem Informasi yang digunakan' => $this->safe($r['sistem'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildRiskMatrix(array $risk): array
    {
        // risk_assessment.potensi_risiko → bucket by dampak × probabilitas.
        $matrix = [];
        foreach ($risk as $cat => $row) {
            if (!is_array($row)) continue;
            $d = (int) ($row['dampak'] ?? 0);
            $p = (int) ($row['probabilitas'] ?? 0);
            if ($d < 1 || $d > 5 || $p < 1 || $p > 5) continue;
            $matrix[$p][$d] = ($matrix[$p][$d] ?? 0) + 1;
        }
        return $matrix;
    }

    private function buildDpiaPageBlocks(array $risk): array
    {
        $blocks = [];
        foreach ($risk as $cat => $row) {
            if (!is_array($row)) continue;
            $dampak = (int)($row['dampak'] ?? 0);
            $prob = (int)($row['probabilitas'] ?? 0);
            $inherent = $dampak * $prob;
            $blocks[] = [
                'keyword' => $this->safe($cat),
                'question' => $this->safe($row['question'] ?? $cat),
                'answer' => $this->safe($row['answer'] ?? '-'),
                'text' => $this->safe($row['description'] ?? ''),
                'risk_number' => '1',
                'risk_event' => $this->safe($row['risk_event'] ?? $cat),
                'a' => (string)$dampak,
                'b' => (string)$prob,
                'c' => (string)$inherent,
                'd' => $this->safe($row['kontrol'] ?? '-'),
                'e' => (string)($row['residual'] ?? $inherent),
                'f' => $this->safe($row['penanganan'] ?? '-'),
                'description' => $this->safe($row['uraian_dampak'] ?? ''),
                'recommendation' => $this->safe($row['mitigasi'] ?? $row['recommendation'] ?? ''),
            ];
        }
        return $blocks;
    }

    // ================================================================
    // Block helpers
    // ================================================================

    private function blocksSimple(array $items, ?string $nameField = null): array
    {
        $items = array_values(array_filter($items, fn($v) => trim((string)$v) !== ''));
        $blocks = [];
        foreach ($items as $item) {
            $block = ['sub_question' => '', 'answer_sub_question' => ''];
            if ($nameField) $block[$nameField] = $this->safe($item);
            $blocks[] = $block;
        }
        return $blocks;
    }

    private function blocksWithSub(array $items, ?string $nameField, ?string $subAnswer): array
    {
        $items = array_values(array_filter($items, fn($v) => $v !== null && trim((string)$v) !== ''));
        $blocks = [];
        foreach ($items as $item) {
            $block = [
                'sub_question' => $subAnswer ? 'Keterangan' : '',
                'answer_sub_question' => $this->safe($subAnswer ?? ''),
            ];
            if ($nameField) $block[$nameField] = $this->safe($item);
            $blocks[] = $block;
        }
        return $blocks;
    }

    private function blockSubQuestion(?string $label, ?string $answer): array
    {
        if (!$label || !$answer) return [];
        return [[
            'sub_question' => $this->safe($label),
            'answer_sub_question' => $this->safe($answer),
        ]];
    }

    // ================================================================
    // PhpWord TemplateProcessor wrappers (null-safe)
    // ================================================================

    /**
     * Safely clone a table row. If the marker placeholder isn't in the
     * template (e.g. tenant custom template), quietly skip.
     */
    private function cloneRowSafe(TemplateProcessor $proc, string $marker, array $rows): void
    {
        try {
            $proc->cloneRowAndSetValues($marker, $rows);
        } catch (\Throwable $e) {
            \Log::debug("cloneRow {$marker} skipped: " . $e->getMessage());
        }
    }

    /**
     * Safely clone a `${block}…${/block}` fenced region. If the block isn't
     * in the template, skip. Empty rows collapse the block.
     */
    private function cloneBlockSafe(TemplateProcessor $proc, string $block, array $rows): void
    {
        try {
            if (empty($rows)) {
                // Delete the block entirely when no data.
                $proc->cloneBlock($block, 0, true, false);
                return;
            }
            $proc->cloneBlock($block, count($rows), true, false, array_values($rows));
        } catch (\Throwable $e) {
            \Log::debug("cloneBlock {$block} skipped: " . $e->getMessage());
        }
    }

    // ================================================================
    // Legacy GAP placeholders (unchanged)
    // ================================================================

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
                'categories' => is_array($g->categories ?? null) ? $g->categories : [],
                'priority_recommendations' => is_array($g->priority_recommendations ?? null) ? $g->priority_recommendations : [],
            ],
        ];
    }

    // ================================================================
    // Utilities
    // ================================================================

    private function resolveTemplatePath(DocumentTemplate $tpl, string $kind): string
    {
        $map = $tpl->docx_templates ?? [];
        if (empty($map[$kind]['path'])) {
            throw new \RuntimeException("Template DOCX untuk {$kind} belum di-upload.");
        }
        return $map[$kind]['path'];
    }

    private function formatList($v, string $fallback = '-'): string
    {
        if (is_array($v)) {
            $v = array_values(array_filter($v, fn($x) => trim((string)$x) !== ''));
            return $v ? implode(', ', $v) : $fallback;
        }
        if (is_string($v) && trim($v) !== '') return $v;
        return $fallback;
    }

    private function formatDate($v): string
    {
        if (!$v) return '-';
        try {
            return \Carbon\Carbon::parse($v)->locale('id')->isoFormat('D MMMM Y');
        } catch (\Throwable $e) {
            return (string)$v;
        }
    }

    private function safe($v): string
    {
        return htmlspecialchars((string) $v, ENT_COMPAT, 'UTF-8');
    }
}
