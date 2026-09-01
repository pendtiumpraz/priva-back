<?php

use App\Models\TenantRole;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill izin modul baru `ppdp` (PP 33/2026 Pasal 142-143) ke role tenant
 * yang sudah ada, mengikuti pola
 * 2026_07_16_000005_backfill_new_module_permissions_to_roles.
 *
 *   - role dengan izin ':write' apa pun → diberi read + write pada ppdp
 *   - role read-only                    → diberi read saja
 *   - role wildcard '*' (Admin)         → dilewati (sudah punya semua)
 * Modul yang sudah ada pada suatu role tidak disentuh (APPEND, tidak menimpa).
 */
return new class extends Migration
{
    private array $newModules = ['ppdp'];

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
        // Forward-only; tidak ada yang perlu dibalik secara aman.
    }
};
