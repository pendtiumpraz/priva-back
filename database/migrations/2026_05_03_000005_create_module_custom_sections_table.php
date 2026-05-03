<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Custom Wizard Foundation — Phase 0:
 *
 * Per-tenant custom wizard sections for RoPA / DPIA. Companion table to
 * `module_custom_fields` (Sprint C1). A section groups one or more custom
 * fields under a labelled header that renders inline in the wizard
 * after (or interleaved with) the built-in sections.
 *
 * Schema design ref: CUSTOM_WIZARD_PLAN.md §4.1.
 *
 * Built-in sections occupy sort_order 0–99 (handled in code, not stored).
 * Org-custom sections start at 100 — keeps the merge order deterministic
 * without requiring callers to renumber built-ins.
 *
 * Backfill: any existing distinct (org_id, module, section_key) tuple in
 * `module_custom_fields` that doesn't yet have a matching section row
 * gets one auto-created with a humanised default label so the FE keeps
 * rendering existing custom field groups even before admins touch them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_custom_sections')) {
            try {
                Schema::create('module_custom_sections', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->uuid('org_id')->index();
                    $table->string('module', 32);
                    $table->string('section_key', 64);
                    $table->string('section_label', 191);
                    $table->text('description')->nullable();
                    $table->integer('sort_order')->default(100);
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    $table->softDeletes();

                    $table->unique(['org_id', 'module', 'section_key'], 'module_custom_sections_unique');
                    $table->index(['org_id', 'module', 'is_active'], 'module_custom_sections_org_mod_idx');
                });
            } catch (Throwable $e) {
                if (! $this->alreadyExists($e)) {
                    throw $e;
                }
            }
        }

        // Backfill — only if both tables exist and we have something to backfill.
        if (! Schema::hasTable('module_custom_sections') || ! Schema::hasTable('module_custom_fields')) {
            return;
        }

        try {
            $existing = DB::table('module_custom_fields')
                ->select('org_id', 'module', 'section_key')
                ->whereNull('deleted_at')
                ->groupBy('org_id', 'module', 'section_key')
                ->get();
        } catch (Throwable $e) {
            Log::warning('module_custom_sections backfill skipped: '.$e->getMessage());

            return;
        }

        $now = now();
        foreach ($existing as $row) {
            $orgId = $row->org_id ?? null;
            $module = $row->module ?? null;
            $sectionKey = $row->section_key ?? null;
            if (! $orgId || ! $module || ! $sectionKey) {
                continue;
            }

            $exists = DB::table('module_custom_sections')
                ->where('org_id', $orgId)
                ->where('module', $module)
                ->where('section_key', $sectionKey)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('module_custom_sections')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'module' => $module,
                'section_key' => $sectionKey,
                'section_label' => ucwords(str_replace('_', ' ', $sectionKey)),
                'description' => null,
                'sort_order' => 100,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_custom_sections');
    }

    private function alreadyExists(Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'already exists')
            || str_contains($msg, '1050')   // MySQL
            || str_contains($msg, '42S01')  // MySQL SQLSTATE
            || str_contains($msg, '42P07'); // PostgreSQL SQLSTATE
    }
};
