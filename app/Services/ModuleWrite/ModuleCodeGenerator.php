<?php

namespace App\Services\ModuleWrite;

use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\Department;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\ProcessingCategory;
use App\Models\Ropa;
use App\Services\RegistrationCodeService;
use Illuminate\Database\Eloquent\Model;

/**
 * Penghasil nomor pendaftaran modul (ROPA/DPIA/DSR/CNT/BRC).
 *
 * Diangkat apa adanya dari ModuleCrudController supaya ada SATU rumah. Kendala
 * unik kolom kode bersifat global (bukan per organisasi), dan temuan F-03 lahir
 * justru karena tiga jalur masing-masing menyalin penghasil nomornya sendiri
 * lalu menyimpang. Menyalinnya sekali lagi ke service tulis RoPA/DPIA akan
 * mengulang sebab yang sama — karena itu dipakai bersama, bukan diduplikasi.
 *
 * Tiga cara penomoran, berurutan:
 *   1. kategori pemrosesan  → ROPA-<KODE KATEGORI>[-<NOMOR KHUSUS>]-NNN
 *   2. kode divisi          → ROPA-<KODE DIVISI>-<TAHUN>[-<NOMOR KHUSUS>]-NNN
 *   3. tanpa keduanya       → ROPA-<TAHUN>-NNN, dihitung LINTAS tenant
 */
class ModuleCodeGenerator
{
    /** Kolom penyimpan kode, per prefiks. */
    private const CODE_COLUMN = [
        'ROPA' => 'registration_number',
        'DPIA' => 'registration_number',
        'DSR' => 'request_id',
        'CNT' => 'collection_id',
        'BRC' => 'incident_code',
    ];

    public function __construct(
        private RegistrationCodeService $codes,
    ) {}

    /**
     * Penomoran berbasis kode divisi membaca baris terhapus juga (`withTrashed`),
     * supaya nomor bekas record yang dihapus tidak dipakai ulang. Karena itu
     * yang diterima bukan sembarang Model, melainkan model bermodel kode yang
     * memang soft-delete — didaftar tegas agar pemeriksa tipe ikut menjaganya.
     *
     * @param  Ropa|Dpia|DsrRequest|ConsentCollectionPoint|BreachIncident  $model
     */
    public function next(
        string $prefix,
        $model,
        string $orgId,
        ?string $categoryId = null,
        ?string $customNumber = null,
        ?string $divisionCode = null,
    ): string {
        $year = date('Y');

        if ($categoryId) {
            $category = ProcessingCategory::where('org_id', $orgId)->where('id', $categoryId)->first();
            if ($category) {
                $module = in_array($prefix, ['ROPA', 'DPIA'], true) ? strtolower($prefix) : 'ropa';
                $counter = $category->nextCounter($module);
                $segments = [$prefix, strtoupper($category->code)];
                if ($customNumber !== null && $customNumber !== '') {
                    $segments[] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($customNumber));
                }
                $segments[] = str_pad((string) $counter, 3, '0', STR_PAD_LEFT);

                return implode('-', array_filter($segments, fn ($s) => $s !== ''));
            }
        }

        // Penomoran berbasis kode divisi penanggung jawab (mis. "HR" →
        // ROPA-HR-2026-001). Counter per (prefiks, divisi, tahun) diturunkan
        // dari max+1.
        $cleanDiv = $divisionCode ? preg_replace('/[^A-Za-z0-9]/', '', strtoupper($divisionCode)) : null;
        if ($cleanDiv) {
            $codeColumn = self::CODE_COLUMN[$prefix] ?? 'registration_number';
            $pattern = $prefix.'-'.$cleanDiv.'-'.$year.'-%';
            $codes = $model->withTrashed()
                ->where('org_id', $orgId)
                ->where($codeColumn, 'like', $pattern)
                ->pluck($codeColumn)
                ->toArray();
            $maxNum = 0;
            foreach ($codes as $code) {
                $num = (int) substr($code, strrpos($code, '-') + 1);
                if ($num > $maxNum) {
                    $maxNum = $num;
                }
            }
            $segments = [$prefix, $cleanDiv, $year];
            if ($customNumber !== null && $customNumber !== '') {
                $segments[] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($customNumber));
            }
            $segments[] = str_pad((string) ($maxNum + 1), 3, '0', STR_PAD_LEFT);

            return implode('-', array_filter($segments, fn ($s) => $s !== ''));
        }

        // Tanpa kategori dan tanpa kode divisi — dihitung lintas tenant, selaras
        // dengan kendala uniknya yang memang global.
        return $this->codes->nextGlobal($prefix, get_class($model));
    }

    /**
     * Kode divisi penanggung jawab sebuah RoPA, dibaca dari wizard.
     *
     * Tiga kandidat dicoba berurutan karena wizard berganti bentuk beberapa kali
     * dan record lama masih memakai yang terdahulu.
     *
     * @param  array<string, mixed>  $data
     */
    public function divisionCodeForRopa(array $data, ?string $orgId): ?string
    {
        if (! $orgId) {
            return null;
        }

        $wiz = $data['wizard_data'] ?? null;
        $wiz = is_array($wiz) ? $wiz : (is_string($wiz) ? (json_decode($wiz, true) ?: []) : []);
        $detail = $wiz['detail_pemrosesan'] ?? [];
        $candidates = [
            $detail['divisi_penanggung_jawab'] ?? null,
            $detail['divisi'] ?? null,
            $data['division'] ?? null,
        ];

        foreach ($candidates as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $dept = Department::where('org_id', $orgId)->where('name', $name)->first();
            if ($dept && ! empty($dept->code)) {
                return (string) $dept->code;
            }
        }

        return null;
    }
}
