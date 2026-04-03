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
            
            // Ganti Tag Dinamis
            $templateProcessor->setValue('registration_number', htmlspecialchars($ropa->registration_number));
            $templateProcessor->setValue('processing_activity', htmlspecialchars($ropa->processing_activity));
            $templateProcessor->setValue('division', htmlspecialchars($ropa->division ?? '-'));
            $templateProcessor->setValue('risk_level', strtoupper($ropa->risk_level));
            $templateProcessor->setValue('purpose', htmlspecialchars($ropa->purpose ?? '-'));
            $templateProcessor->setValue('legal_basis', htmlspecialchars($ropa->legal_basis ?? '-'));
            
            // Data categories & subjects
            $dataCategories = is_array($ropa->data_categories) ? implode(', ', $ropa->data_categories) : '-';
            $templateProcessor->setValue('data_categories', htmlspecialchars($dataCategories));
            
            $dataSubjects = is_array($ropa->data_subjects) ? implode(', ', $ropa->data_subjects) : '-';
            $templateProcessor->setValue('data_subjects', htmlspecialchars($dataSubjects));

            $templateProcessor->setValue('retention_period', htmlspecialchars($ropa->retention_period ?? '-'));
            $templateProcessor->setValue('security_measures', htmlspecialchars($ropa->security_measures ?? '-'));

            $outputFileName = 'ROPA_' . $ropa->registration_number . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
            $templateProcessor->saveAs($tempFile);

            return response()->download($tempFile, $outputFileName)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage()], 500);
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
            
            $templateProcessor->setValue('registration_number', htmlspecialchars($dpia->registration_number));
            $templateProcessor->setValue('ropa_number', htmlspecialchars($dpia->ropa ? $dpia->ropa->registration_number : '-'));
            $templateProcessor->setValue('risk_level', strtoupper($dpia->risk_level));
            $templateProcessor->setValue('status', strtoupper($dpia->status));
            $templateProcessor->setValue('description', htmlspecialchars($dpia->description ?? '-'));

            $outputFileName = 'DPIA_' . $dpia->registration_number . '.docx';
            $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
            $templateProcessor->saveAs($tempFile);

            return response()->download($tempFile, $outputFileName)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Export error: ' . $e->getMessage()], 500);
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
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal membaca/menulis file Excel: ' . $e->getMessage()], 500);
        }
    }
}
