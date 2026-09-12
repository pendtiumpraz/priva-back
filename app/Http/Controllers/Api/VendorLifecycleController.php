<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Daur hidup pihak ketiga: onboarding → aktif → offboarding → berakhir.
 *
 * Dua aturan yang ditegakkan di sini, bukan sekadar diingatkan:
 *   1. pihak ketiga tidak boleh berstatus AKTIF tanpa kontrak berlaku yang
 *      berkasnya ada — "yang sudah onboarding wajib punya kontrak";
 *   2. kerja sama tidak boleh ditutup sebelum seluruh langkah offboarding
 *      tuntas (data dikembalikan/dimusnahkan, akses dicabut, dst.).
 */
class VendorLifecycleController extends Controller
{
    public function setStatus(Request $request, string $vendorId)
    {
        $vendor = $this->find($request, $vendorId);
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', Vendor::LIFECYCLES),
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($data['status'] === Vendor::LIFECYCLE_ACTIVE && ! $vendor->activeContract()) {
            return response()->json([
                'message' => 'Pihak ketiga belum bisa diaktifkan: belum ada kontrak berlaku yang berkasnya terunggah.',
                'butuh' => 'kontrak',
            ], 422);
        }

        $before = $vendor->lifecycle_status;
        $patch = ['lifecycle_status' => $data['status']];
        if ($data['status'] === Vendor::LIFECYCLE_ACTIVE && ! $vendor->activated_at) {
            $patch['activated_at'] = now();
        }
        if ($data['status'] === Vendor::LIFECYCLE_OFFBOARDING && empty($vendor->offboarding_checklist)) {
            $patch['offboarding_checklist'] = $this->seedChecklist();
        }
        $vendor->forceFill($patch)->save();

        $this->audit($request, $vendor, 'lifecycle', ['dari' => $before, 'ke' => $data['status'], 'catatan' => $data['notes'] ?? null]);

        return response()->json(['message' => 'Status daur hidup diperbarui.', 'data' => $vendor->fresh()]);
    }

    /** Perbarui centang daftar periksa offboarding. */
    public function updateOffboarding(Request $request, string $vendorId)
    {
        $vendor = $this->find($request, $vendorId);
        $data = $request->validate([
            'steps' => 'required|array|max:50',
            'steps.*.key' => 'required|string|max:64',
            'steps.*.done' => 'required|boolean',
            'steps.*.notes' => 'nullable|string|max:1000',
        ]);

        $existing = collect($vendor->offboarding_checklist ?: $this->seedChecklist())->keyBy('key');
        foreach ($data['steps'] as $step) {
            $row = $existing->get($step['key']);
            if (! $row) {
                continue; // langkah di luar daftar baku diabaikan
            }
            $row['done'] = (bool) $step['done'];
            $row['notes'] = $step['notes'] ?? ($row['notes'] ?? null);
            $row['done_at'] = $row['done'] ? now()->toIso8601String() : null;
            $row['done_by'] = $row['done'] ? $request->user()->id : null;
            $existing[$step['key']] = $row;
        }

        $vendor->forceFill(['offboarding_checklist' => $existing->values()->all()])->save();
        $this->audit($request, $vendor, 'offboarding_update', ['selesai' => $existing->where('done', true)->count()]);

        return response()->json(['message' => 'Daftar periksa diperbarui.', 'data' => $vendor->fresh()]);
    }

    /** Tutup kerja sama — hanya bila seluruh langkah offboarding tuntas. */
    public function complete(Request $request, string $vendorId)
    {
        $vendor = $this->find($request, $vendorId);
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        $checklist = collect($vendor->offboarding_checklist ?: []);
        $belum = $checklist->filter(fn ($s) => empty($s['done']))->pluck('label')->all();

        if ($checklist->isEmpty() || $belum) {
            return response()->json([
                'message' => 'Masih ada langkah offboarding yang belum tuntas.',
                'belum_selesai' => $belum ?: array_column($this->seedChecklist(), 'label'),
            ], 422);
        }

        $vendor->forceFill([
            'lifecycle_status' => Vendor::LIFECYCLE_TERMINATED,
            'termination_reason' => $data['reason'],
            'terminated_at' => now(),
            'offboarded_at' => now(),
        ])->save();

        $this->audit($request, $vendor, 'offboarding_complete', ['alasan' => $data['reason']]);

        return response()->json(['message' => 'Kerja sama ditutup.', 'data' => $vendor->fresh()]);
    }

    /** @return array<int, array<string, mixed>> */
    private function seedChecklist(): array
    {
        return array_map(fn ($s) => $s + ['done' => false, 'done_at' => null, 'done_by' => null, 'notes' => null], Vendor::OFFBOARDING_STEPS);
    }

    private function find(Request $request, string $vendorId): Vendor
    {
        return Vendor::where('org_id', $request->user()->org_id)
            ->visibleTo($request->user())
            ->findOrFail($vendorId);
    }

    /** @param array<string, mixed> $changes */
    private function audit(Request $request, Vendor $vendor, string $action, array $changes): void
    {
        AuditLog::create([
            'org_id' => $vendor->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name ?? 'System',
            'user_role' => $request->user()->role ?? 'user',
            'module' => 'tprm.lifecycle',
            'action' => $action,
            'record_id' => $vendor->id,
            'section' => 'vendor_lifecycle',
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
