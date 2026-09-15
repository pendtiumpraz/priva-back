<?php

namespace App\Services\ModuleWrite;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Siapa yang menulis, dan atas nama organisasi mana.
 *
 * Jalur tulis RoPA/DPIA dulunya membaca `$request->user()` langsung, sehingga
 * hanya bisa dipanggil dari permintaan ber-sesi. Padahal penulisnya tidak selalu
 * manusia yang login: kunci API mitra, impor massal, dan agen AI juga menulis
 * record yang sama dan berhak atas efek samping yang sama persis.
 *
 * Menjadikan pelaku sebagai nilai — bukan menggalinya dari request — membuat
 * satu jalur tulis melayani semuanya tanpa menyalin logika. `actorUserId` boleh
 * null: pada jalur berkunci API memang tidak ada pengguna, dan itu keadaan yang
 * sah, bukan kekurangan yang perlu ditambal dengan pengguna palsu.
 */
final class ModuleWriteContext
{
    /**
     * @param  User|null  $actor  pelakunya sebagai objek, bila memang ada orangnya
     */
    public function __construct(
        public readonly string $orgId,
        public readonly ?string $actorUserId = null,
        public readonly ?User $actor = null,
    ) {}

    /**
     * Konteks dari permintaan ber-sesi.
     *
     * Superadmin boleh menulis atas nama organisasi lain — kemampuan yang sudah
     * ada sejak dulu di jalur ini dan sengaja dipertahankan; pengguna biasa
     * selalu terkunci ke organisasinya sendiri, berapa pun yang dikirim klien.
     */
    public static function fromRequest(Request $request): self
    {
        $user = $request->user();
        $orgId = $user->role === 'superadmin' && $request->filled('org_id')
            ? (string) $request->input('org_id')
            : (string) $user->org_id;

        // Objek penggunanya ikut dibawa, bukan cuma id-nya. Penugasan divisi
        // otomatis butuh relasi `department` dan `tenantRole` orang itu; kalau
        // yang tersedia hanya id, tiap penulisan harus memuat ulang ketiganya.
        return new self($orgId, $user->id, $user);
    }

    /** Konteks untuk kunci API mitra — tanpa pengguna, tetap terikat organisasi kuncinya. */
    public static function forApiKey(string $orgId): self
    {
        return new self($orgId, null, null);
    }

    /**
     * Konteks untuk pelaku yang sudah berupa objek — agen AI, pekerjaan antrean,
     * dan jalur lain yang tidak punya request.
     */
    public static function forUser(string $orgId, ?User $actor): self
    {
        return new self($orgId, $actor?->id, $actor);
    }
}
