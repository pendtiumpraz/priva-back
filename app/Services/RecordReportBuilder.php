<?php

namespace App\Services;

use App\Models\BreachIncident;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\GapAssessment;
use App\Models\LiaAssessment;
use App\Models\MaturityAssessment;
use App\Models\MaturityQuestion;
use App\Models\Ropa;
use App\Models\TiaAssessment;

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

        return [
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

        return [
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
}
