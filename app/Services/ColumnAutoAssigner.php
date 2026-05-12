<?php

namespace App\Services;

/**
 * Auto-assign `applied_status` pada hasil scan kolom.
 *
 * Setelah standar scan / deep scan menghasilkan klasifikasi sementara
 * (`pii_detected`, `shadow_detected`, `classification`, `pdp_category`),
 * helper ini memetakan klasifikasi tersebut menjadi keputusan akhir
 * (`applied_status`) sehingga user TIDAK perlu apply manual per kolom.
 * User hanya klik "Edit" bila ingin mengubah keputusan otomatis ini.
 *
 * Mapping kebijakan:
 *  - `classification === 'sensitive'`
 *      → applied_status='applied_sensitive', applied_classification='sensitif'
 *  - `classification === 'pii'` ATAU `pii_detected === true`
 *      → applied_status='applied_pribadi', applied_classification='pribadi'
 *  - Selainnya
 *      → applied_status='not_pii', applied_classification=null
 *
 * Jika kolom SUDAH punya `applied_status` (mis. user sudah override via
 * endpoint apply) maka tidak ditimpa — auto-assign hanya fill yang kosong.
 */
class ColumnAutoAssigner
{
    /**
     * Pertahankan keputusan user manual saat scan ulang.
     *
     * Saat standar/deep scan dijalankan kembali, hasil scanner baru otomatis
     * meng-overwrite `scan_results`. Tanpa merge ini, kolom yang user sudah
     * Edit manual (mis. user override Data Pribadi → Data Umum) akan
     * kehilangan keputusan tersebut.
     *
     * Aturan: copy field `applied_*` dari kolom lama (matching table.column
     * by name) ke kolom baru BILA `applied_note` lama BUKAN `'auto_scan'`.
     * Itu berarti user sudah melakukan override manual; harus dipertahankan.
     *
     * @param  array<int,array<string,mixed>>  $newTables
     * @param  array<int,array<string,mixed>>  $oldTables
     * @return array<int,array<string,mixed>>
     */
    public static function mergePreserveUserEdits(array $newTables, array $oldTables): array
    {
        if (empty($oldTables)) {
            return $newTables;
        }
        $oldIndex = [];
        foreach ($oldTables as $tbl) {
            $tn = $tbl['name'] ?? null;
            if (! $tn) {
                continue;
            }
            foreach (($tbl['columns'] ?? []) as $col) {
                $cn = $col['name'] ?? null;
                if (! $cn) {
                    continue;
                }
                $oldIndex["{$tn}|{$cn}"] = $col;
            }
        }
        foreach ($newTables as &$tbl) {
            $tn = $tbl['name'] ?? null;
            if (! $tn || ! isset($tbl['columns']) || ! is_array($tbl['columns'])) {
                continue;
            }
            foreach ($tbl['columns'] as &$col) {
                $cn = $col['name'] ?? null;
                $key = "{$tn}|{$cn}";
                $old = $oldIndex[$key] ?? null;
                if (! $old) {
                    continue;
                }
                // Indikator user manual edit = applied_by NON-NULL (user UUID).
                // Auto-assign dari scanner selalu set applied_by=null.
                if (empty($old['applied_by']) || empty($old['applied_status'])) {
                    continue;
                }
                $col['applied_status'] = $old['applied_status'];
                $col['applied_classification'] = $old['applied_classification'] ?? null;
                $col['applied_at'] = $old['applied_at'] ?? null;
                $col['applied_by'] = $old['applied_by'];
                $col['applied_note'] = $old['applied_note'] ?? null;
            }
            unset($col);
        }
        unset($tbl);

        return $newTables;
    }

    /**
     * Loop semua tabel & kolom, isi applied_status yang masih kosong.
     *
     * @param  array<int,array<string,mixed>>  $tables
     * @return array<int,array<string,mixed>>
     */
    public static function autoAssignTables(array $tables): array
    {
        foreach ($tables as &$table) {
            if (! isset($table['columns']) || ! is_array($table['columns'])) {
                continue;
            }
            foreach ($table['columns'] as &$col) {
                $col = self::autoAssignColumn($col);
            }
            unset($col);
        }
        unset($table);

        return $tables;
    }

    /**
     * Set applied_status untuk satu kolom bila belum di-set.
     *
     * @param  array<string,mixed>  $col
     * @return array<string,mixed>
     */
    public static function autoAssignColumn(array $col): array
    {
        // Jangan override keputusan yang sudah ada (user manual / sebelumnya).
        if (! empty($col['applied_status'])) {
            return $col;
        }

        $classification = strtolower((string) ($col['classification'] ?? ''));
        $piiDetected = (bool) ($col['pii_detected'] ?? false);

        if ($classification === 'sensitive') {
            $col['applied_status'] = 'applied_sensitive';
            $col['applied_classification'] = 'sensitif';
        } elseif ($classification === 'pii' || $piiDetected) {
            $col['applied_status'] = 'applied_pribadi';
            $col['applied_classification'] = 'pribadi';
        } else {
            $col['applied_status'] = 'not_pii';
            $col['applied_classification'] = null;
        }

        // Tandai auto-assign supaya UI bisa beri hint "Auto" yang membantu
        // user mengenali kolom yang belum direview manual.
        $col['applied_at'] = now()->toIso8601String();
        $col['applied_by'] = null;
        if (empty($col['applied_note'])) {
            $col['applied_note'] = 'auto_scan';
        }

        return $col;
    }
}
