<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PiiPatternRule;
use App\Services\ContentPiiScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        return response()->json([
            'data' => PiiPatternRule::orderBy('sequence')->orderBy('label')->get(),
            'meta' => [
                'categories' => PiiPatternRule::CATEGORIES,
                'classifications' => PiiPatternRule::CLASSIFICATIONS,
                'builtin_note' => 'Pola bawaan (NIK, NPWP, dan sejenisnya) selalu aktif dan tidak perlu ditambahkan di sini.',
            ],
        ]);
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
