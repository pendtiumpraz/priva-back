<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\TemplateProcessor;
use App\Models\Ropa;
use App\Models\Dpia;
use App\Models\GapAssessment;
use Illuminate\Support\Facades\Storage;

class TemplateExportController extends Controller
{
    public function exportRopa($id)
    {
        $ropa = Ropa::where('org_id', auth()->user()->org_id)->findOrFail($id);
        
        $templatePath = storage_path('app/templates/ropa-template.docx');
        if (!file_exists($templatePath)) {
            return response()->json(['message' => 'Template ROPA tidak ditemukan'], 404);
        }

        try {
            $templateProcessor = new TemplateProcessor($templatePath);
            
            // Ambil nested JSON variables dari Wizard
            $wiz = $ropa->wizard_data ?? [];
            $detail = $wiz['detail_pemrosesan'] ?? [];
            $dpo = $wiz['dpo_team'] ?? [];
            $info = $wiz['informasi_pemrosesan'] ?? [];
            $pengumpulan = $wiz['pengumpulan_data'] ?? [];
            $penggunaan = $wiz['penggunaan_penyimpanan'] ?? [];
            $pengiriman = $wiz['pengiriman_data'] ?? [];
            $retensi = $wiz['retensi_keamanan'] ?? [];

            // Helper format array
            $fmtArray = fn($arr) => is_array($arr) ? implode(', ', $arr) : '-';

            // Ganti Tag Dinamis
            $templateProcessor->setValue('registration_number', htmlspecialchars($ropa->registration_number ?? '-'));
            $templateProcessor->setValue('nama_pemrosesan', htmlspecialchars($ropa->processing_activity ?? '-'));
            $templateProcessor->setValue('entitas', htmlspecialchars($ropa->entity ?? '-'));
            $templateProcessor->setValue('divisi', htmlspecialchars($ropa->division ?? '-'));
            $templateProcessor->setValue('unit_kerja', htmlspecialchars($ropa->work_unit ?? '-'));
            $templateProcessor->setValue('risk_level', strtoupper($ropa->risk_level ?? '-'));
            $templateProcessor->setValue('kategori_pemrosesan', htmlspecialchars($ropa->kategori_pemrosesan ?? '-'));
            
            $templateProcessor->setValue('dpo_name', htmlspecialchars($dpo['dpo_name'] ?? '-'));
            $templateProcessor->setValue('dpo_email', htmlspecialchars($dpo['dpo_email'] ?? '-'));
            $templateProcessor->setValue('dpo_phone', htmlspecialchars($dpo['dpo_phone'] ?? '-'));
            
            $templateProcessor->setValue('tujuan', htmlspecialchars($ropa->purpose ?? '-'));
            $templateProcessor->setValue('dasar_pemrosesan', htmlspecialchars($ropa->legal_basis ?? '-'));
            $templateProcessor->setValue('sumber_data', htmlspecialchars($pengumpulan['sumber_data'] ?? '-'));
            $templateProcessor->setValue('jumlah_subjek', htmlspecialchars($pengumpulan['jumlah_subjek'] ?? '-'));
            
            $templateProcessor->setValue('jenis_data_spesifik', htmlspecialchars($fmtArray($pengumpulan['jenis_data_spesifik'] ?? [])));
            $templateProcessor->setValue('jenis_data_umum', htmlspecialchars($fmtArray($pengumpulan['jenis_data_umum'] ?? [])));
            $templateProcessor->setValue('jenis_data_pii', htmlspecialchars($fmtArray($pengumpulan['jenis_data_pii'] ?? [])));
            $templateProcessor->setValue('pihak_pemroses', htmlspecialchars($penggunaan['pihak_pemroses'] ?? '-'));
            
            $templateProcessor->setValue('transfer_luar', htmlspecialchars($pengiriman['transfer_luar'] ?? '-'));
            $templateProcessor->setValue('negara_tujuan', htmlspecialchars($pengiriman['negara_tujuan'] ?? '-'));
            $templateProcessor->setValue('safeguards', htmlspecialchars($pengiriman['safeguards'] ?? '-'));
            
            $templateProcessor->setValue('kontrol_keamanan', htmlspecialchars($fmtArray($retensi['kontrol_keamanan'] ?? [])));
            $templateProcessor->setValue('masa_retensi', htmlspecialchars($ropa->retention_period ?? '-'));
            $templateProcessor->setValue('status', strtoupper($ropa->status ?? '-'));

            $outputFileName = 'ROPA_' . $ropa->registration_number . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
            $templateProcessor->saveAs($tempFile);

            return response()->download($tempFile, $outputFileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage() . ' di file ' . $e->getFile() . ' baris ' . $e->getLine()], 500);
        }
    }

    public function exportDpia($id)
    {
        $dpia = Dpia::with('ropa')->where('org_id', auth()->user()->org_id)->findOrFail($id);
        
        $templatePath = storage_path('app/templates/dpia-template.docx');
        if (!file_exists($templatePath)) {
            return response()->json(['message' => 'Template DPIA tidak ditemukan'], 404);
        }

        try {
            $templateProcessor = new TemplateProcessor($templatePath);
            
            $wiz = $dpia->assessment_data ?? [];
            $templateProcessor->setValue('dpia_number', htmlspecialchars($dpia->registration_number ?? '-'));
            $templateProcessor->setValue('ropa_number', htmlspecialchars($dpia->ropa ? $dpia->ropa->registration_number : '-'));
            $templateProcessor->setValue('title', htmlspecialchars($dpia->description ?? '-'));
            $templateProcessor->setValue('risk_level', strtoupper($dpia->risk_level ?? '-'));
            $templateProcessor->setValue('evaluasi_keperluan', htmlspecialchars($wiz['assessment']['necessity_proportionality'] ?? '-'));
            
            $risks = $wiz['risks'] ?? [];
            $sumber = [];
            $identifikasi = [];
            $mitigasi = [];
            $residual = [];
            foreach($risks as $r) {
                $sumber[] = $r['description'] ?? '-';
                $identifikasi[] = 'L: '.($r['likelihood']??'-').' x I: '.($r['impact']??'-').' = '.($r['risk_score']??'-');
                $mitigasi[] = $r['mitigation_measures'] ?? '-';
                $residual[] = 'L: '.($r['residual_likelihood']??'-').' x I: '.($r['residual_impact']??'-').' = '.($r['residual_risk_score']??'-');
            }
            
            // To emulate newlines in DOCX inline values we can often use <w:br/>, 
            // but setting arrays of text might need special PHPWord treatment. Let's just implode with " | " for safety or <w:br/>
            // PhpWord template processor handles multiline with \n if properly set, but simplest is string implode
            $templateProcessor->setValue('sumber_risiko', htmlspecialchars(implode(" | ", $sumber)));
            $templateProcessor->setValue('identifikasi_risiko', htmlspecialchars(implode(" | ", $identifikasi)));
            $templateProcessor->setValue('mitigasi', htmlspecialchars(implode(" | ", $mitigasi)));
            $templateProcessor->setValue('residual_risk', htmlspecialchars(implode(" | ", $residual)));
            $templateProcessor->setValue('rekomendasi_dpo', htmlspecialchars($wiz['approval']['dpo_comments'] ?? '-'));
            $templateProcessor->setValue('status', strtoupper($dpia->status ?? '-'));

            $outputFileName = 'DPIA_' . $dpia->registration_number . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
            $templateProcessor->saveAs($tempFile);

            return response()->download($tempFile, $outputFileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage() . ' di file ' . $e->getFile() . ' baris ' . $e->getLine()], 500);
        }
    }

    // Untuk Gap Assessment (Excel) karena menggunakan .xls lawas, 
    // jika menggunakan PhpSpreadsheet, kita load dan save.
    // Namun untuk PoC cepat, kita beritahu user bahwa File Excel dikelola oleh library Maatwebsite/Excel di iterasi ini
    // atau gunakan PhpSpreadsheet native.
    public function exportGap($id)
    {
        $gap = GapAssessment::where('org_id', auth()->user()->org_id)->findOrFail($id);
        $templatePath = storage_path('app/templates/gap-template.xls');
        
        if (!file_exists($templatePath)) {
            return response()->json(['message' => 'Template Gap Assessment (.xls) tidak ditemukan'], 404);
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Asumsi sel tertentu (opsional untuk diedit user, misal D2 dsb)
            // $worksheet->setCellValue('D4', $gap->version);
            // $worksheet->setCellValue('D5', $gap->score . '%');
            // $worksheet->setCellValue('D6', strtoupper($gap->compliance_level));

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xls');
            $outputFileName = 'Gap_Assessment_' . $gap->version . '.xls';
            $tempFile = tempnam(sys_get_temp_dir(), 'phpxls');
            $writer->save($tempFile);

            return response()->download($tempFile, $outputFileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal membaca/menulis file Excel: ' . $e->getMessage()], 500);
        }
    }
}
