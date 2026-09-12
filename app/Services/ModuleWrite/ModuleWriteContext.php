<?php

namespace App\Services\ModuleWrite;

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
    public function __construct(
        public readonly string $orgId,
        public readonly ?string $actorUserId = null,
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

        return new self($orgId, $user->id);
    }

    /** Konteks untuk kunci API mitra — tanpa pengguna, tetap terikat organisasi kuncinya. */
    public static function forApiKey(string $orgId): self
    {
        return new self($orgId, null);
    }
}
