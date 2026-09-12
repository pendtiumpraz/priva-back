<?php

namespace App\Services;

use App\Models\VendorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyambungkan kontrak pihak ketiga ke modul Contract Review.
 *
 * Dipakai DUA jalur yang berbeda sifatnya:
 *   - tenant menekan "Kirim ke Telaah" (terautentikasi, ada penggunanya);
 *   - pihak ketiga mengunggah lewat tautan publik (anonim, tanpa pengguna).
 *
 * Keduanya harus menghasilkan baris telaah yang identik bentuknya, karena itu
 * logikanya duduk di sini, bukan disalin di dua controller. `contract_reviews`
 * belum punya model Eloquent — idiom yang ada memakai query builder, dan itu
 * dipertahankan supaya tidak ada dua cara menulis tabel yang sama.
 *
 * Tautannya dibuat DUA ARAH: `vendor_contracts.contract_review_id` menunjuk ke
 * telaah, dan `contract_reviews.source_document_id` + `source_module` menunjuk
 * balik ke kontraknya. Tanpa arah balik, halaman telaah tidak punya cara tahu
 * kontrak siapa yang sedang dinilai.
 */
class ContractReviewLinker
{
    public const SOURCE_MODULE = 'vendor_contract';

    /**
     * Buat baris telaah untuk sebuah kontrak, lalu tautkan dua arah.
     *
     * Mengembalikan id telaah — baik yang baru dibuat maupun yang sudah ada.
     * Idempoten: kontrak yang sudah tertaut tidak pernah menghasilkan telaah
     * kedua, karena dua telaah untuk satu berkas yang sama hanya membingungkan
     * penilaiannya.
     *
     * @param  string|null  $actorUserId  null untuk unggahan lewat tautan publik
     */
    public function link(VendorContract $contract, ?string $actorUserId = null): ?string
    {
        if (! $contract->has_file) {
            return null;
        }
        if ($contract->contract_review_id) {
            return $contract->contract_review_id;
        }

        $reviewId = (string) Str::uuid();

        DB::table('contract_reviews')->insert([
            'id' => $reviewId,
            'org_id' => $contract->org_id,
            'title' => $contract->title,
            'contract_type' => $contract->contract_type,
            // Berkasnya dipakai ulang, bukan disalin: satu berkas, satu sumber
            // kebenaran, dan tidak ada risiko dua salinan berbeda isi.
            'file_path' => $contract->file['path'] ?? null,
            'file_name' => $contract->file['filename'] ?? null,
            'status' => 'pending',
            'risk_score' => 0,
            'created_by' => $actorUserId,
            'source_document_id' => $contract->id,
            'source_module' => self::SOURCE_MODULE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract->forceFill(['contract_review_id' => $reviewId])->save();

        return $reviewId;
    }

    /**
     * Ringkasan hasil telaah untuk sekumpulan kontrak, satu query.
     *
     * Dipakai daftar kontrak supaya skor telaah tampil tanpa N+1 — halaman TPRM
     * menampilkan puluhan kontrak sekaligus.
     *
     * @param  array<int, string>  $reviewIds
     * @return array<string, array<string, mixed>> id telaah → ringkasan
     */
    public function summaries(array $reviewIds): array
    {
        $ids = array_values(array_filter($reviewIds));
        if (empty($ids)) {
            return [];
        }

        return DB::table('contract_reviews')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'status', 'risk_score', 'overall_rating', 'updated_at'])
            ->keyBy('id')
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'risk_score' => (int) $r->risk_score,
                'overall_rating' => $r->overall_rating,
                'reviewed_at' => $r->updated_at,
            ])
            ->all();
    }
}
