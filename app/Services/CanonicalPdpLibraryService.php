<?php

namespace App\Services;

use App\Models\QuestionLibrary;
use App\Models\QuestionLibrarySegment;
use App\Models\VendorQuestionnaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolusi library UU PDP KANONIK.
 *
 * Rework TPRM (2026-07): konsep "Default (library_id=null)" DIHAPUS. UU PDP
 * SELALU diwakili oleh SATU QuestionLibrary konkret ber-category
 * `pdp_compliance` (template platform, org_id=null, slug pdp_compliance_v2_2026)
 * — 56 pertanyaan UU 27/2022. Ini menutup bug "1 vendor terhitung 2 asesmen UU
 * PDP" (null-Default + library UU PDP).
 *
 * resolveId($orgId):
 *   - Kalau org punya FORK COW aktif dari template kanonik (source=forked,
 *     cloned_from = template id) → pakai id fork itu, konsisten dengan picker
 *     Bank Pertanyaan yang men-shadow template dengan fork org.
 *   - Selain itu → id template platform kanonik.
 *
 * Template kanonik dibuat oleh QuestionLibraryBackfillSeeder saat migrate+seed.
 * Bila belum ada (env lama / test yang hanya seed pertanyaan), service ini
 * meng-create-nya on-demand + me-link pertanyaan default PDP v2_2026 supaya
 * jalur load pertanyaan by-library_id tetap mengembalikan set yang sama.
 */
class CanonicalPdpLibraryService
{
    public const SLUG = 'pdp_compliance_v2_2026';

    public const VERSION = 'v2_2026';

    /**
     * Peta section → segment (mirror QuestionLibraryBackfillSeeder::SEGMENT_MAP)
     * dipakai saat provisioning on-demand.
     */
    private const SEGMENT_MAP = [
        'governance' => ['name' => 'Tata Kelola', 'code' => 'GOV', 'order' => 0, 'weight_pct' => 25],
        'operation' => ['name' => 'Operasi', 'code' => 'OPS', 'order' => 1, 'weight_pct' => 25],
        'people' => ['name' => 'SDM', 'code' => 'PPL', 'order' => 2, 'weight_pct' => 20],
        'technology' => ['name' => 'Teknologi', 'code' => 'TEK', 'order' => 3, 'weight_pct' => 30],
    ];

    /**
     * Id library UU PDP kanonik untuk org tertentu (fork-aware).
     */
    public function resolveId(?string $orgId): string
    {
        $template = $this->resolveTemplate();

        if ($orgId) {
            $fork = QuestionLibrary::query()
                ->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('source', QuestionLibrary::SOURCE_FORKED)
                ->where('cloned_from_library_id', $template->id)
                ->where('is_active', true)
                ->orderByDesc('created_at')
                ->first(['id']);
            if ($fork) {
                return $fork->id;
            }
        }

        return $template->id;
    }

    /**
     * Kumpulan id library yang dihitung sebagai "UU PDP" (category
     * pdp_compliance) — template kanonik + fork/clone/snapshot org. Dipakai
     * untuk menentukan asesmen headline vendor.
     *
     * @return array<int,string>
     */
    public function pdpLibraryIds(): array
    {
        return QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('category', VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE)
            ->pluck('id')
            ->all();
    }

    /**
     * Template platform kanonik (org_id null). Di-provision on-demand bila
     * belum ada.
     */
    public function resolveTemplate(): QuestionLibrary
    {
        $lib = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->where('slug', self::SLUG)
            ->first();
        if ($lib) {
            return $lib;
        }

        // Fallback: template platform pdp_compliance apa pun (slug beda).
        $lib = QuestionLibrary::query()
            ->withoutGlobalScope('org')
            ->whereNull('org_id')
            ->where('category', VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE)
            ->where('version', self::VERSION)
            ->orderByDesc('is_locked')
            ->first();
        if ($lib) {
            return $lib;
        }

        return DB::transaction(fn () => $this->provisionCanonical());
    }

    /**
     * Create template kanonik + segment + link pertanyaan default PDP v2_2026.
     */
    private function provisionCanonical(): QuestionLibrary
    {
        $library = QuestionLibrary::create([
            'id' => (string) Str::uuid(),
            'org_id' => null,
            'name' => 'Kepatuhan PDP UU 27/2022 — Pihak Ketiga',
            'slug' => self::SLUG,
            'description' => 'Set pertanyaan UU PDP No. 27 Tahun 2022 untuk asesmen pihak ketiga (default wajib).',
            'category' => VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE,
            'version' => self::VERSION,
            'source' => QuestionLibrary::SOURCE_SEEDED,
            'is_active' => true,
            'is_locked' => true,
            'tags' => ['pdp', 'uu_27_2022', 'wajib', 'default'],
        ]);

        $segmentBySection = [];
        foreach (self::SEGMENT_MAP as $sectionKey => $meta) {
            $segmentBySection[$sectionKey] = QuestionLibrarySegment::create([
                'id' => (string) Str::uuid(),
                'library_id' => $library->id,
                'name' => $meta['name'],
                'code' => $meta['code'],
                'order_index' => $meta['order'],
                'weight_pct' => $meta['weight_pct'],
            ]);
        }

        // Link pertanyaan default platform (org_id null, pdp_compliance,
        // v2_2026) yang belum punya library_id ke template kanonik.
        $defaults = VendorQuestionnaire::query()
            ->withoutGlobalScope('org')
            ->whereNull('org_id')
            ->whereNull('library_id')
            ->where('category', VendorQuestionnaire::CATEGORY_PDP_COMPLIANCE)
            ->where('version', self::VERSION)
            ->get();

        foreach ($defaults as $q) {
            $seg = $segmentBySection[$q->section] ?? null;
            $q->forceFill([
                'library_id' => $library->id,
                'library_segment_id' => $seg?->id,
            ])->save();
        }

        $library->refreshCounters();
        foreach ($segmentBySection as $seg) {
            $seg->refreshCounter();
        }

        return $library;
    }
}
