<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $presets = [
            'Admin' => ['*'],
            'DPO'   => ['dashboard', 'ropa', 'dpia', 'dsr', 'breach', 'simulation', 'consent', 'contract-review', 'data-discovery', 'gap-assessment', 'settings'],
            'Maker' => ['dashboard', 'ropa', 'dpia', 'dsr', 'breach', 'consent', 'contract-review', 'data-discovery', 'gap-assessment'],
            'Viewer'=> ['dashboard', 'ropa', 'dpia'],
        ];

        foreach ($presets as $name => $perms) {
            \Illuminate\Support\Facades\DB::table('tenant_roles')
                ->where('is_system', true)
                ->where('name', $name)
                ->update(['permissions' => json_encode($perms)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to all asterisk if needed, but not strictly necessary
    }
};
