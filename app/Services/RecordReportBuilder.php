<?php

namespace App\Services;

use App\Models\Dpia;
use App\Models\Ropa;

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
}
