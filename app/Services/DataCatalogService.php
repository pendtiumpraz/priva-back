<?php

namespace App\Services;

use App\Models\DataCatalogAsset;
use App\Models\DataCatalogLineage;
use App\Models\InformationSystem;
use App\Models\Ropa;
use Illuminate\Support\Facades\DB;

/**
 * Katalog metadata terpusat dan silsilah antar aset data.
 *
 * Menjawab pertanyaan yang paling sering diajukan pemeriksa dan yang selama
 * ini tidak punya satu tempat untuk dijawab: data pribadi ada di mana saja,
 * siapa pemiliknya, dan mengalir ke mana.
 *
 * Aset dimaterialisasi sebagai baris supaya dapat dicari lintas ratusan ribu
 * kolom tanpa memindai ulang sumbernya, dan supaya katalog dapat menampung
 * aset dari sistem luar yang tidak dapat diturunkan dari data kami sendiri.
 * Konsekuensinya katalog bisa basi — itu ditangani terang-terangan lewat
 * last_synced_at dan sinkronisasi ulang, bukan disembunyikan.
 */
class DataCatalogService
{
    /**
     * Bangun ulang katalog dari hasil pemindaian Data Discovery.
     *
     * Aset bersumber `internal` disegarkan; aset bersumber manual dan impor
     * TIDAK disentuh — keduanya tidak dapat diturunkan ulang, sehingga
     * menimpanya berarti membuang pekerjaan yang tidak dapat dipulihkan.
     *
     * @return array{systems: int, datasets: int, fields: int}
     */
    public function syncFromDiscovery(string $orgId): array
    {
        $counts = ['systems' => 0, 'datasets' => 0, 'fields' => 0];
        $now = now();

        $systems = InformationSystem::withoutGlobalScope('org')->where('org_id', $orgId)->get();

        foreach ($systems as $system) {
            $systemKey = 'system:'.$system->id;

            $this->upsert($orgId, [
                'asset_key' => $systemKey,
                'asset_type' => 'system',
                'name' => $system->name ?: 'Sistem',
                'qualified_name' => $system->name,
                'description' => $system->description,
                'source' => 'internal',
                'information_system_id' => $system->id,
                'steward' => $system->owner,
                'owner_user_id' => $system->owner_id,
                'metadata' => array_filter([
                    'source_type' => $system->source_type,
                    'connection_type' => $system->connection_type,
                    'last_scanned_at' => $system->last_scanned_at?->toIso8601String(),
                    'pii_alert_count' => $system->pii_alert_count,
                    'pdp_alert_count' => $system->pdp_alert_count,
                ], fn ($v) => $v !== null),
                'last_synced_at' => $now,
            ]);
            $counts['systems']++;

            foreach (($system->scan_results['tables'] ?? []) as $table) {
                $tableName = $table['name'] ?? null;
                if (! $tableName) {
                    continue;
                }

                $datasetKey = 'dataset:'.$system->id.'/'.$tableName;
                $columns = $table['columns'] ?? [];

                // Klasifikasi dataset diangkat dari kolom paling sensitif di
                // dalamnya. Dataset yang memuat satu kolom sensitif tidak dapat
                // diperlakukan sebagai internal biasa hanya karena sisanya
                // tidak sensitif.
                $hasSensitive = (bool) array_filter($columns, fn ($c) => ($c['classification'] ?? '') === 'sensitive');
                $hasPii = (bool) array_filter($columns, fn ($c) => ! empty($c['pii_detected']));

                $this->upsert($orgId, [
                    'asset_key' => $datasetKey,
                    'asset_type' => 'dataset',
                    'name' => $tableName,
                    'qualified_name' => ($system->name ?: 'sistem').'.'.$tableName,
                    'source' => 'internal',
                    'information_system_id' => $system->id,
                    'classification' => $hasSensitive ? 'sensitive' : ($hasPii ? 'pii' : 'internal'),
                    'metadata' => array_filter([
                        'row_count' => $table['row_count'] ?? null,
                        'size_mb' => $table['size_mb'] ?? null,
                        'column_count' => count($columns),
                        'pii_column_count' => count(array_filter($columns, fn ($c) => ! empty($c['pii_detected']))),
                    ], fn ($v) => $v !== null),
                    'last_synced_at' => $now,
                ]);
                $counts['datasets']++;

                $this->edge($orgId, $systemKey, $datasetKey, 'processes', 'auto', 'Dataset di dalam sistem');

                foreach ($columns as $column) {
                    $colName = $column['name'] ?? null;
                    if (! $colName) {
                        continue;
                    }

                    $fieldKey = 'field:'.$system->id.'/'.$tableName.'/'.$colName;
                    $this->upsert($orgId, [
                        'asset_key' => $fieldKey,
                        'asset_type' => 'field',
                        'name' => $colName,
                        'qualified_name' => ($system->name ?: 'sistem').'.'.$tableName.'.'.$colName,
                        'description' => $column['pii_reason'] ?? null,
                        'source' => 'internal',
                        'information_system_id' => $system->id,
                        'classification' => $column['classification'] ?? null,
                        'pdp_category' => $column['pdp_category'] ?? null,
                        'encryption_required' => (bool) ($column['encryption_required'] ?? false),
                        'metadata' => array_filter([
                            'type' => $column['type'] ?? null,
                            'nullable' => $column['nullable'] ?? null,
                            'encrypted' => $column['encrypted'] ?? null,
                            'protection_state' => $column['protection_state'] ?? null,
                            'shadow_detected' => $column['shadow_detected'] ?? null,
                        ], fn ($v) => $v !== null),
                        'last_synced_at' => $now,
                    ]);
                    $counts['fields']++;

                    $this->edge($orgId, $datasetKey, $fieldKey, 'references', 'auto', 'Kolom di dalam dataset');
                }
            }
        }

        return $counts;
    }

    /**
     * Turunkan silsilah dari keterkaitan yang sudah tercatat di platform.
     *
     * Hanya tepi bersumber `auto` yang dibangun ulang. Tepi manual dan impor
     * dipertahankan karena tidak dapat diturunkan ulang.
     *
     * @return array{edges: int}
     */
    public function rebuildLineage(string $orgId): array
    {
        // Tepi turunan dibuang lebih dulu supaya keterkaitan yang sudah
        // dihapus di sumbernya tidak tertinggal sebagai silsilah hantu.
        //
        // forceDelete, BUKAN delete. Tepi turunan dibangun ulang utuh pada
        // setiap sinkronisasi, jadi mengarsipkannya tidak menyelamatkan apa pun
        // — yang ada keranjang sampah membengkak ribuan baris tiap sync, dan
        // updateOrCreate di bawah tidak melihat baris terarsip sehingga
        // menabrak indeks unik dcl_unique_edge. Soft delete hanya bermakna
        // untuk tepi manual dan impor, yang tidak dapat diturunkan ulang.
        DataCatalogLineage::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('source', 'auto')
            ->where('relation', 'feeds')
            ->forceDelete();

        $count = 0;
        $ropas = Ropa::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->with('informationSystems')
            ->get();

        foreach ($ropas as $ropa) {
            $activityKey = 'report:ropa/'.$ropa->id;

            $this->upsert($orgId, [
                'asset_key' => $activityKey,
                'asset_type' => 'report',
                'name' => $ropa->processing_activity ?: 'Kegiatan Pemrosesan',
                'qualified_name' => 'RoPA '.($ropa->registration_number ?: ''),
                'description' => $ropa->purpose,
                'source' => 'internal',
                'classification' => $ropa->risk_level === 'high' ? 'sensitive' : null,
                'metadata' => array_filter([
                    'registration_number' => $ropa->registration_number,
                    'risk_level' => $ropa->risk_level,
                    'division' => $ropa->division,
                    'legal_basis' => $ropa->legal_basis,
                ], fn ($v) => $v !== null && $v !== ''),
                'last_synced_at' => now(),
            ]);

            // Sistem memasok kegiatan pemrosesan. Arahnya sengaja dari sistem
            // KE kegiatan: itulah arah aliran datanya, bukan arah keterkaitan
            // administratifnya.
            foreach ($ropa->informationSystems as $system) {
                $this->edge(
                    $orgId,
                    'system:'.$system->id,
                    $activityKey,
                    'feeds',
                    'auto',
                    'Sistem memasok data untuk kegiatan pemrosesan'
                );
                $count++;
            }

            // Penerima data sebagai aset di luar batas sistem kami.
            foreach ((array) ($ropa->recipients ?? []) as $recipient) {
                $label = is_array($recipient) ? ($recipient['label'] ?? $recipient['name'] ?? null) : $recipient;
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }
                $recipientKey = 'system:external/'.$this->slug($label);

                $this->upsert($orgId, [
                    'asset_key' => $recipientKey,
                    'asset_type' => 'system',
                    'name' => trim($label),
                    'qualified_name' => 'Eksternal — '.trim($label),
                    'source' => 'internal',
                    'metadata' => ['external' => true],
                    'last_synced_at' => now(),
                ]);

                $this->edge($orgId, $activityKey, $recipientKey, 'exports', 'auto', 'Data dikirim ke penerima');
                $count++;
            }
        }

        return ['edges' => $count];
    }

    /**
     * Telusuri silsilah dari satu aset, ke hulu dan ke hilir.
     *
     * Kedalaman dibatasi dan simpul yang sudah dikunjungi dicatat: graf
     * silsilah pada organisasi besar hampir selalu mengandung siklus (sistem A
     * memasok B yang menyalin balik ke A), dan penelusuran tanpa penanda
     * kunjungan akan berputar sampai kehabisan memori.
     *
     * @return array{nodes: array, edges: array, truncated: bool}
     */
    public function trace(string $orgId, string $assetKey, int $depth = 3): array
    {
        $depth = max(1, min($depth, 6));
        $visited = [];
        $edges = [];
        $frontier = [$assetKey];
        $truncated = false;

        for ($level = 0; $level < $depth; $level++) {
            if (empty($frontier)) {
                break;
            }

            $rows = DataCatalogLineage::withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where(fn ($q) => $q->whereIn('from_key', $frontier)->orWhereIn('to_key', $frontier))
                ->limit(500)
                ->get();

            if ($rows->count() >= 500) {
                $truncated = true;
            }

            foreach ($frontier as $key) {
                $visited[$key] = true;
            }

            $next = [];
            foreach ($rows as $row) {
                $edgeKey = $row->from_key.'>'.$row->to_key.':'.$row->relation;
                if (isset($edges[$edgeKey])) {
                    continue;
                }
                $edges[$edgeKey] = [
                    'from' => $row->from_key,
                    'to' => $row->to_key,
                    'relation' => $row->relation,
                    'source' => $row->source,
                    'description' => $row->description,
                ];
                foreach ([$row->from_key, $row->to_key] as $candidate) {
                    if (! isset($visited[$candidate])) {
                        $next[$candidate] = true;
                    }
                }
            }
            $frontier = array_keys($next);
        }

        foreach ($frontier as $key) {
            $visited[$key] = true;
        }

        $nodes = DataCatalogAsset::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereIn('asset_key', array_keys($visited))
            ->get()
            ->keyBy('asset_key');

        // Kunci yang tidak punya aset tetap dikembalikan sebagai simpul
        // bertanda `unknown`. Itulah tepi yang menunjuk ke luar batas katalog
        // — justru yang paling menarik saat menelusuri kebocoran data.
        $out = [];
        foreach (array_keys($visited) as $key) {
            $asset = $nodes->get($key);
            $out[] = $asset
                ? $asset->only(['asset_key', 'asset_type', 'name', 'qualified_name', 'classification', 'pdp_category', 'source'])
                : ['asset_key' => $key, 'asset_type' => 'unknown', 'name' => $key, 'source' => 'unknown'];
        }

        return ['nodes' => $out, 'edges' => array_values($edges), 'truncated' => $truncated];
    }

    /**
     * Impor aset dan silsilah dari katalog data pihak lain.
     *
     * Menerima bentuk umum yang dikeluarkan Collibra, Alation, dan Purview
     * lewat ekspor maupun API, dengan pemetaan kolom yang dapat disesuaikan.
     * Kami sengaja TIDAK mengirimkan SDK khusus tiap vendor: antarmukanya
     * berubah tanpa pemberitahuan, dan setiap klien enterprise sudah memiliki
     * jalur ekspor yang mereka kendalikan sendiri.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping  kolom internal → kunci pada data sumber
     * @return array{imported: int, edges: int, skipped: int}
     */
    public function import(string $orgId, array $rows, string $source, array $mapping = []): array
    {
        $map = array_merge([
            'asset_key' => 'id',
            'name' => 'name',
            'asset_type' => 'type',
            'qualified_name' => 'qualified_name',
            'description' => 'description',
            'classification' => 'classification',
            'pdp_category' => 'pdp_category',
            'encryption_required' => 'encryption_required',
            'steward' => 'steward',
            'parent_key' => 'parent_id',
        ], $mapping);

        $imported = 0;
        $edges = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $rawKey = $row[$map['asset_key']] ?? null;
            $name = $row[$map['name']] ?? null;
            if (! $rawKey || ! $name) {
                $skipped++;

                continue;
            }

            // Kunci dari sumber luar diberi awalan agar tidak pernah bentrok
            // dengan kunci internal yang dibangkitkan sinkronisasi.
            $assetKey = $source.':'.$rawKey;
            $type = strtolower((string) ($row[$map['asset_type']] ?? 'dataset'));
            if (! in_array($type, DataCatalogAsset::TYPES, true)) {
                $type = 'dataset';
            }

            $this->upsert($orgId, [
                'asset_key' => $assetKey,
                'asset_type' => $type,
                'name' => (string) $name,
                'qualified_name' => $row[$map['qualified_name']] ?? null,
                'description' => $row[$map['description']] ?? null,
                'classification' => $row[$map['classification']] ?? null,
                'pdp_category' => $row[$map['pdp_category']] ?? null,
                'encryption_required' => (bool) ($row[$map['encryption_required']] ?? false),
                'steward' => $row[$map['steward']] ?? null,
                'source' => in_array($source, DataCatalogAsset::SOURCES, true) ? $source : 'custom',
                'source_ref' => (string) $rawKey,
                'metadata' => ['imported_raw' => array_slice($row, 0, 20)],
                'last_synced_at' => now(),
            ]);
            $imported++;

            $parent = $row[$map['parent_key']] ?? null;
            if ($parent) {
                $this->edge($orgId, $source.':'.$parent, $assetKey, 'references', 'imported', 'Hierarki dari katalog '.$source);
                $edges++;
            }
        }

        return ['imported' => $imported, 'edges' => $edges, 'skipped' => $skipped];
    }

    /** @param  array<string, mixed>  $attrs */
    private function upsert(string $orgId, array $attrs): void
    {
        $key = $attrs['asset_key'];
        unset($attrs['asset_key']);

        DataCatalogAsset::withoutGlobalScope('org')->updateOrCreate(
            ['org_id' => $orgId, 'asset_key' => $key],
            $attrs
        );
    }

    private function edge(string $orgId, string $from, string $to, string $relation, string $source, ?string $description = null): void
    {
        try {
            // Tepi yang sudah dihapus tenant tidak dihidupkan lagi. Penghapusan
            // manual adalah keputusan — kalau sinkronisasi berikutnya
            // memunculkannya kembali, penghapusan itu tidak pernah berarti dan
            // tenant akan menghapusnya berulang kali tanpa hasil.
            $trashed = DataCatalogLineage::withoutGlobalScope('org')
                ->onlyTrashed()
                ->where('org_id', $orgId)
                ->where('from_key', $from)
                ->where('to_key', $to)
                ->where('relation', $relation)
                ->exists();

            if ($trashed) {
                return;
            }

            DataCatalogLineage::withoutGlobalScope('org')->updateOrCreate(
                ['org_id' => $orgId, 'from_key' => $from, 'to_key' => $to, 'relation' => $relation],
                ['source' => $source, 'description' => $description]
            );
        } catch (\Throwable) {
            // Balapan pada indeks unik saat sinkronisasi paralel bukan alasan
            // menggagalkan keseluruhan proses — tepinya toh sudah ada.
        }
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value));

        return trim((string) $slug, '-') ?: substr(sha1($value), 0, 12);
    }

    /** Ringkasan katalog untuk papan pemantauan. */
    public function summary(string $orgId): array
    {
        $assets = DataCatalogAsset::withoutGlobalScope('org')->where('org_id', $orgId);

        return [
            'total_assets' => (clone $assets)->count(),
            'by_type' => (clone $assets)->select('asset_type', DB::raw('COUNT(*) as total'))
                ->groupBy('asset_type')->pluck('total', 'asset_type'),
            'by_source' => (clone $assets)->select('source', DB::raw('COUNT(*) as total'))
                ->groupBy('source')->pluck('total', 'source'),
            'sensitive_assets' => (clone $assets)->where('classification', 'sensitive')->count(),
            'total_edges' => DataCatalogLineage::withoutGlobalScope('org')->where('org_id', $orgId)->count(),
            'last_synced_at' => (clone $assets)->max('last_synced_at'),
        ];
    }
}
