<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module ROPA linkage — many-to-many.
 *
 * Pattern: 1 information_system bisa ada di banyak ROPA (1 DB serves multiple
 * processing activities). 1 consent collection bisa terkait banyak ROPA
 * (cookie banner cover analytics + marketing + transfer).
 *
 * DSR doesn't need its own pivot — it links via scope's information_system → ropas.
 *
 * Backfill (best-effort): existing ConsentCollectionPoint.settings.linked_ropa_id
 * di-promote ke pivot.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('information_system_ropa')) {
            try {
                Schema::create('information_system_ropa', function (Blueprint $t) {
                    $t->uuid('information_system_id');
                    $t->uuid('ropa_id');
                    $t->uuid('org_id');
                    $t->text('notes')->nullable();
                    $t->timestamps();

                    $t->primary(['information_system_id', 'ropa_id'], 'is_ropa_pk');
                    $t->index('ropa_id', 'is_ropa_ropa_idx');
                    $t->index(['org_id', 'information_system_id'], 'is_ropa_org_is_idx');
                    $t->foreign('information_system_id', 'is_ropa_is_fk')
                      ->references('id')->on('information_systems')->cascadeOnDelete();
                    $t->foreign('ropa_id', 'is_ropa_ropa_fk')
                      ->references('id')->on('ropas')->cascadeOnDelete();
                    $t->foreign('org_id', 'is_ropa_org_fk')
                      ->references('id')->on('organizations')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'already exists')) throw $e;
            }
        }

        if (!Schema::hasTable('consent_collection_ropa')) {
            try {
                Schema::create('consent_collection_ropa', function (Blueprint $t) {
                    $t->uuid('collection_point_id');
                    $t->uuid('ropa_id');
                    $t->uuid('org_id');
                    $t->text('notes')->nullable();
                    $t->timestamps();

                    $t->primary(['collection_point_id', 'ropa_id'], 'cp_ropa_pk');
                    $t->index('ropa_id', 'cp_ropa_ropa_idx');
                    $t->index(['org_id', 'collection_point_id'], 'cp_ropa_org_cp_idx');
                    $t->foreign('collection_point_id', 'cp_ropa_cp_fk')
                      ->references('id')->on('consent_collection_points')->cascadeOnDelete();
                    $t->foreign('ropa_id', 'cp_ropa_ropa_fk')
                      ->references('id')->on('ropas')->cascadeOnDelete();
                    $t->foreign('org_id', 'cp_ropa_org_fk')
                      ->references('id')->on('organizations')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'already exists')) throw $e;
            }
        }

        // Backfill: existing ConsentCollectionPoint.settings.linked_ropa_id → pivot
        if (Schema::hasTable('consent_collection_points') && Schema::hasTable('consent_collection_ropa')) {
            try {
                DB::table('consent_collection_points')
                    ->whereNotNull('settings')
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) {
                        foreach ($rows as $cp) {
                            $settings = is_string($cp->settings) ? json_decode($cp->settings, true) : (array) $cp->settings;
                            $rid = $settings['linked_ropa_id'] ?? null;
                            if (!$rid) continue;
                            // Verify ropa exists + same org
                            $exists = DB::table('ropas')->where('id', $rid)->where('org_id', $cp->org_id)->exists();
                            if (!$exists) continue;
                            DB::table('consent_collection_ropa')->updateOrInsert([
                                'collection_point_id' => $cp->id,
                                'ropa_id' => $rid,
                            ], [
                                'org_id' => $cp->org_id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    });
            } catch (\Throwable $e) {
                // best-effort backfill — don't block migration
                \Log::warning('Backfill consent_collection_ropa failed: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('information_system_ropa');
        Schema::dropIfExists('consent_collection_ropa');
    }
};
