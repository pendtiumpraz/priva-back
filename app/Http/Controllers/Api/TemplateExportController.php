<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use App\Models\Ropa;
use App\Models\Dpia;
use App\Models\GapAssessment;

class TemplateExportController extends Controller
{
    private function t($text): string
    {
        return htmlspecialchars((string) $text, ENT_COMPAT, 'UTF-8');
    }

    private function addCoverPage(PhpWord $phpWord, string $docType, string $title, string $regNumber, string $status, string $riskLevel, string $orgName)
    {
        $section = $phpWord->addSection([
            'marginTop' => 0, 'marginBottom' => 0, 'marginLeft' => 0, 'marginRight' => 0,
        ]);

        $tableStyle = ['borderSize' => 0, 'cellMargin' => 0, 'width' => 11905, 'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP];
        $phpWord->addTableStyle('CoverTableBase', $tableStyle);
        $table = $section->addTable('CoverTableBase');

        $row = $table->addRow(16840, ['exactHeight' => true]);
        $cell = $row->addCell(11905, ['bgColor' => '1b143c', 'valign' => 'center']);
        
        $cell->addTextBreak(6);
        $cell->addText('PRIVASIMU', ['size' => 16, 'color' => 'ffffff', 'bold' => true], ['alignment' => Jc::CENTER]);
        $cell->addText('|', ['size' => 14, 'color' => '4f46e5'], ['alignment' => Jc::CENTER]);
        $cell->addTextBreak(1);
        
        $docTypeMain = explode(' ', $docType)[0];
        $docTypeSub = str_replace($docTypeMain.' ', '', $docType);
        
        $textRun = $cell->addTextRun(['alignment' => Jc::CENTER]);
        $textRun->addText($docTypeMain, ['size' => 32, 'bold' => true, 'color' => 'ffffff']);
        if ($docTypeSub) {
            $textRun->addText(' ' . trim($docTypeSub), ['size' => 32, 'color' => 'e0e7ff']);
        }
        
        $cell->addTextBreak(1);
        
        $imgPath = base_path('public/images/doc-cover-bg.png');
        if (file_exists($imgPath)) {
            $cell->addImage($imgPath, [
                'width' => 350,
                'alignment' => Jc::CENTER,
            ]);
        } else {
            $cell->addTextBreak(12);
        }
        
        $cell->addTextBreak(4);
        $cell->addText($this->t($title ?: 'Untitled'), ['size' => 16, 'color' => 'ffffff'], ['alignment' => Jc::CENTER]);
        
        $cell->addTextBreak(6);

        $metaTable = $cell->addTable(['borderSize' => 8, 'borderColor' => 'ffffff', 'cellMargin' => 100, 'alignment' => Jc::CENTER]);
        $metaRow = $metaTable->addRow();
        
        $metaRow->addCell(4000)->addText($this->t("ROPA for:\n" . ($orgName ?: '-')), ['size' => 10, 'bold' => true, 'color' => 'ffffff']);
        $metaRow->addCell(2500)->addText($this->t("Status:\n" . strtoupper($status)), ['size' => 10, 'bold' => true, 'color' => 'ffffff']);
        $metaRow->addCell(2500)->addText($this->t("Date:\n" . now()->format('d M Y')), ['size' => 10, 'bold' => true, 'color' => 'ffffff']);

        return $section;
    }

    private function addContentSection(PhpWord $phpWord, string $headerText)
    {
        $section = $phpWord->addSection([
            'marginTop' => 800, 'marginBottom' => 800,
            'marginLeft' => 800, 'marginRight' => 800,
            'bgColor' => 'fcfcfd'
        ]);
        
        $header = $section->addHeader();
        $headerTable = $header->addTable(['borderBottomSize' => 12, 'borderBottomColor' => '94a3b8', 'width' => 100 * 50]);
        $headerTable->addRow();
        $docTypeMain = explode(' ', $headerText)[0];
        $textRun = $headerTable->addCell(10000)->addTextRun();
        $textRun->addText($docTypeMain, ['size' => 12, 'bold' => true, 'color' => '8b9cd4']);
        $textRun->addText(' DATA EXPORT', ['size' => 12, 'color' => '8b9cd4']);
        
        $footer = $section->addFooter();
        $footerTable = $footer->addTable(['width' => 100 * 50]);
        $footerTable->addRow();
        $footerTable->addCell(3000)->addText('PRIVASIMU', ['size' => 12, 'bold' => true, 'color' => '8b9cd4']);
        $footerTable->addCell(7000)->addPreserveText('Your Trusted Personal Data Protection Technology and Compliance Solution | {PAGE}', ['size' => 8, 'color' => '94a3b8', 'italic' => true], ['alignment' => Jc::END]);
        
        return $section;
    }

    private function addSectionTitle($section, string $title)
    {
        $section->addTextBreak();
        $table = $section->addTable(['bgColor' => 'ffffff', 'cellMargin' => 0]);
        $table->addRow();
        $cell = $table->addCell(10000);
        $cleanTitle = ltrim($title, '0123456789. ');
        $cell->addText($this->t('■  ' . $cleanTitle), ['size' => 14, 'bold' => true, 'color' => '1b143c'], ['spaceBefore' => 200, 'spaceAfter' => 100]);
    }

    private function addInfoRow($table, string $label, string $value)
    {
        $rowHeader = $table->addRow();
        $rowHeader->addCell(10000, ['bgColor' => 'f4f5f9', 'borderSize' => 0])
            ->addText($this->t($label), ['size' => 10, 'bold' => true, 'color' => '1e293b'], ['spaceBefore' => 60, 'spaceAfter' => 60]);
            
        $rowValue = $table->addRow();
        $rowValue->addCell(10000, ['bgColor' => 'ffffff', 'borderSize' => 0])
            ->addText($this->t($value ?: '-'), ['size' => 10, 'color' => '475569'], ['spaceBefore' => 40, 'spaceAfter' => 100]);
            
        $table->addRow()->addCell(10000)->addText('', [], ['spaceBefore' => 40]);
    }

    private function makeInfoTable($section)
    {
        return $section->addTable([
            'borderSize' => 0,
            'cellMargin' => 40,
            'width' => 100 * 50,
            'alignment' => Jc::CENTER
        ]);
    }

    private function fmtArray($arr): string
    {
        if (!$arr) return '-';
        if (is_array($arr)) return implode(', ', array_filter($arr)) ?: '-';
        return (string) $arr ?: '-';
    }

    // ================================================================
    //  ROPA EXPORT
    // ================================================================
    public function exportRopa($id)
    {
        $ropa = Ropa::where('org_id', auth()->user()->org_id)->findOrFail($id);

        try {
            $phpWord = new PhpWord();

            $wiz = $ropa->wizard_data ?? [];
            $detail = $wiz['detail_pemrosesan'] ?? [];
            $dpo = $wiz['dpo_team'] ?? [];
            $info = $wiz['informasi_pemrosesan'] ?? [];
            $pengumpulan = $wiz['pengumpulan_data'] ?? [];
            $penggunaan = $wiz['penggunaan_penyimpanan'] ?? [];
            $pengiriman = $wiz['pengiriman_data'] ?? [];
            $retensi = $wiz['retensi_keamanan'] ?? [];
            $orgName = auth()->user()->organization->name ?? '-';

            // Cover Page
            $this->addCoverPage(
                $phpWord, 'Record of Processing Activities (ROPA)',
                $ropa->processing_activity ?? $detail['nama_pemrosesan'] ?? 'Untitled ROPA',
                $ropa->registration_number ?? '-',
                $ropa->status ?? 'draft',
                $ropa->risk_level ?? 'low',
                $orgName
            );

            // Content Page
            $sec = $this->addContentSection($phpWord, 'ROPA - ' . ($ropa->registration_number ?? ''));

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

        try {
            $phpWord = new PhpWord();

            $wiz = $dpia->wizard_data ?? [];
            $infoD = $wiz['informasi_dpia'] ?? [];
            $koneksi = $wiz['koneksi_ropa'] ?? [];
            $risiko = $wiz['potensi_risiko'] ?? [];
            $ra = $dpia->risk_assessment ?? [];
            $orgName = auth()->user()->organization->name ?? '-';

            // Cover Page
            $this->addCoverPage(
                $phpWord, 'Data Protection Impact Assessment (DPIA)',
                $dpia->description ?? $infoD['description'] ?? 'Untitled DPIA',
                $dpia->registration_number ?? '-',
                $dpia->status ?? 'draft',
                $dpia->risk_level ?? 'low',
                $orgName
            );

            // Content Page
            $sec = $this->addContentSection($phpWord, 'DPIA - ' . ($dpia->registration_number ?? ''));

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

        try {
            $phpWord = new PhpWord();
            $orgName = auth()->user()->organization->name ?? '-';
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
                $orgName
            );

            // Content Page
            $sec = $this->addContentSection($phpWord, 'GAP ASSESSMENT - ' . ($gap->version ?? ''));

            // 1. Informasi Assessment
            $this->addSectionTitle($sec, '1. Informasi Assessment');
            $t = $this->makeInfoTable($sec);
            $this->addInfoRow($t, 'Versi Assessment', $gap->version ?? '-');
            $this->addInfoRow($t, 'Organisasi', $orgName);
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
                'na' => ['label' => 'N/A', 'color' => '94a3b8'],
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
                $ansInfo = $answerLabels[$ansCode] ?? ['label' => 'Belum Dijawab', 'color' => '94a3b8'];
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
}
