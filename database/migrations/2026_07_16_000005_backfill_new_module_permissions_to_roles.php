<?php

use App\Models\TenantRole;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill permission entries for the newly permission-gated modules
 * (LIA, TIA, Maturity, Cookie, Security) onto existing tenant roles.
 *
 * These menus used to be visible to every non-platform role via the whitelist
 * ceiling. Now that they map to permission modules, a role without the entries
 * would suddenly lose them. To preserve current visibility we APPEND (never
 * overwrite) the entries:
 *   - roles with any ':write' perm  → grant read + write on the new modules
 *   - read-only roles               → grant read only
 *   - wildcard '*' roles (Admin)    → skipped (already have everything)
 * Modules already present on a role are left untouched.
 */
return new class extends Migration
{
    private array $newModules = ['lia', 'tia', 'maturity', 'cookie', 'security'];

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
