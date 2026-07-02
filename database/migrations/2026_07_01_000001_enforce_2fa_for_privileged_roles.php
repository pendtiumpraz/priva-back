<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforce 2FA (TOTP) untuk role ber-privilege: root, superadmin, admin, dpo.
 *
 * Konteks: 2FA sudah tersedia end-to-end (TwoFactorAuthService + AuthController
 * + halaman /security/2fa). Sebelumnya semua `2fa_required_for_<role>` = false
 * (opt-in). Migration ini menaikkan default kebijakan menjadi WAJIB untuk
 * empat role ber-privilege — sesuai keputusan produk (platform kepatuhan PDP
 * sebaiknya memaksa 2FA pada akun istimewa).
 *
 * Aman terhadap lockout: `isRequiredFor()` untuk user yang BELUM setup tidak
 * mengunci keluar — AuthController membalas `requires_2fa_setup` + token
 * terbatas `2fa:setup`, dan frontend mengarahkan ke `/security/2fa?force=1`
 * untuk enrolment mandiri (forced-enrollment, bukan hard lockout). User tetap
 * bisa masuk setelah scan QR + verifikasi kode (recovery codes sebagai
 * cadangan).
 *
 * MENGHORMATI pilihan admin: hanya baris yang MASIH default (updated_by NULL —
 * belum pernah disentuh manusia lewat UI) yang dinaikkan. Kalau superadmin
 * sudah pernah men-toggle sebuah role (updated_by terisi), pilihannya
 * dibiarkan apa adanya.
 *
 * CATATAN DEPLOY: SettingsServiceProvider men-cache system_settings di file
 * (TTL pendek). Setelah migrate, cache akan rebuild otomatis saat TTL lewat;
 * untuk efek langsung, hapus cache settings (atau simpan ulang lewat UI
 * Security). Test tidak terpengaruh (RefreshDatabase tidak seed system_settings
 * → config default `false`).
 */
return new class extends Migration
{
    private const ENFORCE_KEYS = [
        'security.2fa_required_for_root',
        'security.2fa_required_for_superadmin',
        'security.2fa_required_for_admin',
        'security.2fa_required_for_dpo',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ENFORCE_KEYS as $key) {
            $row = DB::table('system_settings')->where('key', $key)->first();

            if (! $row) {
                // Baris default belum ada (mis. seed 2fa_settings belum jalan) →
                // sisipkan langsung sebagai true.
                DB::table('system_settings')->insert([
                    'key' => $key,
                    'value' => json_encode(true),
                    'is_encrypted' => false,
                    'section' => 'security',
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            // Hanya naikkan baris yang masih default (belum disentuh admin).
            if ($row->updated_by === null) {
                DB::table('system_settings')->where('key', $key)->update([
                    'value' => json_encode(true),
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Revert ke opt-in (false) hanya untuk baris yang masih default.
        DB::table('system_settings')
            ->whereIn('key', self::ENFORCE_KEYS)
            ->whereNull('updated_by')
            ->update([
                'value' => json_encode(false),
                'updated_at' => now(),
            ]);
    }
};
