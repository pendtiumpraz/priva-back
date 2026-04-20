<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('decryptor_profiles')) return;

        Schema::create('decryptor_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('system_id');
            $table->uuid('org_id');

            $table->string('name', 120);
            // Algorithm of the *target* DB's encryption — what we need to undo.
            // Options: laravel_crypt (AES-256-CBC, Laravel format),
            //          aes_256_gcm (iv||tag||ciphertext, base64),
            //          aes_256_cbc (iv||ciphertext, base64),
            //          sodium_secretbox
            $table->string('algorithm', 40);
            // Wrapped key: tenant's raw key encrypted at rest with the platform
            // master key (env TENANT_KEY_WRAP). Never decrypted on disk.
            $table->text('encrypted_key');
            // Short identifier for UI display — sha256 prefix of raw key. Lets
            // admins tell two profiles apart without exposing the key itself.
            $table->string('key_fingerprint', 32)->nullable();
            // Optional scope: only apply to these (table, column) pairs.
            // When null, profile can be used for any column in the system.
            $table->json('columns')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['system_id', 'is_active']);
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decryptor_profiles');
    }
};
