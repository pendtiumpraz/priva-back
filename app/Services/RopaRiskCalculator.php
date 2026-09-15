<?php

namespace App\Services;

/**
 * Computes RoPA risk_level from the 7-step wizard data.
 *
 * Rules come from 7_step_ropa_existing.md (Nexus canonical). Pure function:
 * no DB access, no side effects. Returns {level, triggers[], reasons[]} so
 * the caller can surface "why HIGH" to the user and log triggers to
 * wizard_data.risk_triggers for audit.
 *
 * HIGH wins over MEDIUM wins over LOW — any single HIGH trigger is enough.
 */
class RopaRiskCalculator
{
    /** Any of these values in `informasi_pemrosesan.bantuan_ai` → HIGH. */
    private const AI_FULL_DECISION_VALUES = [
        'Ya (Keputusan Sepenuhnya menggunakan AI)',
        'Ya - Keputusan Penuh AI',
        'ai_full',
    ];

    /** MEDIUM-tier AI assistance. */
    private const AI_PARTIAL_VALUES = [
        'Ya (Keputusan Akhir dari Manusia)',
        'Sebagian dari Pemrosesan',
        'ai_human_final',
        'ai_partial',
    ];

    /** HIGH: fully automated decision (Pasal 10 jo. PP 33/2026 Pasal 93–95 UU PDP). */
    private const AUTO_FULL_VALUES = [
        'Ya, Keputusan Penuh',
        'Ya - Keputusan Penuh',
        'auto_full',
    ];

    /** MEDIUM: partial/human-reviewed automated decision. */
    private const AUTO_PARTIAL_VALUES = [
        'Ya, Keputusan Akhir dari Manusia',
        'Sebagian dari Pemrosesan',
        'auto_human_final',
        'auto_partial',
    ];

    /** jumlah_subjek values that indicate large-scale processing. */
    private const MASS_SUBJECTS_VALUES = [
        '> 1.000 subjek',
        'Lebih dari 1000',
        '>1000',
        'mass',
    ];

    /**
     * Kelompok rentan pada `kategori_subjek` — memicu risiko TINGGI karena
     * SIAPA subjeknya, bukan karena jenis datanya.
     *
     * Dua sumbu ini sering tertukar, dan akibatnya nyata: sebelum ini kalkulator
     * HANYA membaca `jenis_data_spesifik`. Artinya RoPA yang mencantumkan
     * subjeknya "Anak" tetapi tidak mencentang "Data Anak" tidak memicu DPIA
     * sama sekali — padahal itu persis pemrosesan yang dituju PP 33/2026
     * Pasal 38. Untuk Penyandang Disabilitas lebih parah lagi: opsi itu ada di
     * `kategori_subjek` tetapi TIDAK PUNYA padanan apa pun di
     * `jenis_data_spesifik`, sehingga Pasal 39 tidak pernah memicu penilaian
     * dampak lewat jalur mana pun.
     *
     * Dicocokkan sebagai substring huruf kecil, supaya 'Anak' dan
     * 'Penyandang Disabilitas' sama-sama tertangkap tanpa bergantung pada
     * ejaan persis di daftar opsi.
     */
    private const KELOMPOK_RENTAN_KEYWORDS = ['anak', 'disabilitas'];

    /** Keywords that mark an entry in jenis_data_spesifik as sensitive. */
    private const SENSITIVE_KEYWORDS = [
        'kesehatan', 'biometrik', 'genetik', 'anak', 'keuangan',
        'catatan kejahatan', 'ras', 'etnis', 'agama',
        'orientasi seksual', 'pandangan politik',
    ];

    /**
     * @param  array  $wizardData  RoPA wizard_data JSON.
     * @return array{level:string, triggers:array<string>, reasons:array<string>}
     */
    public function calculate(array $wizardData): array
    {
        $info = $wizardData['informasi_pemrosesan'] ?? [];
        $peng = $wizardData['pengumpulan_data'] ?? [];
        $peny = $wizardData['penggunaan_penyimpanan'] ?? [];
        $kirim = $wizardData['pengiriman_data'] ?? [];
        $ret = $wizardData['retensi_keamanan'] ?? [];

        $triggers = [];
        $reasons = [];

        // ─── HIGH triggers ──────────────────────────────────────────────
        if ($this->matches($info['bantuan_ai'] ?? null, self::AI_FULL_DECISION_VALUES)) {
            $triggers[] = 'ai_full_decision';
            $reasons[] = 'Pemrosesan memakai AI untuk keputusan sepenuhnya (Pasal 10 jo. PP 33/2026 Pasal 93–95 UU PDP).';
        }

        if ($this->matches($info['otomatis'] ?? null, self::AUTO_FULL_VALUES)) {
            $triggers[] = 'automated_decision_full';
            $reasons[] = 'Pengambilan keputusan otomatis penuh tanpa campur tangan manusia.';
        }

        $pemrofilan = $info['pemrofilan'] ?? null;
        if ($this->isProfiling($pemrofilan)) {
            $triggers[] = 'profiling';
            $reasons[] = 'Pemrosesan termasuk pemrofilan subjek data ('.(is_array($pemrofilan) ? implode(', ', $pemrofilan) : (string) $pemrofilan).').';
        }

        if ($this->isYes($info['teknologi_baru'] ?? null)) {
            $triggers[] = 'new_technology';
            $reasons[] = 'Pemrosesan menggunakan teknologi baru (emerging tech).';
        }

        if ($this->matches($peng['jumlah_subjek'] ?? null, self::MASS_SUBJECTS_VALUES)) {
            $triggers[] = 'mass_subjects';
            $reasons[] = 'Jumlah subjek data > 1.000 (large-scale processing).';
        }

        $spesifik = $peng['jenis_data_spesifik'] ?? [];
        if ($this->hasSensitiveCategory($spesifik)) {
            $triggers[] = 'sensitive_data';
            $reasons[] = 'Memproses kategori data spesifik/sensitif ('.$this->sensitiveLabel($spesifik).').';
        }

        // Kelompok rentan — dinilai dari SIAPA subjeknya, terpisah dari jenis
        // datanya. Lihat KELOMPOK_RENTAN_KEYWORDS untuk alasannya.
        $subjek = $peng['kategori_subjek'] ?? [];
        if ($this->hasKelompokRentan($subjek)) {
            $triggers[] = 'vulnerable_subjects';
            $reasons[] = 'Subjek datanya termasuk kelompok rentan ('.$this->rentanLabel($subjek).') — '
                .'PP 33/2026 Pasal 38 (Anak) dan Pasal 39 (Penyandang Disabilitas).';
        }

        if ($this->isYes($kirim['transfer_luar'] ?? null)) {
            $triggers[] = 'cross_border_transfer';
            $reasons[] = 'Transfer data ke luar Indonesia (Pasal 56 jo. PP 33/2026 Pasal 160–163 UU PDP — butuh safeguards).';
        }

        if ($this->isYes($ret['pernah_insiden'] ?? null)) {
            $triggers[] = 'prior_incident';
            $reasons[] = 'Pemrosesan ini pernah mengalami insiden/pelanggaran data.';
        }

        if (! empty($triggers)) {
            return ['level' => 'high', 'triggers' => $triggers, 'reasons' => $reasons];
        }

        // ─── MEDIUM triggers ────────────────────────────────────────────
        if ($this->matches($info['bantuan_ai'] ?? null, self::AI_PARTIAL_VALUES)) {
            $triggers[] = 'ai_partial';
            $reasons[] = 'AI membantu pemrosesan dengan review manusia pada keputusan akhir.';
        }

        if ($this->matches($info['otomatis'] ?? null, self::AUTO_PARTIAL_VALUES)) {
            $triggers[] = 'automated_decision_partial';
            $reasons[] = 'Otomatisasi pemrosesan dengan campur tangan manusia.';
        }

        if ($this->isYes($peny['pihak_ketiga'] ?? null)) {
            $triggers[] = 'third_party_processor';
            $reasons[] = 'Data diproses oleh pihak ketiga (processor agreement diperlukan).';
        }

        if (! empty($triggers)) {
            return ['level' => 'medium', 'triggers' => $triggers, 'reasons' => $reasons];
        }

        // ─── Default ────────────────────────────────────────────────────
        return [
            'level' => 'low',
            'triggers' => [],
            'reasons' => ['Tidak ada trigger risiko dari wizard. Default LOW.'],
        ];
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function matches($value, array $candidates): bool
    {
        if ($value === null) {
            return false;
        }
        $str = is_array($value) ? implode('|', $value) : (string) $value;
        $str = strtolower(trim($str));
        foreach ($candidates as $c) {
            if (str_contains($str, strtolower($c))) {
                return true;
            }
        }

        return false;
    }

    private function isYes($v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['ya', 'yes', 'true', '1'], true);
    }

    private function isProfiling($v): bool
    {
        if (empty($v)) {
            return false;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === '' || in_array($s, ['not applicable', 'n/a', 'tidak', 'no'], true)) {
                return false;
            }

            return true;
        }
        if (is_array($v)) {
            $filtered = array_filter($v, fn ($x) => strtolower(trim((string) $x)) !== 'not applicable' && trim((string) $x) !== '');

            return ! empty($filtered);
        }

        return false;
    }

    private function hasSensitiveCategory($spesifik): bool
    {
        $list = is_array($spesifik) ? $spesifik : [$spesifik];
        foreach ($list as $item) {
            $s = strtolower(trim((string) $item));
            if ($s === '' || $s === 'not applicable') {
                continue;
            }
            foreach (self::SENSITIVE_KEYWORDS as $kw) {
                if (str_contains($s, $kw)) {
                    return true;
                }
            }
            // Data kombinasi yang mengidentifikasi seseorang juga dihitung sensitif.
            if (str_contains($s, 'dikombinasikan') && str_contains($s, 'mengidentifikasi')) {
                return true;
            }
        }

        return false;
    }

    /** Apakah ada kelompok rentan di daftar kategori subjek? */
    private function hasKelompokRentan($subjek): bool
    {
        $list = is_array($subjek) ? $subjek : [$subjek];
        foreach ($list as $item) {
            $s = strtolower(trim((string) $item));
            if ($s === '' || $s === 'not applicable') {
                continue;
            }
            foreach (self::KELOMPOK_RENTAN_KEYWORDS as $kw) {
                if (str_contains($s, $kw)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Nama kelompok rentan yang terdeteksi, untuk ditulis di alasan. */
    private function rentanLabel($subjek): string
    {
        $list = is_array($subjek) ? $subjek : [$subjek];
        $cocok = [];
        foreach ($list as $item) {
            $s = strtolower(trim((string) $item));
            foreach (self::KELOMPOK_RENTAN_KEYWORDS as $kw) {
                if ($s !== '' && str_contains($s, $kw)) {
                    $cocok[] = (string) $item;
                    break;
                }
            }
        }

        return empty($cocok) ? '-' : implode(', ', array_unique($cocok));
    }

    private function sensitiveLabel($spesifik): string
    {
        $list = is_array($spesifik) ? $spesifik : [$spesifik];
        $list = array_values(array_filter(array_map('strval', $list), fn ($x) => $x !== '' && strtolower($x) !== 'not applicable'));
        if (empty($list)) {
            return '-';
        }

        return implode(', ', array_slice($list, 0, 3)).(count($list) > 3 ? ', dst.' : '');
    }
}
