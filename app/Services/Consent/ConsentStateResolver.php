<?php

namespace App\Services\Consent;

use App\Models\ConsentLog;

/**
 * Menurunkan keadaan consent per (collection point, item) untuk satu subjek.
 *
 * SUMBER DATANYA `consent_logs`, BUKAN `consent_records`
 * ------------------------------------------------------
 * `consent_records` adalah tabel lama dan pada praktiknya kosong (lihat
 * catatan di ConsentCollectionPoint::logs()). Lebih menentukan lagi:
 * kolom `subject_identifier`-nya memakai cast EncryptedString, yaitu
 * AES-256-CBC dengan IV acak — dua penyandian atas teks yang sama
 * menghasilkan sandi yang berbeda. Artinya `where('subject_identifier', $x)`
 * di tabel itu TIDAK AKAN PERNAH cocok. Mesin aturan yang dibangun di atasnya
 * akan diam-diam menjawab "belum pernah" untuk semua orang, dan — karena
 * bawaan kita adalah menahan — terlihat seolah bekerja dengan benar.
 *
 * `consent_logs.user_identifier` dan `.email` tidak disandikan dan keduanya
 * terindeks, jadi di sanalah pencarian subjek memang bisa dilakukan.
 *
 * CARA "YANG TERBARU MENANG"
 * --------------------------
 * Penarikan consent tidak punya baris sendiri: ia berupa penangkapan baru
 * dengan nilai `false`. Jadi keadaan sebuah item = nilai pada baris TERBARU
 * YANG MENYEBUT item itu.
 *
 * Anak kalimat "yang menyebut" itu bukan hiasan. Satu formulir biasanya hanya
 * mengirim item miliknya sendiri; kalau baris terbaru diperlakukan sebagai
 * keadaan lengkap, persetujuan newsletter akan terhapus hanya karena subjek
 * kemudian mengisi formulir checkout yang tak pernah menanyakannya.
 */
class ConsentStateResolver
{
    /** Subjek menyetujui. */
    public const GRANTED = 'granted';

    /** Subjek ditanya, lalu menolak — atau menyetujui lalu menarik kembali. */
    public const NOT_GRANTED = 'not_granted';

    /** Subjek belum pernah ditanya hal ini sama sekali. */
    public const NEVER = 'never';

    public const STATES = [self::GRANTED, self::NOT_GRANTED, self::NEVER];

    /**
     * @param  list<array{0:string,1:string}>  $wanted  pasangan [collectionPointId, consentItemId]
     * @return array<string,string> kunci "cpId|itemId" → salah satu STATES
     */
    public function resolve(string $orgId, string $subject, array $wanted): array
    {
        $states = [];
        foreach ($wanted as [$cp, $item]) {
            $states[$cp.'|'.$item] = self::NEVER;
        }

        if ($states === [] || trim($subject) === '') {
            return $states;
        }

        $points = array_values(array_unique(array_map(fn (array $p) => $p[0], $wanted)));
        $needle = strtolower(trim($subject));

        // org_id disebut EKSPLISIT: scope global `org` milik BelongsToOrg tidak
        // berlaku di konteks antrean/artisan, dan mesin ini justru dipanggil
        // dari sana.
        $logs = ConsentLog::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->whereIn('collection_id', $points)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(user_identifier) = ?', [$needle])
                    ->orWhereRaw('LOWER(email) = ?', [$needle]);
            })
            // Dilipat dari yang paling lama ke yang paling baru, sehingga baris
            // berikutnya menimpa — itulah "yang terbaru menang". `id` sebagai
            // pemecah seri: dua baris berdetik sama memang ambigu, tapi
            // jawabannya harus tetap sama di tiap pemanggilan.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'collection_id', 'consented_items', 'created_at']);

        foreach ($logs as $log) {
            $cp = (string) $log->collection_id;
            $choices = $log->consented_items ?? [];

            if (array_is_list($choices)) {
                // Bentuk lama: daftar id yang disetujui saja. Baris seperti ini
                // hanya berbicara tentang id yang DIMUATNYA — ketiadaan sebuah
                // id di sini bukan penolakan, melainkan kebisuan.
                foreach ($choices as $itemId) {
                    if (is_string($itemId) && isset($states[$cp.'|'.$itemId])) {
                        $states[$cp.'|'.$itemId] = self::GRANTED;
                    }
                }

                continue;
            }

            foreach ($choices as $itemId => $value) {
                $key = $cp.'|'.$itemId;
                if (isset($states[$key])) {
                    $states[$key] = self::granted($value) ? self::GRANTED : self::NOT_GRANTED;
                }
            }
        }

        return $states;
    }

    /**
     * Persis meniru penurunan `purpose_keys` di ConsentLogController::capture —
     * `true`, `'true'`, `1`.
     *
     * Kemiripan ini disengaja dan arahnya penting: kalau di sini lebih longgar
     * daripada di penangkapan, mesin akan mengirim data yang oleh sistem
     * penangkapan sendiri dianggap tidak disetujui. Lebih ketat hanya membuat
     * kita menahan kiriman; lebih longgar membuat kita melanggar.
     */
    private static function granted(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1;
    }
}
