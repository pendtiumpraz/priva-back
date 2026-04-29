<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pool Registry — Storage Pools.
 *
 * Generalization of the existing `app_settings.platform.storage.*` pattern
 * (which stored a single platform-default storage config). Now superadmin
 * can register multiple S3/MinIO/GCS endpoints as named pools and assign
 * tenants to them.
 *
 * Migration of existing platform storage setting → seed 1 default row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();

            // Driver + endpoint
            $table->string('driver', 20);                    // 's3' | 'minio' | 'do_spaces' | 'gcs'
            $table->string('endpoint', 500)->nullable();     // null for AWS-native s3
            $table->string('region', 40)->nullable();
            $table->string('bucket', 255);

            // Credentials (encrypted at rest)
            $table->text('access_key');                      // Crypt::encryptString
            $table->text('secret_key');                      // Crypt::encryptString
            $table->boolean('use_path_style_endpoint')->default(false);

            // Default flag — only one row should be is_default = true
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');

            $table->json('metadata')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('is_default');
        });

        // Seed: migrate existing platform.storage.* app_setting → 1 default pool
        $this->seedFromAppSettings();
    }

    /**
     * If the prior `app_settings.platform.storage.*` keys exist, migrate
     * that single platform default into a `storage_pools` row marked
     * is_default = true. Idempotent.
     */
    private function seedFromAppSettings(): void
    {
        if (!Schema::hasTable('app_settings')) return;

        $driver = DB::table('app_settings')->where('key', 'platform.storage.driver')->value('value');
        $configEnc = DB::table('app_settings')->where('key', 'platform.storage.config')->value('value');

        if (!$driver || !$configEnc || $driver === 'default') return;

        try {
            $config = json_decode(Crypt::decryptString($configEnc), true);
        } catch (\Throwable $e) {
            // Couldn't decrypt — skip seed; superadmin will recreate via UI.
            return;
        }

        if (!is_array($config) || empty($config['bucket']) || empty($config['key']) || empty($config['secret'])) {
            return;
        }

        DB::table('storage_pools')->insert([
            'id'          => (string) Str::uuid(),
            'name'        => 'Migrated Platform Default',
            'description' => 'Auto-seeded from app_settings.platform.storage.* (pre-pool registry).',
            'driver'      => $driver,
            'endpoint'    => $config['endpoint'] ?? null,
            'region'      => $config['region'] ?? null,
            'bucket'      => $config['bucket'],
            'access_key'  => Crypt::encryptString($config['key']),
            'secret_key'  => Crypt::encryptString($config['secret']),
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'is_default'  => true,
            'status'      => 'active',
            'metadata'    => json_encode(['migrated_from' => 'app_settings.platform.storage.*']),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_pools');
    }
};
