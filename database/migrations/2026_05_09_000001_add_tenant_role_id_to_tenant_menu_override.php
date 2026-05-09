<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah tenant_role_id ke tenant_menu_override untuk dukung custom role
 * (manager, supervisor, dll) — bukan cuma legacy role string (admin/dpo/dll).
 *
 * Kompatibilitas: kolom `role` legacy tetap dipertahankan (nullable). Resolver
 * cek `tenant_role_id` dulu, fallback ke `role` kalau null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_menu_override', function (Blueprint $table) {
            $table->uuid('tenant_role_id')->nullable()->after('role');
            $table->foreign('tenant_role_id')->references('id')->on('tenant_roles')->cascadeOnDelete();
            $table->index(['org_id', 'tenant_role_id'], 'tmo_org_trole_idx');
        });

        // role kolom jadi nullable — entry baru pakai tenant_role_id, legacy
        // entry tetap pakai role string.
        Schema::table('tenant_menu_override', function (Blueprint $table) {
            $table->string('role', 32)->nullable()->change();
        });

        // Drop unique constraint lama (org_id + menu_id + role) karena gak
        // include tenant_role_id. Bikin partial-style: 2 unique constraint
        // berdasarkan source (legacy vs role-based) untuk DB yg mendukung.
        // Untuk Postgres/MySQL kita pakai unique constraint biasa pada
        // kombinasi yang relevan.
        try {
            Schema::table('tenant_menu_override', function (Blueprint $table) {
                $table->dropUnique('tmo_org_menu_role_unique');
            });
        } catch (Throwable $e) {
            // index might not exist on some envs, tolerate
        }

        Schema::table('tenant_menu_override', function (Blueprint $table) {
            $table->unique(['org_id', 'menu_id', 'tenant_role_id'], 'tmo_org_menu_trole_unique');
            $table->unique(['org_id', 'menu_id', 'role'], 'tmo_org_menu_legacy_role_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_menu_override', function (Blueprint $table) {
            try {
                $table->dropUnique('tmo_org_menu_trole_unique');
            } catch (Throwable $e) {
            }
            try {
                $table->dropUnique('tmo_org_menu_legacy_role_unique');
            } catch (Throwable $e) {
            }
            try {
                $table->dropForeign(['tenant_role_id']);
            } catch (Throwable $e) {
            }
            try {
                $table->dropIndex('tmo_org_trole_idx');
            } catch (Throwable $e) {
            }
            $table->dropColumn('tenant_role_id');
            $table->unique(['org_id', 'menu_id', 'role'], 'tmo_org_menu_role_unique');
        });
    }
};
