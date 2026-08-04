<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ControlLibraryItem;
use App\Models\Dpia;
use App\Services\ControlLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pustaka kontrol/pengamanan yang dapat dipakai ulang lintas DPIA.
 *
 * Menyusul pola dpia/framework: baris dimiliki tenant, default disemai lewat
 * ControlLibraryService::ensureSeeded(), dan `reset` mengembalikannya. Tidak
 * ada baris sistem bersama, sehingga penyuntingan satu tenant tidak pernah
 * menyentuh tenant lain.
 *
 * Endpoint `apply` membuat pustaka ini berguna, bukan sekadar terdaftar:
 * kontrol terpilih dituliskan menjadi item Risk Treatment Plan pada DPIA,
 * memakai bentuk item yang sama persis dengan yang dibuat manual.
 */
class ControlLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;
        $seeded = ControlLibraryService::ensureSeeded($orgId);

        $query = ControlLibraryItem::query();

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($type = $request->input('control_type')) {
            $query->where('control_type', $type);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('category')->orderBy('sequence')->get();

        return response()->json([
            'data' => $items,
            'meta' => [
                'categories' => ControlLibraryItem::CATEGORIES,
                'control_types' => ControlLibraryItem::TYPES,
                'seeded_now' => $seeded,
                // Bauran jenis kontrol ditampilkan karena pustaka yang seluruhnya
                // preventif adalah temuan tersendiri: tanpa kontrol detektif,
                // kegagalan kontrol preventif tidak akan pernah diketahui.
                'mix' => [
                    'preventif' => $items->where('control_type', 'preventif')->count(),
                    'detektif' => $items->where('control_type', 'detektif')->count(),
                    'korektif' => $items->where('control_type', 'korektif')->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'nullable|string|max:32',
            'category' => 'required|in:'.implode(',', ControlLibraryItem::CATEGORIES),
            'title' => 'required|string|max:300',
            'description' => 'nullable|string',
            'control_type' => 'required|in:'.implode(',', ControlLibraryItem::TYPES),
            'implementation_guidance' => 'nullable|string',
            'reference' => 'nullable|string|max:200',
            'default_effectiveness' => 'nullable|integer|min:1|max:3',
            'sequence' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $orgId = $request->user()->org_id;
        $item = ControlLibraryItem::create(array_merge($data, [
            'org_id' => $orgId,
            'sequence' => $data['sequence'] ?? ((int) ControlLibraryItem::max('sequence') + 10),
            'is_active' => $data['is_active'] ?? true,
            'is_seeded' => false,
            'created_by' => $request->user()->id,
        ]));

        AuditLog::log('dpia', $item->id, 'control_library_created', [
            'title' => $item->title,
            'category' => $item->category,
        ], 'control_library');

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = ControlLibraryItem::findOrFail($id);

        $data = $request->validate([
            'code' => 'sometimes|nullable|string|max:32',
            'category' => 'sometimes|in:'.implode(',', ControlLibraryItem::CATEGORIES),
            'title' => 'sometimes|string|max:300',
            'description' => 'sometimes|nullable|string',
            'control_type' => 'sometimes|in:'.implode(',', ControlLibraryItem::TYPES),
            'implementation_guidance' => 'sometimes|nullable|string',
            'reference' => 'sometimes|nullable|string|max:200',
            'default_effectiveness' => 'sometimes|nullable|integer|min:1|max:3',
            'sequence' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $before = $item->only(array_keys($data));
        $item->update($data);

        AuditLog::log('dpia', $item->id, 'control_library_updated', [
            'before' => $before,
            'after' => $data,
        ], 'control_library');

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $item = ControlLibraryItem::findOrFail($id);
        $item->delete();

        AuditLog::log('dpia', $item->id, 'control_library_deleted', [
            'title' => $item->title,
        ], 'control_library');

        return response()->json(['message' => 'Kontrol dihapus dari pustaka.']);
    }

    /** Kembalikan pustaka ke katalog bawaan. */
    public function reset(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $count = DB::transaction(function () use ($orgId) {
            ControlLibraryItem::withoutGlobalScope('org')
                ->withTrashed()
                ->where('org_id', $orgId)
                ->forceDelete();

            return ControlLibraryService::seed($orgId);
        });

        AuditLog::log('dpia', $orgId, 'control_library_reset', ['seeded' => $count], 'control_library');

        return response()->json([
            'message' => "Pustaka kontrol direset ke katalog bawaan ({$count} kontrol).",
        ]);
    }

    /**
     * Terapkan kontrol terpilih ke sebuah DPIA sebagai item Risk Treatment Plan.
     *
     * Bentuk itemnya dibuat sama persis dengan yang dihasilkan penambahan
     * manual di DpiaRtpController — kalau berbeda, papan pemantauan RTP akan
     * memperlakukan keduanya secara berlainan tanpa alasan yang dapat
     * dijelaskan kepada pengguna.
     */
    public function applyToDpia(Request $request, string $dpiaId): JsonResponse
    {
        $data = $request->validate([
            'control_ids' => 'required|array|min:1',
            'control_ids.*' => 'uuid',
            'risk_event' => 'nullable|string|max:500',
            'owner_user_id' => 'nullable|uuid',
            'due_date' => 'nullable|date',
        ]);

        $dpia = Dpia::findOrFail($dpiaId);
        $controls = ControlLibraryItem::whereIn('id', $data['control_ids'])->get();

        if ($controls->isEmpty()) {
            return response()->json(['message' => 'Kontrol tidak ditemukan di pustaka.'], 422);
        }

        $items = $dpia->mitigation_tracking ?? [];
        $existingActions = array_column($items, 'action');
        $now = now()->toIso8601String();
        $added = [];

        foreach ($controls as $control) {
            $action = $control->code
                ? "[{$control->code}] {$control->title}"
                : $control->title;

            // Kontrol yang sudah ada pada DPIA ini dilewati. Tanpa penyaringan
            // ini, menerapkan pustaka dua kali akan melipatgandakan item RTP
            // dan merusak persentase penyelesaiannya.
            if (in_array($action, $existingActions, true)) {
                continue;
            }

            $items[] = [
                'id' => (string) Str::uuid(),
                'risk_event' => $data['risk_event'] ?? 'Risiko umum pelindungan data pribadi',
                'category' => $control->category,
                'treatment_type' => 'reduce',
                'action' => $action,
                'rationale' => trim(($control->description ?? '').' '.($control->reference ? "Rujukan: {$control->reference}." : '')) ?: null,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'priority' => match ((int) $control->default_effectiveness) {
                    3 => 'high',
                    2 => 'medium',
                    default => 'low',
                },
                'due_date' => $data['due_date'] ?? null,
                'status' => 'planned',
                'inherent_likelihood' => null,
                'inherent_impact' => null,
                'residual_likelihood' => null,
                'residual_impact' => null,
                'evidence_files' => [],
                'notes' => '',
                'started_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'source' => 'control_library',
                'control_library_id' => $control->id,
            ];
            $existingActions[] = $action;
            $added[] = $action;
        }

        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::log('dpia', $dpia->id, 'control_library_applied', [
            'applied' => count($added),
            'skipped' => $controls->count() - count($added),
            'controls' => $added,
        ], 'control_library');

        return response()->json([
            'message' => count($added) > 0
                ? count($added).' kontrol ditambahkan ke Risk Treatment Plan.'
                : 'Seluruh kontrol terpilih sudah ada pada DPIA ini.',
            'applied' => count($added),
            'skipped' => $controls->count() - count($added),
            'data' => $dpia->fresh()->mitigation_tracking,
        ]);
    }
}
