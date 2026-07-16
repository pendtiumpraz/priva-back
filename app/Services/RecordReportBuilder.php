<?php

namespace App\Services;

use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\CountryAdequacy;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\GapAssessment;
use App\Models\InformationSystem;
use App\Models\LiaAssessment;
use App\Models\MaturityAssessment;
use App\Models\MaturityQuestion;
use App\Models\ModuleCustomField;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;

/**
 * Builds a per-record "report document" as an ordered list of sections, where
 * each section is { title, rows:[{label, value}] }. Consumed by
 * BrandedXlsxExporter::exportRecord() (and, later, DOCX/PDF renderers).
 *
 * One method per module. ROPA + DPIA implemented first; other modules follow
 * the same shape.
 */
class RecordReportBuilder
{
    private const ID_MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    // ---------------------------------------------------------------- helpers

    private function str($v): string
    {
        if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Ya' : 'Tidak';
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v));
        }

        return trim((string) $v);
    }

    private function yn($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Ya' : 'Tidak';
        }
        $s = strtolower(trim((string) $v));
        if (in_array($s, ['ya', 'yes', '1', 'true', 'y', 'ada'], true)) {
            return 'Ya';
        }
        if (in_array($s, ['tidak', 'no', '0', 'false', 'n', 'tidak ada'], true)) {
            return 'Tidak';
        }

        return (string) $v;
    }

    private function date($v): string
    {
        if (! $v) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable((string) $v);
        } catch (\Throwable) {
            return (string) $v;
        }
        $d = (int) $dt->format('j');
        $m = self::ID_MONTHS[(int) $dt->format('n')] ?? $dt->format('M');

        return "{$d} {$m} ".$dt->format('Y');
    }

    /** Join an array into bulleted lines using a per-item formatter. */
    private function bullets(array $items, callable $fmt): string
    {
        $lines = [];
        foreach ($items as $it) {
            $line = trim((string) $fmt($it));
            if ($line !== '') {
                $lines[] = '• '.$line;
            }
        }

        return implode("\n", $lines);
    }

    private function arr($v): array
    {
        return is_array($v) ? $v : [];
    }

    /**
     * Format a single field value by its declared type (text/textarea/number/
     * date/boolean) using the shared helpers.
     */
    private function typedValue($val, ?string $type): string
    {
        return match ($type) {
            'boolean' => $this->yn($val),
            'date' => $this->date($val),
            default => $this->str($val),
        };
    }

    /**
     * Additional / custom field sections for RoPA & DPIA — so anything a tenant
     * adds beyond the built-in schema is included in every export format:
     *   1. Per-record extras (wizard_data.per_record_extras) — self-describing
     *      {field_label, value, field_type}.
     *   2. Org-defined custom fields (ModuleCustomField definitions) whose
     *      values live flat in wizard_data.custom_fields[field_name].
     * Returns 0–2 sections (empty ones are skipped).
     */
    private function additionalFieldSections($record, string $module): array
    {
        $w = $this->arr($record->wizard_data);
        $out = [];

        // 1. Per-record extra fields.
        $extraRows = [];
        foreach ($this->arr($w['per_record_extras'] ?? null) as $e) {
            $label = $this->str($e['field_label'] ?? ($e['field_name'] ?? null));
            if ($label === '') {
                continue;
            }
            $extraRows[] = ['label' => $label, 'value' => $this->typedValue($e['value'] ?? null, (string) ($e['field_type'] ?? 'text'))];
        }
        if (! empty($extraRows)) {
            $out[] = ['title' => 'Informasi Tambahan (Per-Dokumen)', 'rows' => $extraRows];
        }

        // 2. Org-defined custom fields (values flat in wizard_data.custom_fields).
        $customValues = $this->arr($w['custom_fields'] ?? null);
        $defs = ModuleCustomField::where('org_id', $record->org_id)
            ->where('module', $module)
            ->where('origin', '!=', 'built_in')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['field_name', 'field_label', 'field_type']);
        $customRows = [];
        foreach ($defs as $f) {
            $customRows[] = [
                'label' => $this->str($f->field_label ?: $f->field_name),
                'value' => $this->typedValue($customValues[$f->field_name] ?? null, $f->field_type),
            ];
        }
        if (! empty($customRows)) {
            $out[] = ['title' => 'Field Kustom Organisasi', 'rows' => $customRows];
        }

        return $out;
    }

    // ------------------------------------------------------------------- ROPA

    public function ropa(Ropa $r): array
    {
        $w = $this->arr($r->wizard_data);
        $detail = $this->arr($w['detail_pemrosesan'] ?? null);
        $info = $this->arr($w['informasi_pemrosesan'] ?? null);
        $peng = $this->arr($w['pengumpulan_data'] ?? null);
        $peny = $this->arr($w['penggunaan_penyimpanan'] ?? null);
        $kirim = $this->arr($w['pengiriman_data'] ?? null);
        $ret = $this->arr($w['retensi_keamanan'] ?? null);
        $triggers = $this->arr($w['risk_triggers'] ?? null);

        $dpoList = $this->arr($r->dpo_list);
        $picList = $this->arr($r->pic_list);
        $sistem = $this->arr($r->sistem_list);
        $retensi = $this->arr($r->retensi_rows);

        $sections = [
            [
                'title' => 'I. Deskripsi Pemrosesan Data Pribadi',
                'rows' => [
                    ['label' => 'Nomor RoPA', 'value' => $this->str($r->registration_number ?: $r->custom_number)],
                    ['label' => 'Nama Aktivitas Pemrosesan', 'value' => $this->str($r->processing_activity ?: ($detail['nama_pemrosesan'] ?? null))],
                    ['label' => 'Deskripsi Aktivitas', 'value' => $this->str($r->description ?: ($detail['deskripsi'] ?? null))],
                    ['label' => 'Entitas', 'value' => $this->str($r->entity ?: ($detail['entity'] ?? null))],
                    ['label' => 'Tanggal Penyusunan', 'value' => $this->date($r->submitted_at ?: $r->created_at)],
                ],
            ],
            [
                'title' => 'II. Pejabat Pelindungan Data & Penanggung Jawab',
                'rows' => [
                    ['label' => 'Pejabat Pelindungan Data (DPO)', 'value' => $this->bullets($dpoList, fn ($d) => trim(($d['name'] ?? '').' — '.($d['email'] ?? '').' '.($d['phone'] ?? ''), ' —'))],
                    ['label' => 'Penanggung Jawab (PIC)', 'value' => $this->bullets($picList, fn ($p) => trim(($p['name'] ?? '').' — '.($p['jabatan'] ?? $p['role'] ?? '').' '.($p['email'] ?? ''), ' —'))],
                    ['label' => 'Divisi', 'value' => $this->str($r->division ?: ($detail['divisi'] ?? null))],
                    ['label' => 'Unit Kerja', 'value' => $this->str($r->work_unit ?: ($detail['unit_kerja'] ?? null))],
                    ['label' => 'Peran / Kategori Pemrosesan', 'value' => $this->str($r->kategori_pemrosesan ?: $r->category)],
                ],
            ],
            [
                'title' => 'III. Informasi Pemrosesan dan Dasar Hukum',
                'rows' => [
                    ['label' => 'Tujuan Pemrosesan', 'value' => $this->str($r->purpose ?: ($info['tujuan'] ?? null))],
                    ['label' => 'Dasar Hukum Pemrosesan', 'value' => $this->str($r->legal_basis ?: ($info['dasar_pemrosesan'] ?? null))],
                    ['label' => 'Detail Dasar Hukum', 'value' => $this->str($r->legal_basis_detail)],
                    ['label' => 'Kategori Aktivitas Pemrosesan', 'value' => $this->bullets($this->arr($info['jenis_pemrosesan'] ?? null), fn ($x) => $this->str($x))],
                    ['label' => 'Bantuan AI', 'value' => $this->yn($info['bantuan_ai'] ?? null)],
                    ['label' => 'Pengambilan Keputusan Otomatis', 'value' => $this->yn($info['otomatis'] ?? null)],
                    ['label' => 'Pemrofilan (Profiling)', 'value' => $this->str($info['pemrofilan'] ?? null)],
                    ['label' => 'Teknologi Baru', 'value' => $this->str($info['teknologi_baru'] ?? null)],
                ],
            ],
            [
                'title' => 'IV. Pengumpulan dan Kategori Data Pribadi',
                'rows' => [
                    ['label' => 'Data Pribadi Umum', 'value' => $this->str($peng['jenis_data_umum'] ?? null)],
                    ['label' => 'Data Pribadi Spesifik', 'value' => $this->str($peng['jenis_data_spesifik'] ?? null)],
                    ['label' => 'Data Identifikasi Pribadi (PII)', 'value' => $this->str($peng['jenis_data_pii'] ?? null)],
                    ['label' => 'Kategori Subjek Data', 'value' => $this->str($peng['jenis_subjek'] ?? null)],
                    ['label' => 'Estimasi Volume Subjek Data', 'value' => $this->str($peng['jumlah_subjek'] ?? null)],
                    ['label' => 'Sumber Perolehan Data', 'value' => $this->str($peng['sumber_data'] ?? null)],
                ],
            ],
            [
                'title' => 'V. Penggunaan Teknologi dan Penyimpanan Data',
                'rows' => [
                    ['label' => 'Sistem & Lokasi Penyimpanan', 'value' => $this->bullets($sistem, fn ($s) => trim(($s['name'] ?? '').' — '.($s['lokasi'] ?? $s['loc'] ?? ''), ' —'))],
                    ['label' => 'Peran sebagai Pemroses', 'value' => $this->str($peny['kategori_pihak'] ?? null)],
                    ['label' => 'Entitas Pemroses', 'value' => $this->str($peny['pihak_pemroses'] ?? null)],
                    ['label' => 'Cara Pemrosesan', 'value' => $this->str($peny['cara_pemrosesan'] ?? null)],
                ],
            ],
            [
                'title' => 'VI. Pengiriman Data dan Pihak Ketiga',
                'rows' => [
                    ['label' => 'Keterlibatan Pihak Ketiga', 'value' => $this->yn($peny['pihak_ketiga'] ?? null)],
                    ['label' => 'Daftar Pihak Ketiga', 'value' => $this->bullets($this->arr($peny['pihak_ketiga_list'] ?? null), fn ($p) => trim(($p['name'] ?? '').' — '.($p['alamat'] ?? $p['address'] ?? '').' '.($p['pic_name'] ?? ''), ' —'))],
                    ['label' => 'Penerima Internal', 'value' => $this->bullets($this->arr($kirim['penerima_internal_list'] ?? null), fn ($p) => trim(($p['division'] ?? '').' — '.($p['pic'] ?? '').' '.($p['email'] ?? ''), ' —'))],
                    ['label' => 'Penerima Eksternal', 'value' => $this->bullets($this->arr($kirim['penerima_eksternal_list'] ?? null), fn ($p) => trim(($p['org'] ?? '').' — '.($p['pic'] ?? '').' '.($p['email'] ?? ''), ' —'))],
                    ['label' => 'Transfer Lintas Batas Negara', 'value' => $this->yn($kirim['transfer_luar'] ?? null)],
                    ['label' => 'Negara Tujuan Transfer', 'value' => $this->str($kirim['negara_tujuan'] ?? null)],
                ],
            ],
            [
                'title' => 'VII. Retensi dan Keamanan Data',
                'rows' => [
                    ['label' => 'Retensi Data', 'value' => $this->bullets($retensi, fn ($x) => trim(($x['name'] ?? '').' — '.($x['durasi'] ?? '').' '.($x['trigger'] ?? ''), ' —'))],
                    ['label' => 'Periode Retensi', 'value' => $this->str($ret['masa_retensi_text'] ?? $r->retention_period)],
                    ['label' => 'Prosedur Pemusnahan', 'value' => $this->str($ret['penghapusan_answer'] ?? ($ret['prosedur_pemusnahan'] ?? null))],
                    ['label' => 'Pengendalian Keamanan', 'value' => $this->str($r->security_measures)],
                    ['label' => 'Riwayat Insiden Sebelumnya', 'value' => $this->yn($ret['pernah_insiden'] ?? null)],
                ],
            ],
            [
                'title' => 'VIII. Penilaian Risiko & Status Dokumen',
                'rows' => [
                    ['label' => 'Tingkat Risiko Pemrosesan', 'value' => strtoupper($this->str($r->risk_level)) ?: '-'],
                    ['label' => 'Justifikasi / Pemicu Risiko', 'value' => $this->str($triggers['reasons'] ?? ($this->arr($w['risk_triggers'] ?? null)['reasons'] ?? null))],
                    ['label' => 'Status', 'value' => $this->str($r->status)],
                    ['label' => 'Progress', 'value' => $r->progress !== null ? round((float) $r->progress).'%' : '-'],
                    ['label' => 'Dibuat', 'value' => $this->date($r->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($r->updated_at)],
                ],
            ],
        ];

        return array_merge($sections, $this->additionalFieldSections($r, 'ropa'));
    }

    // ------------------------------------------------------------------- DPIA

    public function dpia(Dpia $d): array
    {
        $w = $this->arr($d->wizard_data);
        $info = $this->arr($w['informasi_dpia'] ?? null);
        $koneksi = $this->arr($w['koneksi_ropa'] ?? null);
        $potensi = $this->arr($w['potensi_risiko'] ?? null);
        $riskAssessment = $this->arr($d->risk_assessment);

        // DPO / PIC (informasi_dpia may store single or list).
        $dpoList = $this->arr($info['dpo_list'] ?? null);
        $picList = $this->arr($info['pic_list'] ?? null);
        $dpoName = $dpoList[0]['name'] ?? ($info['dpo_name'] ?? null);
        $dpoEmail = $dpoList[0]['email'] ?? ($info['dpo_email'] ?? null);
        $picName = $picList[0]['name'] ?? ($info['pic_name'] ?? null);

        // Section: 21 risk categories with status + description.
        $answerLabel = [
            'sudah' => 'Sudah Memenuhi', 'sebagian' => 'Memenuhi Sebagian',
            'belum' => 'Belum Memenuhi', 'tidak_berlaku' => 'Tidak Berlaku',
        ];
        $catRows = [];
        $counts = ['sudah' => 0, 'sebagian' => 0, 'belum' => 0, 'tidak_berlaku' => 0];
        foreach (Dpia::RISK_CATEGORIES as $cat) {
            $entry = $this->arr($potensi[$cat] ?? null);
            $ans = (string) ($entry['answer'] ?? '');
            if (isset($counts[$ans])) {
                $counts[$ans]++;
            }
            $status = $answerLabel[$ans] ?? 'Belum Dinilai';
            $desc = $this->str($entry['description'] ?? null);
            $catRows[] = ['label' => $cat, 'value' => 'Status: '.$status.($desc !== '' ? "\nKeterangan: ".$desc : '')];
        }

        // Section: connected RoPAs.
        $connected = $this->arr($koneksi['connected_ropas'] ?? null);
        if (empty($connected) && $d->relationLoaded('ropas')) {
            $connected = $d->ropas->map(fn ($r) => [
                'registration_number' => $r->registration_number,
                'processing_activity' => $r->processing_activity,
                'division' => $r->division,
                'risk_level' => $r->risk_level,
            ])->all();
        }

        // Section: risk assessment matrix.
        $risks = $this->arr($riskAssessment['risks'] ?? null);

        $sections = [
            [
                'title' => 'I. Informasi DPIA',
                'rows' => [
                    ['label' => 'Nomor Registrasi', 'value' => $this->str($d->registration_number ?: $d->custom_number)],
                    ['label' => 'Deskripsi', 'value' => $this->str($d->description)],
                    ['label' => 'Penanggung Jawab (PIC)', 'value' => $this->str($picName)],
                    ['label' => 'DPO', 'value' => $this->str($dpoName)],
                    ['label' => 'Email DPO', 'value' => $this->str($dpoEmail)],
                    ['label' => 'Tingkat Risiko', 'value' => strtoupper($this->str($d->risk_level)) ?: '-'],
                    ['label' => 'Regulasi', 'value' => $this->str($d->regulation_code)],
                ],
            ],
            [
                'title' => 'II. Koneksi RoPA',
                'rows' => [
                    ['label' => 'RoPA Terkait', 'value' => $this->bullets($connected, fn ($r) => trim(($r['registration_number'] ?? '').' — '.($r['processing_activity'] ?? '').' ('.strtoupper((string) ($r['risk_level'] ?? '-')).')', ' —'))],
                ],
            ],
            [
                'title' => 'III. Penilaian Potensi Risiko (21 Kategori)',
                'rows' => $catRows,
            ],
            [
                'title' => 'IV. Ringkasan Penilaian',
                'rows' => [
                    ['label' => 'Sudah Memenuhi', 'value' => (string) $counts['sudah']],
                    ['label' => 'Memenuhi Sebagian', 'value' => (string) $counts['sebagian']],
                    ['label' => 'Belum Memenuhi', 'value' => (string) $counts['belum']],
                    ['label' => 'Tidak Berlaku', 'value' => (string) $counts['tidak_berlaku']],
                ],
            ],
            [
                'title' => 'V. Matriks Penilaian Risiko',
                'rows' => empty($risks)
                    ? [['label' => 'Risiko', 'value' => '-']]
                    : array_map(fn ($rk) => [
                        'label' => $this->str($rk['risk'] ?? $rk['risiko'] ?? 'Risiko'),
                        'value' => 'Likelihood: '.$this->str($rk['likelihood'] ?? '-').' | Impact: '.$this->str($rk['impact'] ?? '-')
                            .' | Skor: '.$this->str($rk['score'] ?? '-')."\nMitigasi: ".$this->str($rk['mitigation'] ?? $rk['mitigasi'] ?? '-')
                            .' | Status: '.$this->str($rk['status'] ?? '-'),
                    ], $risks),
            ],
            [
                'title' => 'VI. Status Dokumen',
                'rows' => [
                    ['label' => 'Status', 'value' => $this->str($d->status)],
                    ['label' => 'Progress', 'value' => $d->progress !== null ? round((float) $d->progress).'%' : '-'],
                    ['label' => 'Disetujui Pada', 'value' => $this->date($d->approved_at)],
                    ['label' => 'Dibuat', 'value' => $this->date($d->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($d->updated_at)],
                ],
            ],
        ];

        return array_merge($sections, $this->additionalFieldSections($d, 'dpia'));
    }

    public function lia(LiaAssessment $x): array
    {
        $purpose = $this->arr($x->purpose_test);
        $necessity = $this->arr($x->necessity_test);
        $balancing = $this->arr($x->balancing_test);
        $riskEvents = $this->arr($x->balancing_risk_events);
        $notes = $this->arr($x->answer_notes);

        $verdictLabel = [
            LiaAssessment::VERDICT_PASS => 'LULUS',
            LiaAssessment::VERDICT_FAIL => 'TIDAK LULUS',
        ];
        $conclusionLabel = fn ($v) => ($v === null || $v === '')
            ? 'Belum Diputuskan'
            : ($verdictLabel[$v] ?? strtoupper((string) $v));

        // Build per-question rows for one LIA test from the DEFAULT_QUESTIONS
        // catalog (platform-level single source of truth — mirrors the answers
        // stored in the purpose_test / necessity_test JSON keyed by
        // question_code, plus dedicated balancing columns).
        $testRows = function (string $test) use ($x, $purpose, $necessity, $balancing, $riskEvents, $notes) {
            $store = ['purpose' => $purpose, 'necessity' => $necessity, 'balancing' => $balancing][$test] ?? [];
            $rows = [];
            foreach (LiaAssessment::DEFAULT_QUESTIONS as $q) {
                if (($q['test'] ?? null) !== $test) {
                    continue;
                }
                $code = $q['question_code'];
                $label = $this->str($q['label'] ?? $code);

                // Risk Register lives in the dedicated balancing_risk_events column.
                if (($q['input_type'] ?? null) === 'risk_register') {
                    $val = $this->bullets($riskEvents, function ($r) {
                        $l = (int) ($r['likelihood'] ?? 0);
                        $i = (int) ($r['impact'] ?? 0);

                        return trim($this->str($r['risk'] ?? $r['name'] ?? '')
                            .' — Likelihood: '.$l.' | Impact: '.$i.' | Skor: '.($l * $i)
                            .' | Mitigasi: '.$this->str($r['mitigation'] ?? '-'), ' —');
                    });
                } elseif ($code === 'subject_loses_control') {
                    // Dedicated columns (storage=column) rather than the JSON blob.
                    $val = $this->yn($x->subject_loses_control);
                    $reason = $this->str($x->subject_loses_control_reason);
                    if ($reason !== '') {
                        $val = trim($val."\nAlasan: ".$reason, "\n");
                    }
                } else {
                    // JSON-stored answers, keyed by question_code; append the
                    // reason_code / other_code companion values when present.
                    $val = $this->str($store[$code] ?? null);
                    if (! empty($q['reason_code'])) {
                        $reason = $this->str($store[$q['reason_code']] ?? null);
                        if ($reason !== '') {
                            $val = trim($val."\nAlasan: ".$reason, "\n");
                        }
                    }
                    if (! empty($q['other_code'])) {
                        $other = $this->str($store[$q['other_code']] ?? null);
                        if ($other !== '') {
                            $val = trim($val."\nLainnya: ".$other, "\n");
                        }
                    }
                }

                // Per-question free-text note (answer_notes map, keyed by question_code).
                $note = $this->str($notes[$code] ?? null);
                if ($note !== '') {
                    $val = trim($val."\nKeterangan: ".$note, "\n");
                }

                $rows[] = ['label' => $label, 'value' => $val !== '' ? $val : '-'];
            }

            return $rows;
        };

        return [
            [
                'title' => 'I. Informasi Legitimate Interest Assessment (LIA)',
                'rows' => [
                    ['label' => 'Kode LIA', 'value' => $this->str($x->lia_code)],
                    ['label' => 'Judul', 'value' => $this->str($x->title)],
                    ['label' => 'Deskripsi', 'value' => $this->str($x->description)],
                    ['label' => 'Aktivitas Pemrosesan', 'value' => $this->str($x->processing_activity)],
                    ['label' => 'RoPA Terkait', 'value' => $this->str($x->ropa ? trim(($x->ropa->registration_number ?? '').' — '.($x->ropa->processing_activity ?? ''), ' —') : null)],
                    ['label' => 'DPIA Terkait', 'value' => $this->str($x->dpia?->registration_number ?? $x->linked_dpia_id)],
                    ['label' => 'Status', 'value' => strtoupper($this->str($x->status)) ?: '-'],
                    ['label' => 'Hasil Akhir (Verdict)', 'value' => $conclusionLabel($x->overallVerdict())],
                    ['label' => 'Skor Overall', 'value' => $x->overall_score !== null ? number_format((float) $x->overall_score, 2) : '-'],
                ],
            ],
            [
                'title' => 'II. Dasar Kepentingan Sah (Legitimate Interest)',
                'rows' => [
                    ['label' => 'Terdapat Dasar Kepentingan Sah', 'value' => $this->yn($x->legitimate_interest_basis)],
                    ['label' => 'Uraian / Alasan Kepentingan Sah', 'value' => $this->str($x->legitimate_interest_reason)],
                ],
            ],
            [
                'title' => 'III. Uji Tujuan (Purpose Test)',
                'rows' => array_merge(
                    [['label' => 'Verdict Uji Tujuan', 'value' => $conclusionLabel($x->conclusion_purpose)]],
                    $testRows('purpose'),
                ),
            ],
            [
                'title' => 'IV. Uji Kebutuhan (Necessity Test)',
                'rows' => array_merge(
                    [['label' => 'Verdict Uji Kebutuhan', 'value' => $conclusionLabel($x->conclusion_necessity)]],
                    $testRows('necessity'),
                ),
            ],
            [
                'title' => 'V. Uji Keseimbangan (Balancing Test)',
                'rows' => array_merge(
                    [['label' => 'Verdict Uji Keseimbangan', 'value' => $conclusionLabel($x->conclusion_balancing)]],
                    $testRows('balancing'),
                ),
            ],
            [
                'title' => 'VI. Kesimpulan & Catatan Approver',
                'rows' => [
                    ['label' => 'Verdict Uji Tujuan', 'value' => $conclusionLabel($x->conclusion_purpose)],
                    ['label' => 'Verdict Uji Kebutuhan', 'value' => $conclusionLabel($x->conclusion_necessity)],
                    ['label' => 'Verdict Uji Keseimbangan', 'value' => $conclusionLabel($x->conclusion_balancing)],
                    ['label' => 'Catatan Approver', 'value' => $this->str($x->conclusion_notes)],
                    ['label' => 'Alasan Penolakan', 'value' => $this->str($x->rejection_reason)],
                ],
            ],
            [
                'title' => 'VII. Alur Kerja (RACI)',
                'rows' => [
                    ['label' => 'Maker', 'value' => trim($this->str($x->maker?->name).' — '.$this->date($x->submitted_at), ' —')],
                    ['label' => 'Checker', 'value' => trim($this->str($x->checker?->name).' — '.$this->date($x->checked_at), ' —')],
                    ['label' => 'Approver', 'value' => trim($this->str($x->approver?->name).' — '.$this->date($x->approved_at), ' —')],
                ],
            ],
            [
                'title' => 'VIII. Status Dokumen',
                'rows' => [
                    ['label' => 'Status', 'value' => strtoupper($this->str($x->status)) ?: '-'],
                    ['label' => 'Terkunci', 'value' => $this->yn($x->is_locked)],
                    ['label' => 'Tanggal Submit', 'value' => $this->date($x->submitted_at)],
                    ['label' => 'Tanggal Disetujui', 'value' => $this->date($x->approved_at)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function tia(TiaAssessment $x): array
    {
        // ---- transfer context (transfer_details is an assoc array of aspect => value)
        $td = $this->arr($x->transfer_details);

        // ---- source reference (RoPA / Cross-Border / Pihak Ketiga relation)
        $sourceRef = '—';
        if ($x->relationLoaded('ropa') && $x->ropa) {
            $sourceRef = 'RoPA: '.trim(($x->ropa->registration_number ?: $x->ropa->custom_number).' — '.$x->ropa->processing_activity, ' —');
        } elseif ($x->relationLoaded('crossBorder') && $x->crossBorder) {
            $sourceRef = 'Transfer Lintas Negara: '.trim(($x->crossBorder->destination_entity ?? '').' — '.($x->crossBorder->destination_country ?? ''), ' —');
        } elseif ($x->relationLoaded('vendor') && $x->vendor) {
            $sourceRef = 'Pihak Ketiga: '.$this->str($x->vendor->name);
        }

        // ---- adequacy conclusion derived from UU PDP + supervisory authority
        $law = $x->destination_has_pdp_law;
        $auth = $x->destination_has_pdp_authority;
        if ($law === true && $auth === true) {
            $adequacy = 'Memadai — negara tujuan memiliki UU perlindungan data pribadi dan otoritas pengawas independen.';
        } elseif ($law === true || $auth === true) {
            $adequacy = 'Sebagian memadai — kerangka perlindungan belum lengkap, diperlukan safeguard tambahan (mis. klausul kontraktual).';
        } elseif ($law === false && $auth === false) {
            $adequacy = 'Tidak memadai — tidak ada kerangka perlindungan setara, wajib menerapkan safeguard kontraktual/teknis.';
        } else {
            $adequacy = '—';
        }

        // ---- effective metric catalog (default − disabled + custom, per org) with
        //      per-metric answers + notes. Default metric score = dedicated column;
        //      custom metric score = risk_assessment.custom_metric_scores[code].
        $customScores = $x->customMetricScores();
        $notes = $this->arr($x->answer_notes);
        $riskRows = [];
        $secRows = [];
        foreach (TiaAssessment::effectiveMetrics($x->org_id) as $m) {
            $code = (string) ($m['metric_code'] ?? '');
            $isCustom = ! empty($m['is_custom']);
            $val = $isCustom ? ($customScores[$code] ?? null) : ($x->{$code} ?? null);
            $label = $this->str($m['label'] ?? $code);
            $weight = (float) ($m['weight'] ?? 1);
            if ($weight != 1.0) {
                $label .= ' (bobot '.rtrim(rtrim(number_format($weight, 2), '0'), '.').')';
            }
            $value = 'Skor: '.($val === null || $val === '' ? 'belum diisi' : $this->str($val).' / 10');
            $note = $this->str($notes[$code] ?? null);
            if ($note !== '') {
                $value .= "\nKeterangan: ".$note;
            }
            $row = ['label' => $label, 'value' => $value];
            if (($m['kind'] ?? 'risk') === 'security') {
                $secRows[] = $row;
            } else {
                $riskRows[] = $row;
            }
        }
        if (empty($riskRows)) {
            $riskRows[] = ['label' => 'Metrik Risiko', 'value' => '—'];
        }
        if (empty($secRows)) {
            $secRows[] = ['label' => 'Metrik Keamanan', 'value' => '—'];
        }

        // ---- verdict + overall risk
        $verdictMap = [
            TiaAssessment::VERDICT_APPROVED => 'Disetujui — Aman untuk Transfer',
            TiaAssessment::VERDICT_CONDITIONAL => 'Bersyarat — Disetujui dengan Mitigasi',
            TiaAssessment::VERDICT_REJECTED => 'Ditolak — Transfer Diblokir',
        ];
        $verdict = $verdictMap[$x->conclusion_verdict] ?? 'Belum Diputuskan';
        $score = $x->overall_risk_score !== null ? $x->overall_risk_score : $x->computeOverallRisk();
        $overall = ($score === null || $score === '') ? '—' : number_format((float) $score, 2).' / 10';
        $level = $x->overall_risk_level ?: $x->riskLevel();

        return [
            [
                'title' => 'I. Identitas & Konteks Transfer',
                'rows' => [
                    ['label' => 'Kode TIA', 'value' => $this->str($x->tia_code ?: $x->id)],
                    ['label' => 'Judul', 'value' => $this->str($x->title)],
                    ['label' => 'Deskripsi', 'value' => $this->str($x->description)],
                    ['label' => 'Negara/Wilayah Tujuan', 'value' => $this->str($x->destination_country)],
                    ['label' => 'Dasar Transfer', 'value' => $this->str($x->transfer_basis ? ucwords(str_replace('_', ' ', (string) $x->transfer_basis)) : null).($this->str($x->transfer_basis_other) !== '' ? ' — '.$this->str($x->transfer_basis_other) : '')],
                    ['label' => 'Volume Transfer', 'value' => $this->str($x->transfer_volume)],
                    ['label' => 'Frekuensi Transfer', 'value' => $this->str($x->transfer_frequency)],
                    ['label' => 'Sumber Penilaian', 'value' => $sourceRef],
                    ['label' => 'Detail Transfer', 'value' => $this->bullets(
                        array_map(fn ($k, $v) => ['k' => $k, 'v' => $v], array_keys($td), array_values($td)),
                        fn ($p) => ucfirst(str_replace('_', ' ', (string) $p['k'])).': '.$this->str($p['v'])
                    )],
                ],
            ],
            [
                'title' => 'II. Status Adekuasi Negara Tujuan',
                'rows' => [
                    ['label' => 'Negara Memiliki UU PDP', 'value' => $this->yn($x->destination_has_pdp_law)],
                    ['label' => 'Negara Memiliki Otoritas Pengawas', 'value' => $this->yn($x->destination_has_pdp_authority)],
                    ['label' => 'Kesimpulan Adekuasi', 'value' => $adequacy],
                    ['label' => 'Skor Kematangan Penerima', 'value' => $x->recipient_maturity_score !== null ? $this->str($x->recipient_maturity_score).' / 10' : ''],
                    ['label' => 'Skor Kematangan Pengirim', 'value' => $x->sender_maturity_score !== null ? $this->str($x->sender_maturity_score).' / 10' : ''],
                ],
            ],
            [
                'title' => 'III. Penilaian Risiko (Skor 1-10, 10 = paling berisiko)',
                'rows' => $riskRows,
            ],
            [
                'title' => 'IV. Penilaian Keamanan / Mitigasi (Skor 1-10, 10 = paling aman)',
                'rows' => $secRows,
            ],
            [
                'title' => 'V. Kerangka Hukum & Langkah Tambahan',
                'rows' => [
                    ['label' => 'Kerangka Hukum', 'value' => $this->bullets($this->arr($x->legal_framework), fn ($i) => $this->str($i))],
                    ['label' => 'Langkah Tambahan (Supplementary Measures)', 'value' => $this->bullets($this->arr($x->supplementary_measures), fn ($i) => $this->str($i))],
                ],
            ],
            [
                'title' => 'VI. Kesimpulan & Keputusan',
                'rows' => [
                    ['label' => 'Skor Risiko Residual Keseluruhan', 'value' => $overall],
                    ['label' => 'Tingkat Risiko', 'value' => strtoupper($this->str($level)) ?: '-'],
                    ['label' => 'Verdict Approver', 'value' => $verdict],
                    ['label' => 'Catatan Kesimpulan', 'value' => $this->str($x->conclusion_notes)],
                ],
            ],
            [
                'title' => 'VII. Workflow RACI & Status Dokumen',
                'rows' => [
                    ['label' => 'Maker', 'value' => trim($this->str($x->relationLoaded('maker') && $x->maker ? $x->maker->name : null).' — '.$this->date($x->submitted_at), ' —')],
                    ['label' => 'Checker', 'value' => trim($this->str($x->relationLoaded('checker') && $x->checker ? $x->checker->name : null).' — '.$this->date($x->checked_at), ' —')],
                    ['label' => 'Approver', 'value' => trim($this->str($x->relationLoaded('approver') && $x->approver ? $x->approver->name : null).' — '.$this->date($x->approved_at), ' —')],
                    ['label' => 'Status', 'value' => strtoupper($this->str($x->status))],
                    ['label' => 'Terkunci', 'value' => $this->yn($x->is_locked)],
                    ['label' => 'Alasan Penolakan', 'value' => $this->str($x->rejection_reason)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function maturity(MaturityAssessment $x): array
    {
        $domainLabels = MaturityQuestion::DOMAIN_LABELS;
        $levelLabels = MaturityAssessment::LEVEL_LABELS;

        $inputLabels = [
            'questionnaire' => 'Kuesioner DPO',
            'document' => 'Upload Dokumen + AI',
            'auto_derive' => 'Auto-derive dari Data Nexus',
        ];
        $sourceLabels = [
            'manual' => 'Manual',
            'auto_derive' => 'Auto-derive',
            'document_ai' => 'AI Dokumen',
        ];

        $overallScore = $x->overall_score !== null ? number_format((float) $x->overall_score, 2) : '';
        $levelLabel = $x->overall_level !== null ? ($levelLabels[$x->overall_level] ?? '') : '';
        $levelValue = $x->overall_level !== null
            ? ('Level '.$x->overall_level.($levelLabel !== '' ? ' — '.$levelLabel : ''))
            : '';

        // Skor per domain (domain_scores keyed by domain code; custom domains
        // fall back to their raw key label).
        $domainScores = $this->arr($x->domain_scores);
        $domainRows = [];
        foreach ($domainScores as $key => $score) {
            $label = $domainLabels[$key] ?? $key;
            $domainRows[] = [
                'label' => $this->str($label),
                'value' => $score !== null ? number_format((float) $score, 2).' / 10' : 'Belum dinilai',
            ];
        }
        if (empty($domainRows)) {
            $domainRows[] = ['label' => 'Skor Domain', 'value' => 'Belum dinilai'];
        }

        // Detail jawaban per pertanyaan (child table maturity_question_responses),
        // dikelompokkan per domain mengikuti urutan domain default lalu sisanya.
        $responses = $x->relationLoaded('responses') ? $x->responses : $x->responses()->get();
        $grouped = $responses->groupBy('domain');
        $orderedDomains = array_keys($domainLabels);
        foreach ($grouped->keys() as $dk) {
            if (! in_array($dk, $orderedDomains, true)) {
                $orderedDomains[] = $dk;
            }
        }
        $answerRows = [];
        foreach ($orderedDomains as $dk) {
            $items = $grouped->get($dk);
            if (! $items || $items->isEmpty()) {
                continue;
            }
            foreach ($items as $r) {
                $src = $sourceLabels[$r->source] ?? $this->str($r->source);
                $notes = $this->str($r->notes);
                $val = 'Skor: '.$this->str($r->score).' / 10 | Sumber: '.($src !== '' ? $src : '-')
                    .($notes !== '' ? "\nCatatan: ".$notes : '');
                $domLabel = $domainLabels[$dk] ?? $dk;
                $answerRows[] = [
                    'label' => $this->str($r->question_code).' ('.$this->str($domLabel).')',
                    'value' => $val,
                ];
            }
        }
        if (empty($answerRows)) {
            $answerRows[] = ['label' => 'Jawaban', 'value' => 'Belum ada jawaban'];
        }

        // Rekomendasi (array of string or {text}).
        $recommendations = $this->arr($x->recommendations);

        return [
            [
                'title' => 'I. Informasi Maturity Assessment',
                'rows' => [
                    ['label' => 'Judul', 'value' => $this->str($x->title)],
                    ['label' => 'Deskripsi', 'value' => $this->str($x->description)],
                    ['label' => 'Versi', 'value' => $this->str($x->version)],
                    ['label' => 'Metode Input', 'value' => $this->str($inputLabels[$x->input_method] ?? $x->input_method)],
                    ['label' => 'Status', 'value' => strtoupper($this->str($x->status)) ?: '-'],
                    ['label' => 'Tanggal Submit', 'value' => $this->date($x->submitted_at)],
                    ['label' => 'Disubmit oleh', 'value' => $this->str($x->relationLoaded('submitter') ? optional($x->submitter)->name : optional($x->submitter)->name)],
                ],
            ],
            [
                'title' => 'II. Skor Kematangan Keseluruhan',
                'rows' => [
                    ['label' => 'Skor Overall (dari 10)', 'value' => $overallScore !== '' ? $overallScore.' / 10' : '-'],
                    ['label' => 'Tingkat Kematangan', 'value' => $levelValue ?: '-'],
                ],
            ],
            [
                'title' => 'III. Skor per Domain',
                'rows' => $domainRows,
            ],
            [
                'title' => 'IV. Detail Jawaban per Pertanyaan',
                'rows' => $answerRows,
            ],
            [
                'title' => 'V. Rekomendasi',
                'rows' => [
                    ['label' => 'Rekomendasi', 'value' => $this->bullets($recommendations, fn ($rec) => is_array($rec) ? $this->str($rec['text'] ?? $rec) : $this->str($rec)) ?: '-'],
                ],
            ],
            [
                'title' => 'VI. Status Dokumen',
                'rows' => [
                    ['label' => 'Status', 'value' => $this->str($x->status)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function gap(GapAssessment $x): array
    {
        $code = $x->regulation_code ?: 'uupdp';
        $answers = $this->arr($x->answers);
        $notes = $this->arr($x->answer_notes);
        $aiAnalyses = $this->arr($x->ai_analyses);
        $recommendations = $this->arr($x->recommendations);

        // Effective question set for this org (default bank + per-org overrides + custom questions),
        // mirroring how the score and the on-screen report are computed.
        $questions = GapAssessment::effectiveQuestions($x->org_id, $code);

        // User-answer, AI-verdict, priority and compliance-level label maps.
        $answerLabel = [
            'yes' => 'Sudah Memenuhi', 'partial' => 'Memenuhi Sebagian',
            'no' => 'Belum Memenuhi', 'na' => 'Tidak Berlaku',
        ];
        $aiLabel = [
            'comply' => 'Memenuhi', 'partial' => 'Memenuhi Sebagian',
            'non_comply' => 'Belum Memenuhi', 'unsure' => 'AI Tidak Yakin',
        ];
        $priorityLabel = ['critical' => 'Kritis', 'high' => 'Tinggi', 'medium' => 'Sedang'];
        $complianceLabel = [
            'high' => 'Tinggi (Kepatuhan Baik)',
            'medium' => 'Sedang (Kepatuhan Parsial)',
            'low' => 'Rendah (Banyak Kesenjangan)',
        ];

        // Tally answers by status across the effective questions.
        $counts = ['yes' => 0, 'partial' => 0, 'no' => 0, 'na' => 0, 'unanswered' => 0];
        foreach ($questions as $q) {
            $a = $answers[$q['id']] ?? null;
            if ($a === null || $a === '') {
                $counts['unanswered']++;
            } elseif (isset($counts[$a])) {
                $counts[$a]++;
            }
        }

        // Per-question detail: category/article context + user answer + AI verdict + note.
        $questionRows = [];
        foreach ($questions as $q) {
            $qId = $q['id'];
            $a = (string) ($answers[$qId] ?? '');
            $status = $answerLabel[$a] ?? 'Belum Dinilai';
            $note = $this->str($notes[$qId] ?? null);
            $aiVerdict = GapAssessment::aggregateAiVerdict($aiAnalyses[$qId] ?? null);
            $val = 'Kategori: '.($this->str($q['category'] ?? null) ?: '-')
                .' | Pasal: '.($this->str($q['article'] ?? null) ?: '-')
                ."\nJawaban: ".$status;
            if ($aiVerdict) {
                $val .= "\nVerdict AI: ".($aiLabel[$aiVerdict] ?? $aiVerdict);
            }
            if ($note !== '') {
                $val .= "\nCatatan: ".$note;
            }
            $questionRows[] = [
                'label' => $this->str($qId).' — '.$this->str($q['question'] ?? null),
                'value' => $val,
            ];
        }
        if (empty($questionRows)) {
            $questionRows[] = ['label' => 'Pertanyaan', 'value' => '-'];
        }

        // AI document verification: one row per question that has analyzed evidence.
        $qTextById = [];
        foreach ($questions as $q) {
            $qTextById[$q['id']] = $q['question'] ?? $q['id'];
        }
        $aiRows = [];
        foreach ($aiAnalyses as $qId => $entries) {
            // Normalise to a list of entries (legacy single object vs. array of objects).
            $list = $this->arr($entries);
            if (isset($list['status'])) {
                $list = [$list];
            }
            $list = array_values(array_filter($list, fn ($e) => is_array($e)));
            if (empty($list)) {
                continue;
            }
            $worst = GapAssessment::aggregateAiVerdict($entries);
            $ua = (string) ($answers[$qId] ?? '');
            $lines = [
                'Jawaban Anda: '.($answerLabel[$ua] ?? '-'),
                'Verdict AI (worst-case): '.($worst ? ($aiLabel[$worst] ?? $worst) : 'AI Tidak Yakin'),
            ];
            foreach ($list as $ai) {
                $fname = isset($ai['attachment_path']) ? basename((string) $ai['attachment_path']) : '';
                $fname = $fname !== '' ? $fname : 'Dokumen';
                $lines[] = '— '.$fname.': '.($aiLabel[$ai['status'] ?? ''] ?? ($this->str($ai['status'] ?? null) ?: '-'));
                if (! empty($ai['analysis'])) {
                    $lines[] = '  '.$this->str($ai['analysis']);
                }
                $cited = $this->arr($ai['cited_passages'] ?? null);
                if (! empty($cited)) {
                    $lines[] = '  Kutipan: '.implode(' | ', array_slice(array_map(fn ($c) => $this->str($c), $cited), 0, 2));
                }
            }
            $aiRows[] = [
                'label' => $this->str($qTextById[$qId] ?? $qId),
                'value' => implode("\n", $lines),
            ];
        }

        return [
            [
                'title' => 'I. Informasi Asesmen Kesenjangan (GAP)',
                'rows' => [
                    ['label' => 'Regulasi Rujukan', 'value' => $this->str($x->regulation_code) ?: 'UU PDP'],
                    ['label' => 'Versi', 'value' => $this->str($x->version)],
                    ['label' => 'Deskripsi', 'value' => $this->str($x->description)],
                    ['label' => 'Skor Keseluruhan', 'value' => $x->overall_score !== null ? $this->str($x->overall_score).'%' : '-'],
                    ['label' => 'Tingkat Kepatuhan', 'value' => $complianceLabel[(string) $x->compliance_level] ?? (strtoupper($this->str($x->compliance_level)) ?: '-')],
                    ['label' => 'Progress', 'value' => $x->progress !== null ? round((float) $x->progress).'%' : '-'],
                    ['label' => 'Tanggal Finalisasi', 'value' => $this->date($x->finalized_at)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
            [
                'title' => 'II. Ringkasan Kepatuhan',
                'rows' => [
                    ['label' => 'Total Pertanyaan', 'value' => (string) count($questions)],
                    ['label' => 'Sudah Memenuhi', 'value' => (string) $counts['yes']],
                    ['label' => 'Memenuhi Sebagian', 'value' => (string) $counts['partial']],
                    ['label' => 'Belum Memenuhi', 'value' => (string) $counts['no']],
                    ['label' => 'Tidak Berlaku', 'value' => (string) $counts['na']],
                    ['label' => 'Belum Dijawab', 'value' => (string) $counts['unanswered']],
                ],
            ],
            [
                'title' => 'III. Detail Penilaian per Pertanyaan',
                'rows' => $questionRows,
            ],
            [
                'title' => 'IV. Rekomendasi Perbaikan',
                'rows' => empty($recommendations)
                    ? [['label' => 'Rekomendasi', 'value' => '-']]
                    : array_map(fn ($rec) => [
                        'label' => '['.($priorityLabel[$rec['priority'] ?? ''] ?? ($this->str($rec['priority'] ?? null) ?: '-')).'] '
                            .$this->str($rec['article'] ?? null).' — '.$this->str($rec['question'] ?? null),
                        'value' => $this->str($rec['recommendation'] ?? null)
                            .' (Jawaban saat ini: '.($answerLabel[(string) ($rec['current_answer'] ?? '')] ?? '-').')'
                            .(! empty($rec['ai_override']) ? ' [Override berdasarkan AI]' : ''),
                    ], array_values($recommendations)),
            ],
            [
                'title' => 'V. Verifikasi AI Dokumen Pendukung',
                'rows' => empty($aiRows) ? [['label' => 'Analisis AI', 'value' => '-']] : $aiRows,
            ],
        ];
    }

    public function breach(BreachIncident $x): array
    {
        // BreachIncident stores everything in normalized columns (no wizard_data blob).
        // Encrypted fields (description, pic_name) are transparently decrypted by their
        // EncryptedString casts, and linked_ropas is a self-querying appended accessor.
        $sevLabel = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi', 'critical' => 'Kritis'];
        $sev = strtolower($this->str($x->severity));
        $sevText = $sev !== '' ? (($sevLabel[$sev] ?? ucfirst($sev)).' ('.strtoupper($sev).')') : '-';

        $statusLabel = [
            'detected' => 'Terdeteksi', 'assessed' => 'Dinilai',
            'contained' => 'Ditangani (Contained)', 'closed' => 'Ditutup',
        ];
        $st = strtolower($this->str($x->status));
        $stText = $st !== '' ? ($statusLabel[$st] ?? ucfirst($st)) : '-';

        // Containment checklist: assoc map [step => bool | {done,label,category,skipped,raci}].
        // Flatten while preserving the step key so each entry can be listed with its status.
        $checklistRows = [];
        foreach ($this->arr($x->containment_checklist) as $step => $v) {
            $checklistRows[] = ['step' => $step, 'val' => $v];
        }

        return [
            [
                'title' => 'I. Identifikasi Insiden',
                'rows' => [
                    ['label' => 'Kode Insiden', 'value' => $this->str($x->incident_code)],
                    ['label' => 'Judul Insiden', 'value' => $this->str($x->title)],
                    ['label' => 'Kategori Insiden', 'value' => $this->str($x->case_type)],
                    ['label' => 'Sumber Deteksi', 'value' => $this->str($x->source)],
                    ['label' => 'Tingkat Keparahan', 'value' => $sevText],
                    ['label' => 'Status Penanganan', 'value' => $stText],
                    ['label' => 'Data Simulasi', 'value' => $this->yn($x->is_simulation)],
                    ['label' => 'Tanggal Terdeteksi', 'value' => $this->date($x->detected_at)],
                ],
            ],
            [
                'title' => 'II. Deskripsi dan Cakupan Data Terdampak',
                'rows' => [
                    ['label' => 'Uraian Insiden', 'value' => $this->str($x->description)],
                    ['label' => 'Jumlah Subjek Terdampak', 'value' => $x->affected_subjects_count !== null ? number_format((int) $x->affected_subjects_count, 0, ',', '.').' subjek' : '-'],
                    ['label' => 'Jenis Data Pribadi Terdampak', 'value' => $this->str($x->affected_data_types)],
                ],
            ],
            [
                'title' => 'III. RoPA Terkait',
                'rows' => [
                    ['label' => 'Aktivitas Pemrosesan Terkait', 'value' => $this->bullets($this->arr($x->linked_ropas), fn ($r) => trim(($r['registration_number'] ?? '').' — '.($r['processing_activity'] ?? ''), ' —'))],
                ],
            ],
            [
                'title' => 'IV. Analisis Akar Masalah (Root Cause Analysis)',
                'rows' => [
                    ['label' => 'Akar Masalah', 'value' => $this->str($x->root_cause)],
                ],
            ],
            [
                'title' => 'V. Tindakan Penanganan (Containment)',
                'rows' => [
                    ['label' => 'Tindakan Penanganan', 'value' => $this->str($x->containment_actions)],
                    ['label' => 'Daftar Periksa Penanganan', 'value' => $this->bullets($checklistRows, function ($it) {
                        $v = $it['val'];
                        $isArr = is_array($v);
                        $done = $isArr ? (bool) ($v['done'] ?? false) : (bool) $v;
                        $skipped = $isArr ? (bool) ($v['skipped'] ?? false) : false;
                        $label = $isArr ? ($v['label'] ?? $it['step']) : $it['step'];
                        $status = $skipped ? 'Dilewati' : ($done ? 'Selesai' : 'Belum');

                        return $this->str($label).' — '.$status;
                    })],
                    ['label' => 'Langkah Penanganan Tambahan', 'value' => $this->bullets($this->arr($x->containment_steps), fn ($s) => is_array($s) ? $this->str($s['label'] ?? $s['name'] ?? null) : $this->str($s))],
                ],
            ],
            [
                'title' => 'VI. Rencana Remediasi',
                'rows' => [
                    ['label' => 'Rencana Perbaikan dan Pencegahan', 'value' => $this->str($x->remediation_plan)],
                ],
            ],
            [
                'title' => 'VII. Timeline Insiden',
                'rows' => [
                    ['label' => 'Riwayat Peristiwa', 'value' => $this->bullets($this->arr($x->timeline_log), fn ($e) => trim($this->str($e['time'] ?? null).' — '.$this->str($e['event'] ?? null), ' —'))],
                ],
            ],
            [
                'title' => 'VIII. Notifikasi Pelanggaran',
                'rows' => [
                    ['label' => 'Notifikasi Diwajibkan', 'value' => $this->yn($x->notification_required)],
                    ['label' => 'Batas Waktu Notifikasi (3x24 jam)', 'value' => $this->date($x->notification_deadline)],
                    ['label' => 'Notifikasi ke KOMDIGI', 'value' => $x->notified_komdigi_at ? ('Sudah — '.$this->date($x->notified_komdigi_at)) : 'Belum'],
                    ['label' => 'Notifikasi ke Subjek Data', 'value' => $x->notified_subjects_at ? ('Sudah — '.$this->date($x->notified_subjects_at)) : 'Belum'],
                ],
            ],
            [
                'title' => 'IX. Penanggung Jawab Penanganan',
                'rows' => [
                    ['label' => 'PIC Penanganan', 'value' => $this->str($x->pic_name)],
                    ['label' => 'Incident Commander', 'value' => $this->str($x->incident_commander)],
                    ['label' => 'Terdeteksi Oleh', 'value' => $this->str($x->detected_by)],
                ],
            ],
            [
                'title' => 'X. Status dan Riwayat Waktu',
                'rows' => [
                    ['label' => 'Status', 'value' => $stText],
                    ['label' => 'Waktu Dinilai (Assessed)', 'value' => $this->date($x->assessed_at)],
                    ['label' => 'Waktu Ditangani (Contained)', 'value' => $this->date($x->contained_at)],
                    ['label' => 'Waktu Ditutup (Closed)', 'value' => $this->date($x->closed_at)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function dsr(DsrRequest $x): array
    {
        // Indonesian labels for request types (mirrors the frontend detail page).
        $typeLabels = [
            'access' => 'Akses Data', 'correction' => 'Koreksi', 'rectification' => 'Pembetulan',
            'deletion' => 'Penghapusan', 'erasure' => 'Penghapusan', 'portability' => 'Portabilitas',
            'restriction' => 'Pembatasan', 'objection' => 'Keberatan',
            'withdraw_consent' => 'Tarik Persetujuan', 'info' => 'Informasi',
        ];
        $execStatusLabels = [
            'pending' => 'Menunggu', 'executed' => 'Dieksekusi',
            'failed' => 'Gagal', 'skipped' => 'Dilewati',
        ];

        $typeLabel = $typeLabels[$x->request_type] ?? $this->str($x->request_type);

        // Child tables — resolve to plain arrays whether or not eager-loaded.
        $scopes = $x->relationLoaded('scopes') ? $x->scopes->all() : $x->scopes()->get()->all();
        $executions = $x->relationLoaded('executions') ? $x->executions->all() : $x->executions()->get()->all();

        // Additional applicant-supplied data (arbitrary key/value JSON).
        $subjectData = $this->arr($x->subject_data);
        $subjectRows = [];
        foreach ($subjectData as $k => $v) {
            $subjectRows[] = ['k' => (string) $k, 'v' => $v];
        }

        return [
            [
                'title' => 'I. Detail Permintaan Subjek Data',
                'rows' => [
                    ['label' => 'Nomor Permintaan (Request ID)', 'value' => $this->str($x->request_id ?: $x->id)],
                    ['label' => 'Jenis Permintaan', 'value' => $typeLabel],
                    ['label' => 'Aplikasi Sumber', 'value' => $this->str($x->app?->name)],
                    ['label' => 'Tanggal Permintaan Masuk', 'value' => $this->date($x->created_at)],
                    ['label' => 'Keterangan Permohonan', 'value' => $this->str($x->description)],
                ],
            ],
            [
                'title' => 'II. Identitas Pemohon',
                'rows' => [
                    ['label' => 'Nama Pemohon', 'value' => $this->str($x->requester_name)],
                    ['label' => 'Surel Pemohon', 'value' => $this->str($x->requester_email)],
                    ['label' => 'Telepon Pemohon', 'value' => $this->str($x->requester_phone)],
                ],
            ],
            [
                'title' => 'III. Verifikasi Identitas',
                'rows' => [
                    ['label' => 'Status Verifikasi', 'value' => $this->str($x->verification_status)],
                    ['label' => 'Metode Verifikasi', 'value' => $this->str($x->verification_method)],
                    ['label' => 'Diverifikasi Pada', 'value' => $this->date($x->verified_at)],
                    ['label' => 'Kedaluwarsa Verifikasi', 'value' => $this->date($x->verification_expires_at)],
                    ['label' => 'NDA Ditandatangani Pada', 'value' => $this->date($x->nda_signed_at)],
                ],
            ],
            [
                'title' => 'IV. Penanganan, SLA, dan Hasil',
                'rows' => [
                    ['label' => 'Status Permohonan', 'value' => $this->str($x->status)],
                    ['label' => 'Penanggung Jawab', 'value' => $this->str($x->assignee?->name)],
                    ['label' => 'Tenggat Penyelesaian (SLA)', 'value' => $this->date($x->deadline_at)],
                    ['label' => 'Ditanggapi Pada', 'value' => $this->date($x->responded_at)],
                    ['label' => 'Ditutup Pada', 'value' => $this->date($x->closed_at)],
                    ['label' => 'Alasan Penutupan', 'value' => $this->str($x->closed_reason)],
                    ['label' => 'Alasan Penolakan', 'value' => $this->str($x->rejection_reason)],
                    ['label' => 'Tanggapan / Respons', 'value' => $this->str($x->response)],
                ],
            ],
            [
                'title' => 'V. Cakupan Sistem Informasi Terdampak',
                'rows' => [
                    ['label' => 'Sistem Terdampak', 'value' => $this->bullets(
                        $scopes,
                        fn ($s) => trim(
                            ($s->informationSystem?->name ?? $s->information_system_id)
                            .' — Jenis: '.$this->str($this->arr($s->request_types))
                            .' | Shard: '.($this->str($this->arr($s->shards_affected)) ?: '-')
                            .' | Status SQL Pack: '.($this->str($s->sql_pack_status) ?: '-'),
                            ' —'
                        )
                    )],
                ],
            ],
            [
                'title' => 'VI. Log Pelaksanaan per Sistem',
                'rows' => [
                    ['label' => 'Catatan Eksekusi', 'value' => $this->bullets(
                        $executions,
                        fn ($e) => trim(
                            ($e->informationSystem?->name ?? $e->information_system_id)
                            .($e->shard_name ? ' ['.$e->shard_name.']' : '')
                            .' — '.($typeLabels[$e->request_type] ?? $this->str($e->request_type))
                            .' — Status: '.($execStatusLabels[$e->status] ?? $this->str($e->status))
                            .($e->rows_affected ? ' ('.$e->rows_affected.' baris)' : '')
                            .($e->executed_at ? ' | '.$this->date($e->executed_at) : '')
                            .($this->str($e->executed_by_email) !== '' ? ' | oleh '.$this->str($e->executed_by_email) : '')
                            .($this->str($e->notes) !== '' ? "\n  Catatan: ".$this->str($e->notes) : '')
                            .($this->str($e->failure_reason) !== '' ? "\n  Alasan gagal: ".$this->str($e->failure_reason) : ''),
                            ' —'
                        )
                    )],
                ],
            ],
            [
                'title' => 'VII. Data Tambahan Pemohon',
                'rows' => [
                    ['label' => 'Data Subjek', 'value' => $this->bullets(
                        $subjectRows,
                        fn ($r) => $r['k'].': '.$this->str($r['v'])
                    )],
                ],
            ],
            [
                'title' => 'VIII. Status Dokumen',
                'rows' => [
                    ['label' => 'Status', 'value' => $this->str($x->status)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function dataDiscovery(InformationSystem $x): array
    {
        $scan = $this->arr($x->scan_results);
        $ai = $this->arr($x->ai_scan_results);
        $config = $this->arr($x->connection_config);
        $protection = $this->arr($x->protection_assessments);

        $tables = $this->arr($scan['tables'] ?? null);
        $aiTables = $this->arr($ai['tables'] ?? null);
        $access = $this->arr($scan['access_paths'] ?? null);
        $enc = $this->arr($scan['encryption'] ?? null);
        $diffAlerts = $this->arr($scan['diff_alerts'] ?? null);

        // Label maps for the recommendation-vs-applied lifecycle of each column.
        $appliedLabel = [
            'applied_pribadi' => 'Diterapkan: Data Pribadi',
            'applied_sensitive' => 'Diterapkan: Data Sensitif',
            'not_pii' => 'Ditandai: Bukan PII',
            'rejected' => 'Rekomendasi Ditolak',
            'pending' => 'Rekomendasi (Belum Direview)',
        ];
        $appliedStatus = fn ($c) => $appliedLabel[(string) ($c['applied_status'] ?? '')] ?? 'Rekomendasi (Belum Direview)';

        // Aggregate counts across every scanned table/column.
        $colCount = 0;
        $piiCount = 0;
        $encReqCount = 0;
        $manualCount = 0;
        $totalRows = 0;
        $applied = ['applied_pribadi' => 0, 'applied_sensitive' => 0, 'not_pii' => 0, 'rejected' => 0];
        $pdpBuckets = [];
        foreach ($tables as $t) {
            $totalRows += (int) ($t['row_count'] ?? 0);
            foreach ($this->arr($t['columns'] ?? null) as $c) {
                $colCount++;
                if (! empty($c['pii_detected'])) {
                    $piiCount++;
                }
                if (! empty($c['encryption_required'])) {
                    $encReqCount++;
                }
                if (! empty($c['manually_classified'])) {
                    $manualCount++;
                }
                $as = (string) ($c['applied_status'] ?? '');
                if (isset($applied[$as])) {
                    $applied[$as]++;
                }
                $cat = trim((string) ($c['pdp_category'] ?? ''));
                if ($cat !== '') {
                    $pdpBuckets[$cat] = ($pdpBuckets[$cat] ?? 0) + 1;
                }
            }
        }

        // Per-table rows: each table lists its flagged columns as bullets.
        $tableRows = [];
        foreach ($tables as $t) {
            $tName = $this->str($t['name'] ?? $t['table_name'] ?? null);
            if ($tName === '') {
                continue;
            }
            $cols = $this->arr($t['columns'] ?? null);
            $flagged = array_values(array_filter($cols, fn ($c) => ! empty($c['pii_detected']) || trim((string) ($c['pdp_category'] ?? '')) !== '' || ! empty($c['applied_status'])));
            $meta = 'Baris: '.number_format((int) ($t['row_count'] ?? 0), 0, ',', '.')
            .' | Kolom: '.count($cols)
            .(isset($t['size_mb']) ? ' | Ukuran: '.$this->str($t['size_mb']).' MB' : '');
            $body = $this->bullets($flagged, function ($c) use ($appliedStatus) {
                $parts = $this->str($c['name'] ?? $c['column_name'] ?? null);
                if (! empty($c['type'])) {
                    $parts .= ' ('.$this->str($c['type']).')';
                }
                $tags = [];
                $tags[] = 'PII: '.($this->yn(! empty($c['pii_detected'])));
                if (trim((string) ($c['pdp_category'] ?? '')) !== '') {
                    $tags[] = 'Kategori PDP: '.$this->str($c['pdp_category']);
                }
                if (trim((string) ($c['classification'] ?? '')) !== '') {
                    $tags[] = 'Klasifikasi: '.strtoupper($this->str($c['classification']));
                }
                $tags[] = 'Status: '.$appliedStatus($c);
                if (! empty($c['encryption_required'])) {
                    $tags[] = 'Perlu Enkripsi';
                }

                return trim($parts.' — '.implode(' | ', $tags), ' —');
            });
            $tableRows[] = ['label' => $tName, 'value' => trim($meta."\n".($body !== '' ? $body : 'Tidak ada kolom terklasifikasi.'))];
        }
        if (empty($tableRows)) {
            $tableRows[] = ['label' => 'Tabel', 'value' => 'Belum ada hasil pemindaian.'];
        }

        // AI Deep Scan per-column recommendations.
        $aiRecRows = [];
        foreach ($aiTables as $t) {
            $tName = $this->str($t['name'] ?? null);
            $recs = array_values(array_filter($this->arr($t['columns'] ?? null), fn ($c) => trim((string) ($c['ai_recommendation'] ?? '')) !== ''));
            if (empty($recs)) {
                continue;
            }
            $aiRecRows[] = [
                'label' => $tName,
                'value' => $this->bullets($recs, fn ($c) => trim($this->str($c['name'] ?? null).': '.$this->str($c['ai_recommendation'] ?? null), ': ')),
            ];
        }

        // Protection assessment per column (keyed by "table.column").
        $protMeta = ['assessed_at', 'assessed_by', 'assessed_by_name', 'source'];
        $protRows = [];
        foreach ($protection as $key => $entry) {
            $entry = $this->arr($entry);
            $checks = [];
            foreach ($entry as $k => $v) {
                if (in_array($k, $protMeta, true)) {
                    continue;
                }
                $checks[] = ucfirst(str_replace('_', ' ', (string) $k)).': '.$this->str($v);
            }
            $footer = trim('Sumber: '.($this->str($entry['source'] ?? null) ?: '-')
            .' | Oleh: '.($this->str($entry['assessed_by_name'] ?? null) ?: '-')
            .' | '.$this->date($entry['assessed_at'] ?? null), ' |');
            $protRows[] = [
                'label' => $this->str($key),
                'value' => trim(implode("\n", $checks)."\n".$footer),
            ];
        }
        if (empty($protRows)) {
            $protRows[] = ['label' => 'Penilaian Perlindungan', 'value' => 'Belum ada penilaian perlindungan kolom.'];
        }

        // Related RoPA (many-to-many pivot). Only when eager-loaded to avoid N+1.
        $ropaRows = [];
        if ($x->relationLoaded('ropas')) {
            $ropaRows = $x->ropas->all();
        }

        return [
            [
                'title' => 'I. Identitas Sistem Informasi',
                'rows' => [
                    ['label' => 'Nama Sistem', 'value' => $this->str($x->name)],
                    ['label' => 'Kode Sistem', 'value' => $this->str($x->code)],
                    ['label' => 'Deskripsi', 'value' => $this->str($x->description)],
                    ['label' => 'Pemilik Sistem (Owner)', 'value' => $this->str($x->owner)],
                    ['label' => 'Tipe Sumber Data', 'value' => strtoupper($this->str($x->source_type)) ?: '-'],
                    ['label' => 'Tipe Koneksi', 'value' => $this->str($x->connection_type)],
                    ['label' => 'Menggunakan Sharding', 'value' => $this->yn($x->is_sharded)],
                    ['label' => 'Jumlah Shard', 'value' => (string) count($this->arr($x->shards))],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                ],
            ],
            [
                'title' => 'II. Konfigurasi Koneksi Sumber Data',
                'rows' => [
                    ['label' => 'Host', 'value' => $this->str($config['host'] ?? null)],
                    ['label' => 'Port', 'value' => $this->str($config['port'] ?? null)],
                    ['label' => 'Nama Database', 'value' => $this->str($config['database'] ?? null)],
                    ['label' => 'Username', 'value' => $this->str($config['username'] ?? null)],
                    ['label' => 'Uji Koneksi Terakhir', 'value' => $this->date($config['last_test'] ?? null)],
                    ['label' => 'Hasil Uji Koneksi', 'value' => trim($this->yn($this->arr($config['test_result'] ?? null)['success'] ?? null).' — '.$this->str($this->arr($config['test_result'] ?? null)['message'] ?? null), ' —')],
                ],
            ],
            [
                'title' => 'III. Status Pemindaian (Scan)',
                'rows' => [
                    ['label' => 'Status Pemindaian', 'value' => strtoupper($this->str($x->scanning_status)) ?: '-'],
                    ['label' => 'Progres Pemindaian', 'value' => $x->scanning_progress !== null ? round((float) $x->scanning_progress).'%' : '-'],
                    ['label' => 'Terakhir Dipindai', 'value' => $this->date($x->last_scanned_at)],
                    ['label' => 'Mesin Pemindai (Engine)', 'value' => $this->str($scan['engine_version'] ?? ($scan['engine'] ?? null))],
                    ['label' => 'Durasi Pemindaian', 'value' => isset($scan['scan_duration_ms']) ? $this->str($scan['scan_duration_ms']).' ms' : '-'],
                    ['label' => 'Total Baris Dipindai', 'value' => number_format((int) ($scan['total_rows_scanned'] ?? $totalRows), 0, ',', '.')],
                    ['label' => 'Galat Pemindaian', 'value' => $this->str($scan['error'] ?? null) ?: 'Tidak ada'],
                ],
            ],
            [
                'title' => 'IV. Ringkasan Temuan Data Pribadi',
                'rows' => [
                    ['label' => 'Total Alert PDP', 'value' => (string) ((int) ($x->pdp_alert_count ?? 0))],
                    ['label' => 'Total Alert PII', 'value' => (string) ((int) ($x->pii_alert_count ?? 0))],
                    ['label' => 'Jumlah Tabel Dipindai', 'value' => (string) count($tables)],
                    ['label' => 'Total Kolom Dipindai', 'value' => (string) $colCount],
                    ['label' => 'Kolom Terdeteksi PII', 'value' => (string) $piiCount],
                    ['label' => 'Kolom Perlu Enkripsi', 'value' => (string) $encReqCount],
                    ['label' => 'Kolom Diklasifikasi Manual', 'value' => (string) $manualCount],
                    ['label' => 'Diterapkan: Data Pribadi', 'value' => (string) $applied['applied_pribadi']],
                    ['label' => 'Diterapkan: Data Sensitif', 'value' => (string) $applied['applied_sensitive']],
                    ['label' => 'Ditandai: Bukan PII', 'value' => (string) $applied['not_pii']],
                    ['label' => 'Rekomendasi Ditolak', 'value' => (string) $applied['rejected']],
                    ['label' => 'Sebaran Kategori PDP', 'value' => $this->bullets(
                        array_map(fn ($k, $v) => ['k' => $k, 'v' => $v], array_keys($pdpBuckets), array_values($pdpBuckets)),
                        fn ($p) => $this->str($p['k']).': '.$this->str($p['v']).' kolom'
                    ) ?: '-'],
                ],
            ],
            [
                'title' => 'V. Rincian Tabel & Kolom Terklasifikasi',
                'rows' => $tableRows,
            ],
            [
                'title' => 'VI. Perubahan Skema Terdeteksi',
                'rows' => [
                    ['label' => 'Peringatan Perubahan Skema', 'value' => $this->bullets($diffAlerts, fn ($a) => $this->str($a)) ?: 'Tidak ada perubahan skema terdeteksi.'],
                ],
            ],
            [
                'title' => 'VII. Jalur Akses (Access Paths)',
                'rows' => [
                    ['label' => 'Akun / Peran Basis Data', 'value' => $this->bullets($this->arr($access['roles'] ?? null), fn ($r) => trim($this->str($r['name'] ?? null).($this->yn($r['can_login'] ?? null) === 'Ya' ? ' — dapat login' : '').(! empty($r['is_superuser']) ? ' — superuser' : ''), ' —')) ?: '-'],
                    ['label' => 'Hak Akses Tabel (Grants)', 'value' => $this->bullets($this->arr($access['grants'] ?? null), fn ($g) => trim($this->str($g['grantee'] ?? null).' → '.$this->str($g['table'] ?? null).' : '.$this->str($g['privilege'] ?? null).(! empty($g['is_grantable']) ? ' (grantable)' : ''), ' →: ')) ?: '-'],
                    ['label' => 'Galat Pemindaian Akses', 'value' => $this->str($access['error'] ?? null) ?: 'Tidak ada'],
                ],
            ],
            [
                'title' => 'VIII. Sinyal Enkripsi',
                'rows' => [
                    ['label' => 'Mesin Basis Data', 'value' => $this->str($enc['engine'] ?? null)],
                    ['label' => 'Koneksi TLS/SSL Aktif', 'value' => $this->yn($enc['ssl_in_use'] ?? null)],
                    ['label' => 'Enkripsi Data-at-Rest', 'value' => $this->yn($enc['data_at_rest_encrypted'] ?? null) ?: 'Tidak diketahui di lapisan basis data'],
                    ['label' => 'Tabel dengan Enkripsi Kolom Terdeteksi', 'value' => $this->bullets($this->arr($this->arr($enc['column_encryption_observed'] ?? null)['tables'] ?? null), fn ($t) => $this->str($t)) ?: '-'],
                    ['label' => 'Galat Pemindaian Enkripsi', 'value' => $this->str($enc['error'] ?? null) ?: 'Tidak ada'],
                ],
            ],
            [
                'title' => 'IX. Analisis AI Deep Scan',
                'rows' => array_merge(
                    [['label' => 'Rekomendasi Global AI', 'value' => $this->str($ai['global_recommendation'] ?? null) ?: 'Belum ada analisis AI Deep Scan.']],
                    empty($aiRecRows) ? [] : $aiRecRows,
                ),
            ],
            [
                'title' => 'X. Penilaian Perlindungan Kolom',
                'rows' => $protRows,
            ],
            [
                'title' => 'XI. Keterkaitan RoPA',
                'rows' => [
                    ['label' => 'Aktivitas Pemrosesan Terkait', 'value' => $this->bullets($ropaRows, fn ($r) => trim($this->str($r->registration_number ?? null).' — '.$this->str($r->processing_activity ?? null).' ('.strtoupper($this->str($r->risk_level ?? '-')).')', ' —')) ?: '-'],
                ],
            ],
            [
                'title' => 'XII. Status Dokumen',
                'rows' => [
                    ['label' => 'Status Pemindaian', 'value' => strtoupper($this->str($x->scanning_status)) ?: '-'],
                    ['label' => 'Terakhir Dipindai', 'value' => $this->date($x->last_scanned_at)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function vendorRisk(VendorAssessment $x): array
    {
        // ---- effective question set (mirror ThirdPartyAssessmentScorer::compute +
        //      TprmReviewController::doShow dual path). library_id terisi → semua
        //      pertanyaan aktif library tsb; legacy (NULL) → effectiveForOrg + v2_2026.
        if (! empty($x->library_id)) {
            $questions = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('library_id', $x->library_id)
                ->where('is_active', true)
                ->orderBy('section')
                ->orderBy('sort_order')
                ->get();
        } else {
            $questions = VendorQuestionnaire::effectiveForOrg($x->org_id)
                ->filter(fn ($q) => $q->is_active && $q->version === 'v2_2026')
                ->sortBy(['section', 'sort_order'])
                ->values();
        }

        $answers = $this->arr($x->answers);
        $aiAnalyses = $this->arr($x->ai_analyses);
        $breakdown = $this->arr($x->score_breakdown);
        $recommendations = $this->arr($x->recommendations);
        $vendor = $x->vendor;

        // ---- label maps
        $valLabel = ['ya' => 'Ya', 'tidak' => 'Tidak', 'tidak_tahu' => 'Tidak Tahu / Tidak Yakin'];
        $aiLabel = [
            'comply' => 'Memenuhi', 'partial' => 'Memenuhi Sebagian',
            'non_comply' => 'Belum Memenuhi', 'unsure' => 'AI Tidak Yakin',
        ];
        $riskLabel = [
            'low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi', 'critical' => 'Kritis',
            'rendah' => 'Rendah', 'sedang' => 'Sedang', 'tinggi' => 'Tinggi', 'kritis' => 'Kritis',
        ];
        $statusLabel = [
            VendorAssessment::STATUS_DRAFT => 'Draf',
            VendorAssessment::STATUS_SENT => 'Terkirim ke Pihak Ketiga',
            VendorAssessment::STATUS_SUBMITTED => 'Telah Disubmit (Menunggu Peninjauan)',
            VendorAssessment::STATUS_REVIEW_IN_PROGRESS => 'Sedang Ditinjau',
            VendorAssessment::STATUS_PENDING_APPROVAL => 'Menunggu Persetujuan',
            VendorAssessment::STATUS_APPROVED => 'Disetujui',
            VendorAssessment::STATUS_REJECTED => 'Ditolak',
        ];
        $sourceLabel = [
            VendorAssessment::SOURCE_DETERMINISTIC => 'Kuesioner Deterministik',
            VendorAssessment::SOURCE_AI => 'Berbantuan AI',
            VendorAssessment::SOURCE_IMPORTED => 'Impor',
        ];
        $sectionLabels = VendorQuestionnaire::SECTION_LABELS;
        $catLabels = VendorQuestionnaire::CATEGORY_LABELS;

        $st = (string) $x->status;
        $stText = $statusLabel[$st] ?? (strtoupper($this->str($st)) ?: '-');
        $rl = strtolower($this->str($x->risk_level));
        $rlText = $rl !== '' ? (($riskLabel[$rl] ?? ucfirst($rl)).' ('.strtoupper($rl).')') : '-';

        // ---- helper: normalise satu entri jawaban → [value, note, evidenceCount]
        $readAnswer = function ($entry) {
            if (is_array($entry)) {
                $ev = isset($entry['evidence']) && is_array($entry['evidence']) ? count($entry['evidence']) : 0;

                return [$entry['value'] ?? null, $this->str($entry['note'] ?? null), $ev];
            }
            if (is_string($entry)) {
                return [$entry, '', 0];
            }

            return [null, '', 0];
        };

        // ---- per-question detail rows (grouped mengikuti urutan section pertanyaan)
        $questionRows = [];
        foreach ($questions as $q) {
            [$value, $note, $evCount] = $readAnswer($answers[$q->id] ?? null);
            $sectionText = $sectionLabels[$q->section] ?? ($this->str($q->section) ?: '-');
            $val = 'Bagian: '.$sectionText
            .' | Jawaban: '.($value !== null && $value !== '' ? ($valLabel[$value] ?? $this->str($value)) : 'Belum Dijawab');
            if ($note !== '') {
                $val .= "\nCatatan: ".$note;
            }
            $aiVerdict = VendorAssessment::aggregateAiVerdict($aiAnalyses[$q->id] ?? null);
            if ($aiVerdict) {
                $val .= "\nVerdict AI (advisory): ".($aiLabel[$aiVerdict] ?? $aiVerdict);
            }
            if ($evCount > 0) {
                $val .= "\nBukti Terlampir: ".$evCount.' dokumen';
            } elseif ($q->requires_evidence_upload) {
                $val .= "\nBukti Terlampir: wajib namun belum diunggah";
            }
            $questionRows[] = [
                'label' => trim($this->str($q->question_code).' — '.$this->str($q->question_text), ' —'),
                'value' => $val,
            ];
        }
        if (empty($questionRows)) {
            $questionRows[] = ['label' => 'Pertanyaan', 'value' => '-'];
        }

        // ---- AI document verification: satu baris per pertanyaan yang punya analisis
        $qTextById = [];
        foreach ($questions as $q) {
            $qTextById[$q->id] = $q->question_text ?: $q->question_code;
        }
        $aiRows = [];
        foreach ($aiAnalyses as $qId => $entries) {
            $list = $this->arr($entries);
            if (isset($list['status'])) {
                $list = [$list];
            }
            $list = array_values(array_filter($list, fn ($e) => is_array($e)));
            if (empty($list)) {
                continue;
            }
            $worst = VendorAssessment::aggregateAiVerdict($entries);
            $lines = ['Verdict AI (worst-case): '.($worst ? ($aiLabel[$worst] ?? $worst) : 'AI Tidak Yakin')];
            foreach ($list as $ai) {
                $fname = isset($ai['attachment_path']) ? basename((string) $ai['attachment_path']) : '';
                $fname = $fname !== '' ? $fname : 'Dokumen';
                $lines[] = '— '.$fname.': '.($aiLabel[$ai['status'] ?? ''] ?? ($this->str($ai['status'] ?? null) ?: '-'));
                if (! empty($ai['analysis'])) {
                    $lines[] = '  '.$this->str($ai['analysis']);
                }
                $cited = $this->arr($ai['cited_passages'] ?? null);
                if (! empty($cited)) {
                    $lines[] = '  Kutipan: '.implode(' | ', array_slice(array_map(fn ($c) => $this->str($c), $cited), 0, 2));
                }
            }
            $aiRows[] = ['label' => $this->str($qTextById[$qId] ?? $qId), 'value' => implode("\n", $lines)];
        }

        return [
            [
                'title' => 'I. Identitas Asesmen Pihak Ketiga',
                'rows' => [
                    ['label' => 'ID Asesmen', 'value' => $this->str($x->id)],
                    ['label' => 'Nama Pihak Ketiga', 'value' => $this->str($vendor?->name)],
                    ['label' => 'Kategori Kuesioner', 'value' => $this->str($catLabels[$x->category] ?? $x->category)],
                    ['label' => 'Versi Kuesioner', 'value' => $this->str($x->questionnaire_version)],
                    ['label' => 'Sumber Penilaian', 'value' => $this->str($sourceLabel[$x->source] ?? $x->source)],
                    ['label' => 'Dinilai oleh', 'value' => $this->str($x->assessor?->name)],
                    ['label' => 'Status Alur Kerja', 'value' => $stText],
                    ['label' => 'Tanggal Dikirim (Submit)', 'value' => $this->date($x->submitted_at)],
                ],
            ],
            [
                'title' => 'II. Profil & Identitas Pihak Ketiga',
                'rows' => [
                    ['label' => 'Nama', 'value' => $this->str($vendor?->name)],
                    ['label' => 'Jenis / Tipe', 'value' => $this->str($vendor?->type)],
                    ['label' => 'Jenis Entitas', 'value' => $this->str($vendor?->jenis_entitas)],
                    ['label' => 'Bidang', 'value' => $this->str($this->arr($vendor?->bidang))],
                    ['label' => 'Negara', 'value' => $this->str($vendor?->country)],
                    ['label' => 'Alamat', 'value' => $this->str($vendor?->alamat)],
                    ['label' => 'NPWP', 'value' => $this->str($vendor?->npwp)],
                    ['label' => 'Kontak PIC', 'value' => $vendor ? trim($this->str($vendor->contact_name).' — '.$this->str($vendor->pic_jabatan).' '.$this->str($vendor->contact_email).' '.$this->str($vendor->telepon), ' —') : ''],
                    ['label' => 'Departemen Kontak', 'value' => $this->str($vendor?->departemen_kontak)],
                    ['label' => 'Situs Web', 'value' => $this->str($vendor?->website)],
                    ['label' => 'URL Kebijakan Privasi', 'value' => $this->str($vendor?->privacy_policy_url)],
                    ['label' => 'Deskripsi', 'value' => $this->str($vendor?->description)],
                    ['label' => 'Layanan yang Diberikan', 'value' => $this->str($this->arr($vendor?->services_provided))],
                    ['label' => 'Data yang Dibagikan', 'value' => $this->str($this->arr($vendor?->data_shared))],
                    ['label' => 'Status Cakupan PDP', 'value' => $this->str($vendor?->pdp_scope_status)],
                ],
            ],
            [
                'title' => 'III. Perjanjian Pemrosesan Data (DPA)',
                'rows' => [
                    ['label' => 'Status DPA', 'value' => $this->str($vendor?->dpa_status)],
                    ['label' => 'DPA Ditandatangani', 'value' => $this->date($vendor?->dpa_signed_at)],
                    ['label' => 'DPA Kedaluwarsa', 'value' => $this->date($vendor?->dpa_expires_at)],
                    ['label' => 'Jadwal Asesmen Ulang', 'value' => $this->date($vendor?->next_assessment_due_at)],
                ],
            ],
            [
                'title' => 'IV. Hasil Penilaian Risiko',
                'rows' => [
                    ['label' => 'Skor', 'value' => $x->score !== null ? $this->str($x->score).' / 100' : '-'],
                    ['label' => 'Tingkat Risiko', 'value' => $rlText],
                    ['label' => 'Total Pertanyaan Aktif', 'value' => $this->str($breakdown['total_aktif'] ?? $questions->count())],
                    ['label' => 'Dijawab "Ya"', 'value' => $this->str($breakdown['jawab_ya'] ?? null)],
                    ['label' => 'Dijawab "Tidak"', 'value' => $this->str($breakdown['jawab_tidak'] ?? null)],
                    ['label' => 'Belum Dijawab / Tidak Tahu', 'value' => $this->str($breakdown['jawab_kosong'] ?? null)],
                ],
            ],
            [
                'title' => 'V. Detail Jawaban per Pertanyaan',
                'rows' => $questionRows,
            ],
            [
                'title' => 'VI. Rekomendasi Perbaikan',
                'rows' => empty($recommendations)
                ? [['label' => 'Rekomendasi', 'value' => '-']]
                : array_map(fn ($rec) => [
                    'label' => trim($this->str($rec['question_code'] ?? null).' — '.$this->str($sectionLabels[$rec['section'] ?? ''] ?? ($rec['section'] ?? '')), ' —'),
                    'value' => 'Pertanyaan: '.($this->str($rec['pertanyaan'] ?? null) ?: '-')."\nRekomendasi: ".($this->str($rec['rekomendasi'] ?? null) ?: '-'),
                ], array_values($recommendations)),
            ],
            [
                'title' => 'VII. Verifikasi AI Dokumen Pendukung (Advisory)',
                'rows' => empty($aiRows) ? [['label' => 'Analisis AI', 'value' => '-']] : $aiRows,
            ],
            [
                'title' => 'VIII. Alur Kerja Peninjauan & Persetujuan',
                'rows' => [
                    ['label' => 'Catatan Peninjau (Reviewer)', 'value' => $this->str($x->reviewer_note)],
                    ['label' => 'Ditinjau Pada', 'value' => $this->date($x->reviewer_actioned_at)],
                    ['label' => 'Catatan Penyetuju (Approver)', 'value' => $this->str($x->approver_note)],
                    ['label' => 'Disetujui / Ditindak Pada', 'value' => $this->date($x->approver_actioned_at)],
                    ['label' => 'Alasan Penolakan', 'value' => $this->str($x->rejection_reason)],
                    ['label' => 'Catatan Internal', 'value' => $this->str($x->notes)],
                    ['label' => 'Alur Kerja Terkunci', 'value' => $this->yn($x->workflow_locked)],
                ],
            ],
            [
                'title' => 'IX. Status Dokumen & Jejak Pengiriman',
                'rows' => [
                    ['label' => 'Status', 'value' => $stText],
                    ['label' => 'Tanggal Dikirim', 'value' => $this->date($x->submitted_at)],
                    ['label' => 'Alamat IP Pengirim', 'value' => $this->str($x->submitted_ip)],
                    ['label' => 'User Agent Pengirim', 'value' => $this->str($x->submitted_user_agent)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function crossBorder(CrossBorderTransfer $x): array
    {
        // tia_summary is persisted as a json_encode()'d string (NOT in $casts),
        // so decode it manually; tia_answers IS array-cast.
        $tia = is_string($x->tia_summary)
        ? $this->arr(json_decode($x->tia_summary, true))
        : $this->arr($x->tia_summary);
        $answers = $this->arr($x->tia_answers);

        // Country adequacy reference (platform-level lookup, may be null when the
        // destination string isn't in the adequacy table → treat as Tier "none").
        $adq = $x->adequacy();

        // Enum → Bahasa Indonesia label maps (model constants + module-local sets).
        $volumeLabels = CrossBorderTransfer::VOLUME_BAND_LABELS;
        $freqLabels = CrossBorderTransfer::FREQUENCY_LABELS;
        $sensLabels = CrossBorderTransfer::SENSITIVITY_LABELS;
        $mechLabels = CrossBorderTransfer::MECHANISM_LABELS;

        $legalLabels = [
            'none' => 'Belum dipilih / Tidak ada dasar formal',
            'adequacy' => 'Tingkat perlindungan setara (adequacy)',
            'sccs' => 'Standard Contractual Clauses (SCCs)',
            'bcr' => 'Binding Corporate Rules (BCR)',
            'consent' => 'Persetujuan eksplisit subjek data',
            'contract_necessity' => 'Pelaksanaan kontrak dengan subjek',
            'public_interest' => 'Kepentingan publik',
            'vital_interest' => 'Kepentingan vital (vital interest)',
        ];
        $statusLabels = [
            'draft' => 'Draf', 'pending' => 'Menunggu Persetujuan',
            'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'expired' => 'Kedaluwarsa',
        ];
        $riskLabels = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi', 'critical' => 'Kritis'];
        $sourceLabels = ['ai' => 'AI-assisted', 'manual' => 'Manual (rubric Pasal 56)'];

        $legalBasis = (string) ($x->legal_basis ?? '');
        $legalText = $legalBasis !== '' ? ($legalLabels[$legalBasis] ?? $legalBasis) : '';

        $status = strtolower($this->str($x->status));
        $statusText = $status !== '' ? ($statusLabels[$status] ?? ucfirst($status)) : '-';

        $riskLevel = strtolower($this->str($x->risk_level));
        $riskText = $riskLevel !== ''
        ? (($riskLabels[$riskLevel] ?? ucfirst($riskLevel)).' ('.strtoupper($riskLevel).')')
        : '-';

        // RoPA linkage (belongsTo via linked_ropa_id).
        $ropa = $x->ropa;
        $ropaText = $ropa
        ? trim(($ropa->registration_number ?: $ropa->custom_number).' — '.$ropa->processing_activity, ' —')
        : '';

        // Adequacy tier + narrative.
        if ($adq) {
            $tierText = CountryAdequacy::TIER_LABELS[$adq->tier] ?? $this->str($adq->tier);
        } else {
            $tierText = CountryAdequacy::TIER_LABELS[CountryAdequacy::TIER_NONE]
            .' — negara tidak terdaftar di lookup, wajib safeguard tambahan (SCCs/BCR/persetujuan).';
        }

        // TIA questionnaire answers: assoc map question_key => bool|string.
        $answerRows = [];
        foreach ($answers as $k => $v) {
            $answerRows[] = ['k' => (string) $k, 'v' => $v];
        }

        return [
            [
                'title' => 'I. Identitas Transfer Lintas Negara',
                'rows' => [
                    ['label' => 'Negara Tujuan', 'value' => $this->str($x->destination_country)],
                    ['label' => 'Entitas Penerima', 'value' => $this->str($x->destination_entity)],
                    ['label' => 'Tujuan Transfer', 'value' => $this->str($x->transfer_purpose)],
                    ['label' => 'Aktivitas Pemrosesan Terkait (RoPA)', 'value' => $ropaText],
                    ['label' => 'Kategori Data yang Ditransfer', 'value' => $this->str($this->arr($x->data_categories))],
                    ['label' => 'Catatan', 'value' => $this->str($x->notes)],
                ],
            ],
            [
                'title' => 'II. Profil Transfer',
                'rows' => [
                    ['label' => 'Volume Transfer', 'value' => $x->transfer_volume_band ? ($volumeLabels[$x->transfer_volume_band] ?? $this->str($x->transfer_volume_band)) : '-'],
                    ['label' => 'Frekuensi Transfer', 'value' => $x->transfer_frequency ? ($freqLabels[$x->transfer_frequency] ?? $this->str($x->transfer_frequency)) : '-'],
                    ['label' => 'Tingkat Sensitivitas Data', 'value' => $x->data_sensitivity ? ($sensLabels[$x->data_sensitivity] ?? $this->str($x->data_sensitivity)) : '-'],
                    ['label' => 'Mekanisme Transfer', 'value' => $x->transfer_mechanism ? ($mechLabels[$x->transfer_mechanism] ?? $this->str($x->transfer_mechanism)) : '-'],
                    ['label' => 'Periode Retensi di Penerima', 'value' => $x->retention_period_days !== null ? number_format((int) $x->retention_period_days, 0, ',', '.').' hari' : '-'],
                ],
            ],
            [
                'title' => 'III. Kontrol Keamanan & Minimisasi Data',
                'rows' => [
                    ['label' => 'Enkripsi In-Transit (TLS 1.2+)', 'value' => $this->yn($x->encryption_in_transit)],
                    ['label' => 'Enkripsi At-Rest (AES-256)', 'value' => $this->yn($x->encryption_at_rest)],
                    ['label' => 'Data Minimization Diterapkan', 'value' => $this->yn($x->data_minimization_applied)],
                ],
            ],
            [
                'title' => 'IV. Penanggung Jawab di Penerima (Pasal 56 ayat 2)',
                'rows' => [
                    ['label' => 'Nama DPO / PIC Penerima', 'value' => $this->str($x->recipient_dpo_name)],
                    ['label' => 'Email DPO / PIC Penerima', 'value' => $this->str($x->recipient_dpo_email)],
                ],
            ],
            [
                'title' => 'V. Dasar Hukum & Status Adekuasi Negara Tujuan',
                'rows' => [
                    ['label' => 'Dasar Hukum Transfer (Pasal 56 UU PDP)', 'value' => $legalText ?: '-'],
                    ['label' => 'Status Adekuasi Negara', 'value' => $tierText],
                    ['label' => 'Dasar Klasifikasi Adekuasi', 'value' => $adq ? $this->str($adq->basis) : ''],
                    ['label' => 'Wilayah / Region', 'value' => $adq ? $this->str($adq->region) : ''],
                    ['label' => 'Negara Memiliki UU Pelindungan Data', 'value' => $adq ? $this->yn($adq->has_pdp_law) : ''],
                    ['label' => 'Negara Memiliki Otoritas Pengawas', 'value' => $adq ? $this->yn($adq->has_pdp_authority) : ''],
                    ['label' => 'Safeguard Tambahan Diwajibkan', 'value' => $adq ? $this->yn($adq->recommended_safeguards_required) : 'Ya'],
                    ['label' => 'Catatan Adekuasi', 'value' => $adq ? $this->str($adq->notes) : ''],
                ],
            ],
            [
                'title' => 'VI. Safeguard / Langkah Perlindungan',
                'rows' => [
                    ['label' => 'Safeguard Terpasang', 'value' => $this->bullets($this->arr($x->safeguards), fn ($s) => $this->str($s)) ?: '-'],
                ],
            ],
            [
                'title' => 'VII. Transfer Impact Assessment (TIA)',
                'rows' => [
                    ['label' => 'Skor Risiko', 'value' => $x->risk_score !== null ? $this->str($x->risk_score).' / 100' : 'Belum dinilai'],
                    ['label' => 'Tingkat Risiko', 'value' => $riskText],
                    ['label' => 'Sumber Penilaian', 'value' => isset($tia['source']) ? ($sourceLabels[$tia['source']] ?? $this->str($tia['source'])) : '-'],
                    ['label' => 'Dasar Hukum Rekomendasi TIA', 'value' => isset($tia['legal_basis']) ? ($legalLabels[$tia['legal_basis']] ?? $this->str($tia['legal_basis'])) : ''],
                    ['label' => 'Rekomendasi Safeguard (TIA)', 'value' => $this->bullets($this->arr($tia['safeguards'] ?? null), fn ($s) => $this->str($s))],
                    ['label' => 'Waktu Penilaian TIA', 'value' => $this->date($tia['assessed_at'] ?? null)],
                    ['label' => 'Catatan Kegagalan AI', 'value' => $this->str($tia['ai_error'] ?? null)],
                    ['label' => 'Jawaban Kuesioner TIA', 'value' => $this->bullets($answerRows, fn ($r) => ucfirst(str_replace('_', ' ', $r['k'])).': '.(is_bool($r['v']) ? $this->yn($r['v']) : $this->str($r['v'])))],
                ],
            ],
            [
                'title' => 'VIII. Status & Riwayat Waktu',
                'rows' => [
                    ['label' => 'Status', 'value' => $statusText],
                    ['label' => 'Disetujui Pada', 'value' => $this->date($x->approved_at)],
                    ['label' => 'Jadwal Tinjauan Ulang', 'value' => $this->date($x->review_due_at)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
        ];
    }

    public function consent(ConsentCollectionPoint $x): array
    {
        $settings = $this->arr($x->settings);
        $allowedDomains = $this->arr($x->allowed_domains);

        // Jenis collection point (single-kind: cookie_banner OR app_consent).
        $kindLabels = [
            ConsentCollectionPoint::KIND_COOKIE => 'Cookie Banner (Pengunjung Anonim)',
            ConsentCollectionPoint::KIND_APP => 'Consent Aplikasi (Subjek Teridentifikasi)',
        ];
        $kindLabel = $kindLabels[$x->kind] ?? ($this->str($x->kind) ?: '-');

        $displayModeLabels = [
            'banner_bottom' => 'Banner Bawah', 'banner_top' => 'Banner Atas',
            'modal_center' => 'Modal Tengah', 'fullscreen' => 'Layar Penuh',
            'inline' => 'Inline (Anchor)',
        ];
        $frequencyLabels = [
            'once' => 'Sekali (Once)', 'session' => 'Per Sesi', 'every_load' => 'Setiap Muat Halaman',
        ];
        $audienceLabels = [
            'anonymous_only' => 'Hanya Pengunjung Anonim', 'logged_in_only' => 'Hanya Pengguna Login',
            'both' => 'Keduanya',
        ];
        $categoryLabels = [
            'essential' => 'Esensial', 'analytics' => 'Analitik', 'marketing' => 'Pemasaran',
            'personalization' => 'Personalisasi', 'functional' => 'Fungsional',
            'third_party' => 'Pihak Ketiga', 'other' => 'Lainnya',
        ];

        // Child data — consent items (purposes / kategori), linked RoPA, consent logs.
        $items = $x->relationLoaded('items') ? $x->items : $x->items()->get();
        $ropas = $x->relationLoaded('ropas') ? $x->ropas : $x->ropas()->get();
        $logsTotal = $x->relationLoaded('logs') ? $x->logs->count() : $x->logs()->count();
        $logs = $x->relationLoaded('logs') ? $x->logs->take(20) : $x->logs()->latest()->limit(20)->get();

        // Count granted choices in one consent log (handles list vs. {id:bool} map).
        $grantedCount = function ($log) {
            $ci = $this->arr($log->consented_items);
            if ($ci === []) {
                return 0;
            }

            return array_is_list($ci) ? count($ci) : count(array_filter($ci, fn ($v) => (bool) $v));
        };

        return [
            [
                'title' => 'I. Identitas Titik Pengumpulan Persetujuan',
                'rows' => [
                    ['label' => 'Collection ID', 'value' => $this->str($x->collection_id)],
                    ['label' => 'Nama', 'value' => $this->str($x->name)],
                    ['label' => 'Jenis', 'value' => $kindLabel],
                    ['label' => 'Domain', 'value' => $this->str($x->domain)],
                    ['label' => 'URL Pengalihan (Redirect)', 'value' => $this->str($x->redirect_url)],
                    ['label' => 'Bahasa (Locale)', 'value' => $this->str($x->locale)],
                    ['label' => 'Webhook URL', 'value' => $this->str($x->webhook_url)],
                    ['label' => 'Dibuat', 'value' => $this->date($x->created_at)],
                    ['label' => 'Terakhir Diperbarui', 'value' => $this->date($x->updated_at)],
                ],
            ],
            [
                'title' => 'II. Konfigurasi Tampilan & Audiens',
                'rows' => [
                    ['label' => 'Mode Tampilan', 'value' => $displayModeLabels[$x->display_mode] ?? ($this->str($x->display_mode) ?: '-')],
                    ['label' => 'Frekuensi Tampilan', 'value' => $frequencyLabels[$x->display_frequency] ?? ($this->str($x->display_frequency) ?: '-')],
                    ['label' => 'Target Audiens', 'value' => $audienceLabels[$x->audience] ?? ($this->str($x->audience) ?: '-')],
                    ['label' => 'Domain yang Diizinkan', 'value' => $this->str($allowedDomains) ?: '-'],
                    ['label' => 'Warna Utama', 'value' => $this->str($settings['primary_color'] ?? null)],
                    ['label' => 'Warna Aksen', 'value' => $this->str($settings['accent_color'] ?? null)],
                    ['label' => 'Teks Banner', 'value' => $this->str($settings['banner_text'] ?? null)],
                    ['label' => 'Teks Intro Modal', 'value' => $this->str($settings['modal_intro_text'] ?? null)],
                    ['label' => 'Tampilkan "Powered By"', 'value' => $this->yn($settings['show_powered_by'] ?? null)],
                    ['label' => 'Logo', 'value' => $this->str($settings['logo_url'] ?? ($settings['powered_by_logo'] ?? null))],
                ],
            ],
            [
                'title' => 'III. Pengaturan Wali / Guardian (Anak & Disabilitas)',
                'rows' => [
                    ['label' => 'Mode Wali Aktif', 'value' => $this->yn($settings['guardian_mode'] ?? null)],
                    ['label' => 'Label Wali', 'value' => $this->str($settings['guardian_label'] ?? null)],
                    ['label' => 'Opsi Hubungan Wali', 'value' => $this->str($settings['guardian_relation_options'] ?? null)],
                ],
            ],
            [
                'title' => 'IV. Integrasi & Autentikasi',
                'rows' => [
                    ['label' => 'Metode Widget Aktif', 'value' => $this->yn($x->isWidgetEnabled())],
                    ['label' => 'Metode API Key Aktif', 'value' => $this->yn($x->isApiKeyEnabled())],
                    ['label' => 'Client Key (Publik)', 'value' => $this->str($x->client_key)],
                    ['label' => 'Embed Token', 'value' => $this->str($x->embed_token)],
                    ['label' => 'Penyedia CAPTCHA', 'value' => $this->str($x->captcha_provider)],
                    ['label' => 'CAPTCHA Site Key', 'value' => $this->str($x->captcha_site_key)],
                    ['label' => 'API Key Terakhir Dirotasi', 'value' => $this->date($x->api_keys_last_rotated_at)],
                ],
            ],
            [
                'title' => 'V. Daftar Item Persetujuan / Kategori',
                'rows' => [
                    ['label' => 'Jumlah Item', 'value' => (string) $items->count()],
                    ['label' => 'Item Persetujuan', 'value' => $this->bullets($items->all(), function ($it) use ($categoryLabels) {
                        $cat = $categoryLabels[$it->category] ?? $this->str($it->category);
                        $meta = [];
                        if ($cat !== '') {
                            $meta[] = 'Kategori: '.$cat;
                        }
                        $meta[] = 'Wajib: '.$this->yn($it->is_required);
                        $meta[] = 'Aktif: '.$this->yn($it->is_active);
                        if ($this->str($it->version) !== '') {
                            $meta[] = 'v'.$this->str($it->version);
                        }
                        $line = trim($this->str($it->title).' — '.implode(' | ', $meta), ' —');
                        $purpose = $this->str($it->specific_purpose ?: $it->description);
                        if ($purpose !== '') {
                            $line .= "\n  Tujuan: ".$purpose;
                        }
                        $cookies = $this->str($this->arr($it->cookie_keys));
                        if ($cookies !== '') {
                            $line .= "\n  Cookie Keys: ".$cookies;
                        }

                        return $line;
                    }) ?: '-'],
                ],
            ],
            [
                'title' => 'VI. RoPA Terkait',
                'rows' => [
                    ['label' => 'Aktivitas Pemrosesan Terkait', 'value' => $this->bullets(
                        $ropas->all(),
                        fn ($r) => trim($this->str($r->registration_number ?: $r->custom_number).' — '.$this->str($r->processing_activity).' ('.(strtoupper($this->str($r->risk_level)) ?: '-').')', ' —')
                    ) ?: '-'],
                ],
            ],
            [
                'title' => 'VII. Rekaman Persetujuan (Consent Logs)',
                'rows' => [
                    ['label' => 'Total Rekaman Persetujuan', 'value' => number_format((int) $logsTotal, 0, ',', '.').' rekaman'],
                    ['label' => 'Rekaman Terbaru (maks. 20)', 'value' => $this->bullets($logs->all(), function ($log) use ($grantedCount) {
                        $subj = $this->str($log->email ?: $log->user_identifier ?: $log->name);
                        $parts = [$subj !== '' ? $subj : '(anonim)'];
                        $parts[] = 'Setuju: '.$grantedCount($log).' item';
                        if ($this->str($log->policy_version) !== '') {
                            $parts[] = 'Kebijakan v'.$this->str($log->policy_version);
                        }
                        if ($this->str($log->source_form) !== '') {
                            $parts[] = 'Form: '.$this->str($log->source_form);
                        }
                        if ($this->str($log->ip_country) !== '') {
                            $parts[] = $this->str($log->ip_country);
                        }
                        $parts[] = $this->date($log->created_at);

                        return implode(' | ', array_filter($parts));
                    }) ?: 'Belum ada rekaman'],
                ],
            ],
        ];
    }
}
