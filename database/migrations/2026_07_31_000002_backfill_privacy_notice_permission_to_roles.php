<?php

use App\Models\TenantRole;
use Illuminate\Database\Migrations\Migration;

/**
 * Daftarkan modul `privacy_notice` pada tenant role yang sudah ada.
 *
 * Tanpa ini, modul baru tidak terlihat oleh siapa pun kecuali peran ber-wildcard
 * — pengguna yang seharusnya berwenang justru menerima 403 dan menyimpulkan
 * fiturnya rusak.
 *
 * Aturannya menyusul migrasi backfill terdahulu (2026_07_16_000005):
 *   - peran dengan wildcard '*'            → dilewati, sudah mencakup semuanya
 *   - peran yang punya izin ':write' apa pun → diberi read + write
 *   - peran baca-saja                      → diberi read saja
 * Izin ditambahkan, tidak pernah menimpa.
 */
return new class extends Migration
{
    private string $module = 'privacy_notice';

    public function up(): void
    {
        TenantRole::query()->chunkById(200, function ($roles) {
            foreach ($roles as $role) {
                $perms = is_array($role->permissions) ? $role->permissions : [];
                if (in_array('*', $perms, true)) {
                    continue;
                }

                $existingModules = array_map(
                    fn ($p) => explode(':', (string) $p)[0],
                    $perms
                );
                if (in_array($this->module, $existingModules, true)) {
                    continue;
                }

                $isEditor = (bool) array_filter(
                    $perms,
                    fn ($p) => str_ends_with((string) $p, ':write')
                );

                $perms[] = $this->module.':read';
                if ($isEditor) {
                    $perms[] = $this->module.':write';
                }

                $role->permissions = array_values($perms);
                $role->save();
            }
        });
    }

    public function down(): void
    {
        TenantRole::query()->chunkById(200, function ($roles) {
            foreach ($roles as $role) {
                $perms = is_array($role->permissions) ? $role->permissions : [];
                $filtered = array_values(array_filter(
                    $perms,
                    fn ($p) => explode(':', (string) $p)[0] !== $this->module
                ));
                if ($filtered !== $perms) {
                    $role->permissions = $filtered;
                    $role->save();
                }
            }
        });
    }
};
