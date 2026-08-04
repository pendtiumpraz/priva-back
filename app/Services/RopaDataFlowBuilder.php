<?php

namespace App\Services;

use App\Models\Ropa;
use App\Models\RopaDataFlow;

/**
 * Menurunkan peta alur data dari isi RoPA, lalu menggabungkannya dengan
 * suntingan manual pengguna.
 *
 * Petanya TIDAK disimpan. Ia dibangun ulang setiap kali diminta, karena peta
 * yang disimpan akan basi diam-diam begitu RoPA-nya berubah — dan peta alur
 * data yang basi lebih berbahaya daripada tidak punya peta sama sekali: ia
 * dipakai untuk membuktikan kepatuhan Pasal 31 UU PDP.
 *
 * Kunci simpul dibuat deterministik dari isi RoPA (mis. `source:mitra-agen`),
 * bukan acak. Itulah yang memungkinkan suntingan manual tetap menempel pada
 * simpul yang sama setelah RoPA diperbarui dan peta diturunkan ulang.
 */
class RopaDataFlowBuilder
{
    public const TYPE_SOURCE = 'source';

    public const TYPE_PROCESS = 'process';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_STORAGE = 'storage';

    public const TYPE_RECIPIENT = 'recipient';

    public const TYPE_CROSS_BORDER = 'cross_border';

    public const TYPE_DISPOSAL = 'disposal';

    /** Peta lengkap: otomatis + manual, siap dirender. */
    public function build(Ropa $ropa): array
    {
        $auto = $this->derive($ropa);
        $manual = RopaDataFlow::where('ropa_id', $ropa->id)->first();

        return $this->merge($auto, $manual);
    }

    /**
     * Turunkan graf murni dari isi RoPA.
     *
     * Urutan simpulnya mengikuti perjalanan data yang sesungguhnya: dari mana
     * data datang, diproses di mana, disimpan di mana, dikirim ke siapa, dan
     * berakhir bagaimana.
     */
    public function derive(Ropa $ropa): array
    {
        $wizard = $ropa->wizard_data ?? [];
        $nodes = [];
        $edges = [];

        $section = fn (string $key) => $wizard[$key] ?? [];
        $pengumpulan = $section('pengumpulan_data');
        $informasi = $section('informasi_pemrosesan');
        $penyimpanan = $section('penggunaan_penyimpanan');
        $pengiriman = $section('pengiriman_data');
        $retensi = $section('retensi_keamanan');

        // ---- Simpul pusat: kegiatan pemrosesan itu sendiri --------------
        $processKey = 'process:'.$ropa->id;
        $nodes[] = [
            'key' => $processKey,
            'type' => self::TYPE_PROCESS,
            'label' => $ropa->processing_activity ?: 'Kegiatan Pemrosesan',
            'description' => $ropa->purpose ?: ($ropa->description ?: null),
            'meta' => array_filter([
                'registration_number' => $ropa->registration_number,
                'legal_basis' => $ropa->legal_basis,
                'risk_level' => $ropa->risk_level,
                'division' => $ropa->division,
            ], fn ($v) => $v !== null && $v !== ''),
            'origin' => 'auto',
        ];

        // ---- Sumber data ------------------------------------------------
        // Subjek data dan sumber perolehan dibedakan: yang pertama adalah
        // SIAPA datanya, yang kedua adalah DARI MANA diperolehnya. Menyatukan
        // keduanya akan menyembunyikan perolehan tidak langsung — justru
        // jalur yang paling sering luput dari asesmen.
        foreach ($this->values($pengumpulan['kategori_subjek'] ?? $ropa->data_subjects) as $subject) {
            $key = 'subject:'.$this->slug($subject);
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_SOURCE,
                'label' => $subject,
                'description' => 'Subjek data',
                'meta' => ['role' => 'subjek_data'],
                'origin' => 'auto',
            ];
            $edges[] = $this->edge($key, $processKey, 'Pengumpulan', 'collect');
        }

        foreach ($this->values($pengumpulan['sumber_data'] ?? null) as $source) {
            $key = 'source:'.$this->slug($source);
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_SOURCE,
                'label' => $source,
                'description' => 'Sumber perolehan data',
                'meta' => ['role' => 'sumber_data'],
                'origin' => 'auto',
            ];
            $edges[] = $this->edge($key, $processKey, 'Perolehan', 'collect');
        }

        // ---- Sistem yang memproses --------------------------------------
        // Dua asal: relasi information_system_ropa (tertaut ke Data Discovery,
        // jadi kolomnya sudah terklasifikasi) dan isian bebas `sistem_terkait`.
        foreach ($ropa->informationSystems as $system) {
            $key = 'system:'.$system->id;
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_SYSTEM,
                'label' => $system->name ?? 'Sistem',
                'description' => $system->description ?? null,
                'meta' => array_filter([
                    'source_type' => $system->source_type ?? null,
                    'information_system_id' => $system->id,
                    'linked' => true,
                ]),
                'origin' => 'auto',
            ];
            $edges[] = $this->edge($processKey, $key, 'Diproses di', 'process');
        }

        $linkedNames = $ropa->informationSystems->pluck('name')->filter()->map(
            fn ($n) => mb_strtolower(trim((string) $n))
        )->all();

        foreach ($this->values($informasi['sistem_terkait'] ?? null) as $systemName) {
            // Lewati yang sudah hadir sebagai sistem tertaut, supaya satu
            // sistem tidak muncul dua kali hanya karena namanya juga diketik
            // manual di wizard.
            if (in_array(mb_strtolower(trim($systemName)), $linkedNames, true)) {
                continue;
            }
            $key = 'system:'.$this->slug($systemName);
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_SYSTEM,
                'label' => $systemName,
                'description' => 'Sistem terkait (dari wizard)',
                'meta' => ['linked' => false],
                'origin' => 'auto',
            ];
            $edges[] = $this->edge($processKey, $key, 'Diproses di', 'process');
        }

        // ---- Penyimpanan ------------------------------------------------
        foreach ($this->values($penyimpanan['lokasi_penyimpanan'] ?? null) as $location) {
            $key = 'storage:'.$this->slug($location);
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_STORAGE,
                'label' => $location,
                'description' => 'Lokasi penyimpanan',
                'meta' => array_filter(['cara_pemrosesan' => $penyimpanan['cara_pemrosesan'] ?? null]),
                'origin' => 'auto',
            ];
            $edges[] = $this->edge($processKey, $key, 'Disimpan di', 'store');
        }

        // ---- Penerima data ----------------------------------------------
        foreach ($this->values($ropa->recipients) as $recipient) {
            $key = 'recipient:'.$this->slug($recipient);
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_RECIPIENT,
                'label' => $recipient,
                'description' => 'Penerima data',
                'meta' => array_filter(['transfer_domestik' => $pengiriman['transfer_domestik'] ?? null]),
                'origin' => 'auto',
            ];
            $edges[] = $this->edge($processKey, $key, 'Dikirim ke', 'transfer');
        }

        // ---- Transfer lintas negara -------------------------------------
        // Diberi jenis panah tersendiri karena inilah yang memicu kewajiban
        // Pasal 56 UU PDP; menyamakannya dengan transfer domestik akan
        // menyembunyikan justru bagian yang wajib dibuktikan.
        $safeguards = $pengiriman['safeguards'] ?? null;
        foreach ($this->values($pengiriman['negara_tujuan'] ?? null) as $country) {
            $key = 'cross_border:'.$this->slug($country);
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_CROSS_BORDER,
                'label' => $country,
                'description' => 'Tujuan transfer lintas negara',
                'meta' => array_filter([
                    'safeguards' => is_array($safeguards) ? implode(', ', $safeguards) : $safeguards,
                    'transfer_internasional' => $pengiriman['transfer_internasional'] ?? null,
                ]),
                'origin' => 'auto',
            ];
            $edges[] = $this->edge(
                $processKey,
                $key,
                $safeguards ? 'Transfer lintas negara' : 'Transfer lintas negara (safeguard belum diisi)',
                'cross_border'
            );
        }

        // ---- Akhir siklus: pemusnahan -----------------------------------
        $retention = $retensi['retention_period'] ?? $ropa->retention_period;
        $disposal = $retensi['prosedur_pemusnahan'] ?? null;
        if ($retention || $disposal) {
            $key = 'disposal:'.$ropa->id;
            $nodes[] = [
                'key' => $key,
                'type' => self::TYPE_DISPOSAL,
                'label' => $disposal ?: 'Pemusnahan data',
                'description' => $retention ? 'Retensi: '.(is_array($retention) ? implode(', ', $retention) : $retention) : null,
                'meta' => array_filter([
                    'retention_period' => is_array($retention) ? implode(', ', $retention) : $retention,
                    'retention_due_date' => $ropa->retention_due_date,
                ]),
                'origin' => 'auto',
            ];
            // Pemusnahan mengikuti penyimpanan bila ada; kalau tidak, langsung
            // dari kegiatan pemrosesan.
            $storageKeys = array_values(array_filter(
                array_column($nodes, 'key'),
                fn ($k) => str_starts_with($k, 'storage:')
            ));
            foreach ($storageKeys ?: [$processKey] as $from) {
                $edges[] = $this->edge($from, $key, 'Dimusnahkan', 'dispose');
            }
        }

        return [
            'nodes' => $this->dedupeByKey($nodes),
            'edges' => $this->dedupeByKey($edges),
        ];
    }

    /**
     * Gabungkan graf otomatis dengan lapisan manual.
     *
     * Urutannya penting: sembunyikan dulu, baru terapkan penyuntingan, baru
     * tambahkan elemen manual. Membalik urutan akan membuat elemen manual
     * ikut tersaring oleh daftar sembunyi yang dimaksudkan untuk elemen
     * otomatis.
     */
    public function merge(array $auto, ?RopaDataFlow $manual): array
    {
        $hidden = $manual?->hidden_keys ?? [];
        $overrides = $manual?->overrides ?? [];
        $positions = $manual?->positions ?? [];

        $apply = function (array $items) use ($hidden, $overrides, $positions) {
            $out = [];
            foreach ($items as $item) {
                $key = $item['key'];
                if (in_array($key, $hidden, true)) {
                    continue;
                }
                if (isset($overrides[$key]) && is_array($overrides[$key])) {
                    // Kunci, jenis, dan asal tidak boleh ditimpa: tanpa itu
                    // suntingan bisa memutus tautan ke simpul otomatisnya.
                    $safe = $overrides[$key];
                    unset($safe['key'], $safe['origin'], $safe['type']);
                    $item = array_merge($item, $safe);
                    $item['edited'] = true;
                }
                if (isset($positions[$key])) {
                    $item['position'] = $positions[$key];
                }
                $out[] = $item;
            }

            return $out;
        };

        $nodes = $apply($auto['nodes']);
        $edges = $apply($auto['edges']);

        foreach ($manual?->manual_nodes ?? [] as $node) {
            $node['origin'] = 'manual';
            if (isset($positions[$node['key'] ?? ''])) {
                $node['position'] = $positions[$node['key']];
            }
            $nodes[] = $node;
        }
        foreach ($manual?->manual_edges ?? [] as $edge) {
            $edge['origin'] = 'manual';
            $edges[] = $edge;
        }

        // Panah yang menunjuk simpul tidak ada — bisa terjadi ketika pengguna
        // menyembunyikan simpul otomatis tetapi panah manualnya tertinggal —
        // dibuang, karena penggambar akan gagal atau menampilkan panah
        // menggantung yang membingungkan.
        $nodeKeys = array_column($nodes, 'key');
        $edges = array_values(array_filter(
            $edges,
            fn ($e) => in_array($e['from'] ?? null, $nodeKeys, true)
                && in_array($e['to'] ?? null, $nodeKeys, true)
        ));

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'stats' => [
                'total_nodes' => count($nodes),
                'total_edges' => count($edges),
                'manual_nodes' => count(array_filter($nodes, fn ($n) => ($n['origin'] ?? '') === 'manual')),
                'hidden' => count($hidden),
                'cross_border' => count(array_filter(
                    $nodes,
                    fn ($n) => ($n['type'] ?? '') === self::TYPE_CROSS_BORDER
                )),
            ],
            'notes' => $manual?->notes,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function edge(string $from, string $to, string $label, string $kind): array
    {
        return [
            'key' => 'edge:'.$kind.':'.$from.'>'.$to,
            'from' => $from,
            'to' => $to,
            'label' => $label,
            'kind' => $kind,
            'origin' => 'auto',
        ];
    }

    /**
     * Normalisasi isian wizard yang bentuknya tidak seragam.
     *
     * Lapangan menunjukkan tiga bentuk untuk data yang sama: array, string
     * dipisah koma, dan array asosiatif berisi label. Ketiganya harus diterima
     * — memaksa satu bentuk berarti sebagian RoPA yang sudah terisi tidak akan
     * pernah memunculkan simpulnya.
     */
    private function values(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $parts = preg_split('/[;,\n]+/', $raw) ?: [];

            return array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $label = $item['label'] ?? $item['name'] ?? $item['nama'] ?? $item['value'] ?? null;
                if (is_string($label) && trim($label) !== '') {
                    $out[] = trim($label);
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value));

        return trim((string) $slug, '-') ?: substr(sha1($value), 0, 12);
    }

    private function dedupeByKey(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = $item['key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }
}
