<?php

use App\Models\TenantRole;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill izin dua modul subjek baru — `consent_guardian` (anak) dan
 * `consent_accessibility` (disabilitas) — ke role tenant yang sudah ada,
 * mengikuti pola 2026_07_16_000005_backfill_new_module_permissions_to_roles.
 *
 * Sebelum fase ini fitur-fitur itu menumpang izin `consent`; tanpa backfill,
 * semua role yang hari ini bisa membukanya akan kehilangan akses diam-diam.
 *
 *   - role dengan izin ':write' apa pun → diberi read + write pada keduanya
 *   - role read-only                    → diberi read saja
 *   - role wildcard '*' (Admin)         → dilewati (sudah punya semua)
 * Modul yang sudah ada pada suatu role tidak disentuh (APPEND, tidak menimpa).
 */
return new class extends Migration
{
    private array $newModules = ['consent_guardian', 'consent_accessibility'];

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
                $isEditor = (bool) array_filter(
                    $perms,
                    fn ($p) => str_ends_with((string) $p, ':write')
                );

                $changed = false;
                foreach ($this->newModules as $mod) {
                    if (in_array($mod, $existingModules, true)) {
                        continue;
                    }
                    $perms[] = "{$mod}:read";
                    if ($isEditor) {
                        $perms[] = "{$mod}:write";
                    }
                    $changed = true;
                }

                if ($changed) {
                    $role->permissions = array_values(array_unique($perms));
                    $role->save();
                }
            }
        });
    }

    public function down(): void
    {
        // Non-destructive forward migration; nothing to reverse safely.
    }
};
