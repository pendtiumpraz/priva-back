<?php

require __DIR__.'/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

// ==========================================
// RoPA TEMPLATE GENERATION
// ==========================================
$phpWord = new PhpWord;
$section = $phpWord->addSection();

$fontStyle = ['name' => 'Arial', 'size' => 11];
$titleStyle = ['name' => 'Arial', 'size' => 16, 'bold' => true];
$headerStyle = ['name' => 'Arial', 'size' => 12, 'bold' => true, 'color' => 'FFFFFF'];

$section->addText('RECORD OF PROCESSING ACTIVITIES (RoPA)', $titleStyle, ['alignment' => Jc::CENTER]);
$section->addTextBreak(1);

$tableStyle = [
    'borderSize' => 6,
    'borderColor' => '000000',
    'cellMargin' => 80,
];
$phpWord->addTableStyle('RopaTable', $tableStyle);
$table = $section->addTable('RopaTable');

// Data rows
$rows = [
    ['No Registrasi / RoPA NO', '${registration_number}'],
    ['Nama Pemrosesan', '${nama_pemrosesan}'],
    ['Entitas', '${entitas}'],
    ['Divisi / Departemen', '${divisi}'],
    ['Unit Kerja', '${unit_kerja}'],
    ['Risk Level', '${risk_level}'],
    ['Kategori Pemrosesan', '${kategori_pemrosesan}'],
    ['Nama DPO / Pejabat', '${dpo_name}'],
    ['Kontak DPO', '${dpo_email} / ${dpo_phone}'],
    ['Tujuan Pemrosesan', '${tujuan}'],
    ['Dasar Pemrosesan', '${dasar_pemrosesan}'],
    ['Sumber Data Pribadi', '${sumber_data}'],
    ['Jumlah Subjek', '${jumlah_subjek}'],
    ['Kategori Spesifik', '${jenis_data_spesifik}'],
    ['Kategori Umum', '${jenis_data_umum}'],
    ['Kategori PII', '${jenis_data_pii}'],
    ['Pihak yang Memproses', '${pihak_pemroses}'],
    ['Transfer ke Luar Indonesia', '${transfer_luar}'],
    ['Negara Tujuan / Safeguards', '${negara_tujuan} / ${safeguards}'],
    ['Kontrol Keamanan', '${kontrol_keamanan}'],
    ['Masa Retensi', '${masa_retensi}'],
    ['Status RoPA', '${status}'],
];

foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(4000, ['bgColor' => 'F2F2F2'])->addText($row[0], ['bold' => true]);
    $table->addCell(6000)->addText($row[1]);
}

$ropaPath = __DIR__.'/storage/app/templates/ropa-template.docx';
$phpWord->save($ropaPath, 'Word2007');
echo "RoPA template generated!\n";

// ==========================================
// DPIA TEMPLATE GENERATION
// ==========================================
$phpWord2 = new PhpWord;
$section2 = $phpWord2->addSection();

$section2->addText('DATA PROTECTION IMPACT ASSESSMENT (DPIA)', $titleStyle, ['alignment' => Jc::CENTER]);
$section2->addTextBreak(1);

$phpWord2->addTableStyle('DpiaTable', $tableStyle);
$table2 = $section2->addTable('DpiaTable');

$dpiaRows = [
    ['No DPIA', '${dpia_number}'],
    ['No RoPA Terkait', '${ropa_number}'],
    ['Judul/Aktivitas', '${title}'],
    ['Risk Level DPIA', '${risk_level}'],
    ['Evaluasi Keperluan Pemrosesan', '${evaluasi_keperluan}'],
    ['Sumber Risiko', '${sumber_risiko}'],
    ['Identifikasi Risiko (Likelihood x Impact)', '${identifikasi_risiko}'],
    ['Mitigasi & Pengendalian', '${mitigasi}'],
    ['Sisa Risiko (Residual Risk)', '${residual_risk}'],
    ['Rekomendasi DPO', '${rekomendasi_dpo}'],
    ['Status DPIA', '${status}'],
];

foreach ($dpiaRows as $row) {
    $table2->addRow();
    $table2->addCell(4000, ['bgColor' => 'F2F2F2'])->addText($row[0], ['bold' => true]);
    $table2->addCell(6000)->addText($row[1]);
}

$dpiaPath = __DIR__.'/storage/app/templates/dpia-template.docx';
$phpWord2->save($dpiaPath, 'Word2007');
echo "DPIA template generated!\n";
