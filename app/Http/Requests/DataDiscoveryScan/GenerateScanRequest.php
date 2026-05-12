<?php

namespace App\Http\Requests\DataDiscoveryScan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body of POST /api/data-discovery/scan/generate.
 *
 * **Email wajib, nama opsional** (perubahan dari versi sebelumnya yang
 * menjadikan nama sebagai filter utama via LIKE). Alasan: tabel tenant
 * client bisa ter-tabyte; `LIKE '%nama%'` memicu full table scan tanpa
 * memanfaatkan index, sangat mahal. Email biasanya stored exact &
 * ter-index, jadi dipakai sebagai filter utama eksak. Nama dipakai
 * sebagai filter sekunder bila diisi — juga eksak (no LIKE), karena
 * jarang nama disimpan dengan variasi format yang konsisten cross-tabel.
 */
class GenerateScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Email primary identifier — exact match di SQL, biasanya
            // ter-index. Wajib supaya scan tidak men-degenerate jadi
            // full-table-scan via LIKE.
            'email' => ['required', 'email', 'max:191'],
            // Nama opsional. Bila diisi minimal 3 karakter. Saat scan
            // berjalan, klausa filter nama memakai equality (=), bukan LIKE.
            'name' => ['nullable', 'string', 'min:3', 'max:191'],
            'nik' => ['nullable', 'digits:16'],
            'phone' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date_format:Y-m-d'],
            // Subset InformationSystem yang mau di-scan. Kosong/null =
            // scan semua DB systems org user. Wajib UUID, tenant-scoped
            // di service layer (anti tenant leak).
            'target_system_ids' => ['nullable', 'array'],
            'target_system_ids.*' => ['uuid'],
        ];
    }
}
