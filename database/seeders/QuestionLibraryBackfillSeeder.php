<?php

namespace Database\Seeders;

use App\Models\QuestionLibrary;
use App\Models\QuestionLibrarySegment;
use App\Models\VendorQuestionnaire;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TPRM Phase 1 — Backfill seeder.
 *
 * Convert pertanyaan PDP 56 yang sudah ada di `vendor_questionnaires`
 * (category=pdp_compliance, version=v2_2026, org_id=NULL) menjadi:
 *   1. Library "PDP Compliance UU 27/2022 v2_2026" (global template,
 *      org_id=NULL, is_locked=true)
 *   2. 4 segments dari distinct section: governance/operation/people/
 *      technology → Tata Kelola/Operasi/SDM/Teknologi
 *   3. Link semua 56 pertanyaan ke library + segment-nya
 *
 * Idempotent — kalau library sudah ada (run kedua kali), seeder skip.
 *
 * Run:
 *   php artisan db:seed --class=QuestionLibraryBackfillSeeder
 */
class QuestionLibraryBackfillSeeder extends Seeder
{
    private const LIBRARY_SLUG = 'pdp_compliance_v2_2026';

    private const SEGMENT_MAP = [
        'governance' => ['name' => 'Tata Kelola',  'code' => 'GOV', 'order' => 0, 'weight_pct' => 25],
        'operation'  => ['name' => 'Operasi',      'code' => 'OPS', 'order' => 1, 'weight_pct' => 25],
        'people'     => ['name' => 'SDM',          'code' => 'PPL', 'order' => 2, 'weight_pct' => 20],
        'technology' => ['name' => 'Teknologi',    'code' => 'TEK', 'order' => 3, 'weight_pct' => 30],
    ];

    public function run(): void
    {
        $existing = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('slug', self::LIBRARY_SLUG)
            ->first();

        if ($existing) {
            $slug = self::LIBRARY_SLUG;
            $this->command->info("[skip] Library '{$slug}' sudah ada (id={$existing->id})");

            return;
        }

        DB::transaction(function () {
            $library = QuestionLibrary::create([
                'id' => (string) Str::uuid(),
                'org_id' => null,
                'name' => 'Kepatuhan PDP UU 27/2022 — Pihak Ketiga',
                'slug' => self::LIBRARY_SLUG,
                'description' => '56 pertanyaan komprehensif untuk asesmen pihak ketiga sesuai UU PDP No. 27 Tahun 2022. Mencakup tata kelola, operasi, SDM, dan teknologi. Berlaku lintas bidang vendor (IT, Legal, HR, Procurement, dst).',
                'category' => 'pdp_compliance',
                'version' => 'v2_2026',
                'source' => QuestionLibrary::SOURCE_SEEDED,
                'is_active' => true,
                'is_locked' => true,
                'tags' => ['pdp', 'uu_27_2022', 'wajib', 'default'],
            ]);

            $segmentByCode = [];
            foreach (self::SEGMENT_MAP as $sectionKey => $meta) {
                $segment = QuestionLibrarySegment::create([
                    'id' => (string) Str::uuid(),
                    'library_id' => $library->id,
                    'name' => $meta['name'],
                    'code' => $meta['code'],
                    'description' => null,
                    'order_index' => $meta['order'],
                    'weight_pct' => $meta['weight_pct'],
                ]);
                $segmentByCode[$sectionKey] = $segment;
            }

            // Backfill semua row PDP v2_2026 yang org_id NULL (default platform)
            // dengan library_id + library_segment_id. Per-tenant override
            // (org_id != NULL, parent_id set) di-link mengikuti parent-nya.
            $defaults = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->whereNull('org_id')
                ->where('category', 'pdp_compliance')
                ->where('version', 'v2_2026')
                ->get();

            $updated = 0;
            foreach ($defaults as $q) {
                $sectionKey = $q->section;
                $segment = $segmentByCode[$sectionKey] ?? null;
                if (! $segment) {
                    $this->command->warn("[warn] Question {$q->question_code} section '{$sectionKey}' tidak ada di map — skip");

                    continue;
                }
                $q->library_id = $library->id;
                $q->library_segment_id = $segment->id;
                $q->save();
                $updated++;
            }

            // Override per-tenant: parent_id menunjuk ke default → ikuti
            // library_id + library_segment_id dari parent supaya tenant
            // override tetap kelihatan dalam library yang sama.
            $overrides = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->whereNotNull('org_id')
                ->whereNotNull('parent_id')
                ->whereNull('library_id')
                ->get();

            foreach ($overrides as $ov) {
                $parent = VendorQuestionnaire::query()
                    ->withoutGlobalScope('org')
                    ->where('id', $ov->parent_id)
                    ->first();
                if (! $parent || ! $parent->library_id) {
                    continue;
                }
                $ov->library_id = $parent->library_id;
                $ov->library_segment_id = $parent->library_segment_id;
                $ov->save();
                $updated++;
            }

            $library->refreshCounters();
            foreach ($segmentByCode as $seg) {
                $seg->refreshCounter();
            }

            $this->command->info("[ok] Library '{$library->name}' dibuat. {$updated} pertanyaan di-link.");
        });
    }
}
