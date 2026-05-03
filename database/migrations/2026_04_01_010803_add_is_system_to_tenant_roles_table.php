<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('name');
        });

        // Migrate predefined roles into tenant_roles and link existing users
        $orgs = DB::table('organizations')->get();

        $presets = [
            'admin' => ['name' => 'Admin', 'desc' => 'Administrator dengan full akses konfigurasi'],
            'dpo' => ['name' => 'DPO', 'desc' => 'Data Protection Officer untuk review dan approval'],
            'maker' => ['name' => 'Maker', 'desc' => 'User operasional yang input data RoPA/DPIA'],
            'viewer' => ['name' => 'Viewer', 'desc' => 'Akses hanya baca (read-only)'],
        ];

        foreach ($orgs as $org) {
            $roleIds = [];
            foreach ($presets as $code => $data) {
                $id = Str::uuid()->toString();
                DB::table('tenant_roles')->insert([
                    'id' => $id,
                    'org_id' => $org->id,
                    'name' => $data['name'],
                    'is_system' => true,
                    'description' => $data['desc'],
                    'permissions' => json_encode(['*']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $roleIds[$code] = $id;
            }

            // Sync existing users
            foreach ($roleIds as $code => $id) {
                DB::table('users')
                    ->where('org_id', $org->id)
                    ->where('role', $code)
                    ->update(['tenant_role_id' => $id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
