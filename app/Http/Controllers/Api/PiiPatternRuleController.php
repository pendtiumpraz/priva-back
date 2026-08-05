<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PiiPatternRule;
use App\Services\ContentPiiScanner;
use App\Services\PiiPatternRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pola pengenal data pribadi yang dapat ditambah organisasi sendiri.
 *
 * Pola divalidasi dua lapis sebelum disimpan: sah sebagai ekspresi reguler,
 * dan benar-benar mencocokkan contoh nilai yang disertakan. Lapis kedua yang
 * paling menolong — pola yang salah tulis tetap sah secara sintaksis, dan
 * kesalahannya baru ketahuan berbulan-bulan kemudian ketika ada yang menyadari
 * kolomnya tidak pernah terdeteksi.
 */
class PiiPatternRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $seeded = PiiPatternRuleService::ensureSeeded($request->user()->org_id);

        $query = PiiPatternRule::query();

        // Keranjang sampah, seragam dengan modul lain.
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return response()->json([
            'data' => $query->orderBy('sequence')->orderBy('label')->get(),
            'meta' => [
                'categories' => PiiPatternRule::CATEGORIES,
                'classifications' => PiiPatternRule::CLASSIFICATIONS,
                'seeded' => $seeded,
                'trashed_count' => PiiPatternRule::onlyTrashed()->count(),
                'builtin_note' => 'Pencocokan berdasarkan NAMA kolom (nik, no_ktp, npwp, dan sejenisnya) sudah tertanam di kode dan selalu aktif. Pola di halaman ini bekerja pada ISI datanya — itulah yang menangkap kolom bernama samar seperti "field_123" yang ternyata memuat NIK.',
            ],
        ]);
    }

    /**
     * Kembalikan katalog bawaan.
     *
     * Menghapus seluruh pola tenant lalu menyemai ulang. Dipakai ketika
     * penyuntingan terlanjur berantakan dan lebih murah mengulang daripada
     * membetulkan satu per satu.
     */
    public function reset(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $count = DB::transaction(function () use ($orgId) {
            PiiPatternRule::withTrashed()->forceDelete();

            return PiiPatternRuleService::seed($orgId);
        });

        ContentPiiScanner::flushCustomPatterns();

        // Reset tidak menyangkut satu baris, melainkan seluruh katalog milik
        // organisasi — jadi org yang dicatat sebagai record-nya.
        AuditLog::log('pii_patterns', $orgId, 'reset', ['seeded' => $count], 'manual');

        return response()->json([
            'message' => "Katalog dikembalikan ke {$count} pola bawaan.",
            'seeded' => $count,
        ]);
    }

    /** Kembalikan pola yang sudah dihapus. */
    public function restore(Request $request, string $id): JsonResponse
    {
        $rule = PiiPatternRule::onlyTrashed()->findOrFail($id);
        $rule->restore();
        ContentPiiScanner::flushCustomPatterns();

        return response()->json(['message' => 'Pola dikembalikan.', 'data' => $rule]);
    }

    /** Hapus permanen — hanya untuk pola yang sudah ada di keranjang sampah. */
    public function forceDelete(Request $request, string $id): JsonResponse
    {
        $rule = PiiPatternRule::onlyTrashed()->findOrFail($id);
        $rule->forceDelete();
        ContentPiiScanner::flushCustomPatterns();

        return response()->json(['message' => 'Pola dihapus permanen.']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($err = $this->patternProblem($data)) {
            return $err;
        }

        $rule = PiiPatternRule::create(array_merge($data, [
            'org_id' => $request->user()->org_id,
            'created_by' => $request->user()->id,
        ]));
        ContentPiiScanner::flushCustomPatterns();

        AuditLog::log('data-discovery', $rule->id, 'pii_pattern_created', [
            'key' => $rule->key,
            'label' => $rule->label,
        ], 'pii_pattern');

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rule = PiiPatternRule::findOrFail($id);
        $data = $this->validated($request, $rule->id);

        $merged = array_merge($rule->only(['pattern', 'sample_value']), $data);
        if ($err = $this->patternProblem($merged)) {
            return $err;
        }

        $rule->update($data);
        ContentPiiScanner::flushCustomPatterns();

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $rule = PiiPatternRule::findOrFail($id);
        $rule->delete();
        ContentPiiScanner::flushCustomPatterns();

        return response()->json(['message' => 'Pola dihapus.']);
    }

    /**
     * Uji pola terhadap sekumpulan nilai tanpa menyimpannya.
     *
     * Disediakan supaya penyusun pola dapat memastikan cakupannya sebelum
     * dipakai memindai basis data produksi — pola yang terlalu longgar akan
     * menandai seluruh kolom sebagai data pribadi dan membuat hasil
     * pemindaian tidak berguna.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pattern' => 'required|string|max:500',
            'values' => 'required|array|min:1|max:50',
            'values.*' => 'nullable|string|max:500',
        ]);

        $check = PiiPatternRule::validatePattern($data['pattern']);
        if (! $check['valid']) {
            return response()->json(['message' => $check['error']], 422);
        }

        $matches = [];
        foreach ($data['values'] as $value) {
            $matches[] = [
                'value' => $value,
                'matched' => (bool) preg_match($data['pattern'], trim((string) $value)),
            ];
        }
        $hit = count(array_filter($matches, fn ($m) => $m['matched']));

        return response()->json([
            'data' => [
                'results' => $matches,
                'matched' => $hit,
                'total' => count($matches),
                'warning' => $hit === count($matches) && count($matches) > 2
                    ? 'Pola mencocokkan SELURUH nilai uji. Pastikan tidak terlalu longgar — pola yang mencocokkan apa pun akan menandai semua kolom sebagai data pribadi.'
                    : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?string $ignoreId = null): array
    {
        $unique = 'unique:pii_pattern_rules,key,'.($ignoreId ?? 'NULL').',id,org_id,'.$request->user()->org_id;

        return $request->validate([
            'key' => ($ignoreId ? 'sometimes|' : '').'required|string|max:64|regex:/^[a-z0-9_]+$/|'.$unique,
            'label' => ($ignoreId ? 'sometimes|' : '').'required|string|max:150',
            'pattern' => ($ignoreId ? 'sometimes|' : '').'required|string|max:500',
            'pdp_category' => 'sometimes|in:'.implode(',', PiiPatternRule::CATEGORIES),
            'classification' => 'sometimes|in:'.implode(',', PiiPatternRule::CLASSIFICATIONS),
            'encryption_required' => 'sometimes|boolean',
            'weight' => 'sometimes|numeric|min:0|max:1',
            'reason' => 'sometimes|nullable|string|max:300',
            'sample_value' => 'sometimes|nullable|string|max:200',
            'is_active' => 'sometimes|boolean',
            'sequence' => 'sometimes|integer',
        ]);
    }

    private function patternProblem(array $data): ?JsonResponse
    {
        $pattern = $data['pattern'] ?? null;
        if (! $pattern) {
            return null;
        }

        $check = PiiPatternRule::validatePattern($pattern);
        if (! $check['valid']) {
            return response()->json(['message' => $check['error']], 422);
        }

        $sample = $data['sample_value'] ?? null;
        if ($sample !== null && $sample !== '' && ! preg_match($pattern, trim((string) $sample))) {
            return response()->json([
                'message' => 'Pola tidak mencocokkan contoh nilai yang Anda berikan. '
                    .'Perbaiki polanya atau contohnya — pola yang tidak mencocokkan contohnya sendiri hampir selalu salah tulis.',
            ], 422);
        }

        return null;
    }
}
