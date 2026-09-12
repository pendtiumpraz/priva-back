<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\Vendor;
use App\Models\VendorIncident;
use App\Models\VendorRopa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Pihak ketiga mana yang mungkin terlibat pada insiden ini?"
 *
 * Datanya sebenarnya sudah ada sejak lama, hanya tidak pernah ditelusuri: dari
 * insiden → RoPA terdampak → pihak ketiga yang memproses RoPA itu. Fase 1
 * menambahkan PERAN pada tautan RoPA ↔ pihak ketiga, dan Fase 1b menambahkan
 * RoPA yang diisi pihak ketiga sendiri; dua jalur itulah yang dirangkai di sini.
 *
 * Dugaan sengaja DIPISAH dari tautan yang dipastikan (`linked_vendor_ids`):
 * laporan ke regulator tidak boleh memuat tuduhan yang belum diverifikasi
 * penanggung jawab insiden.
 */
class BreachThirdPartyController extends Controller
{
    public function suggested(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $breach = BreachIncident::where('org_id', $orgId)->findOrFail($id);

        $ropaIds = $breach->linked_ropa_ids;
        if (empty($ropaIds) && $breach->linked_ropa_id) {
            $ropaIds = [$breach->linked_ropa_id];
        }
        $ropaIds = array_values(array_filter(is_array($ropaIds) ? $ropaIds : [], 'is_string'));

        $confirmed = array_values(array_filter((array) ($breach->linked_vendor_ids ?? []), 'is_string'));
        $alasanPer = [];

        if ($ropaIds) {
            // Jalur 1 — pihak ketiga yang memproses RoPA terdampak, beserta perannya.
            $rows = DB::table('ropa_vendor')
                ->join('ropas', 'ropas.id', '=', 'ropa_vendor.ropa_id')
                ->where('ropa_vendor.org_id', $orgId)
                ->whereIn('ropa_vendor.ropa_id', $ropaIds)
                ->get(['ropa_vendor.vendor_id', 'ropa_vendor.role', 'ropas.id as ropa_id', 'ropas.registration_number', 'ropas.processing_activity']);

            foreach ($rows as $row) {
                $nama = $row->processing_activity ?: ($row->registration_number ?: 'RoPA');
                $alasanPer[$row->vendor_id][] = [
                    'jenis' => 'ropa',
                    'ropa_id' => $row->ropa_id,
                    'peran' => Vendor::roleLabel($row->role),
                    'teks' => 'Memproses "'.$nama.'" sebagai '.Vendor::roleLabel($row->role).'.',
                ];
            }

            // Jalur 2 — RoPA yang diisi pihak ketiga sendiri dan ditautkan ke
            // RoPA terdampak (Fase 1b).
            $vendorRopas = VendorRopa::where('org_id', $orgId)
                ->whereHas('ropas', fn ($q) => $q->whereIn('ropas.id', $ropaIds))
                ->get(['id', 'vendor_id', 'processing_activity']);

            foreach ($vendorRopas as $vendorRopa) {
                $alasanPer[$vendorRopa->vendor_id][] = [
                    'jenis' => 'vendor_ropa',
                    'vendor_ropa_id' => $vendorRopa->id,
                    'teks' => 'Mencatat sendiri kegiatan "'.($vendorRopa->processing_activity ?: 'tanpa nama').'" yang menyentuh RoPA terdampak.',
                ];
            }
        }

        $vendors = Vendor::whereIn('id', array_keys($alasanPer))
            ->where('org_id', $orgId)
            ->get(['id', 'name', 'country', 'risk_level'])
            ->keyBy('id');

        $suggested = [];
        foreach ($alasanPer as $vendorId => $alasan) {
            $vendor = $vendors->get($vendorId);
            if (! $vendor) {
                continue; // terhapus atau milik tenant lain — jangan pernah bocor
            }
            $suggested[] = [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'country' => $vendor->country,
                'risk_level' => $vendor->risk_level,
                'sudah_ditautkan' => in_array($vendor->id, $confirmed, true),
                'alasan' => $alasan,
            ];
        }

        return response()->json([
            'data' => [
                'dipastikan' => $breach->linked_third_parties,
                'dugaan' => $suggested,
                'ropa_terdampak' => count($ropaIds),
            ],
        ]);
    }

    /**
     * Catat kejadian yang sama di register insiden TPRM dan tautkan keduanya,
     * lalu tandai pihak ketiga itu sebagai terlibat pada insiden ini.
     */
    public function createTprmIncident(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $breach = BreachIncident::where('org_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'vendor_id' => 'required|uuid',
            'severity' => 'nullable|in:low,medium,high,critical',
            'description' => 'nullable|string|max:2000',
        ]);

        $vendor = Vendor::where('org_id', $orgId)->findOrFail($data['vendor_id']);

        $existing = VendorIncident::where('org_id', $orgId)
            ->where('vendor_id', $vendor->id)
            ->where('linked_breach_id', $breach->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Insiden pihak ketiga untuk kejadian ini sudah tercatat.',
                'data' => $existing,
            ]);
        }

        $incident = VendorIncident::create([
            'org_id' => $orgId,
            'vendor_id' => $vendor->id,
            'reporter_user_id' => $request->user()->id,
            'kind' => VendorIncident::KIND_DATA_BREACH,
            'severity' => $data['severity'] ?? $breach->severity ?? 'medium',
            'title' => 'Terkait insiden '.$breach->incident_code,
            'description' => $data['description'] ?? ('Dibuat dari insiden '.$breach->incident_code.'.'),
            'detected_at' => $breach->detected_at ?? now(),
            'status' => VendorIncident::STATUS_OPEN,
            'linked_breach_id' => $breach->id,
        ]);

        // Sekalian tandai sebagai terlibat — kalau kita sudah membuka kasus di
        // sisi pihak ketiga, keterlibatannya bukan dugaan lagi.
        $ids = array_values(array_unique(array_merge($breach->linked_vendor_ids ?? [], [$vendor->id])));
        $breach->forceFill(['linked_vendor_ids' => $ids])->save();

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name ?? 'System',
            'user_role' => $request->user()->role ?? 'user',
            'module' => 'breach.third_party',
            'action' => 'create_tprm_incident',
            'record_id' => $breach->id,
            'section' => 'breach',
            'changes' => ['vendor_incident_id' => $incident->id, 'pihak_ketiga' => $vendor->name],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Insiden pihak ketiga dibuat dan ditautkan.',
            'data' => $incident,
        ], 201);
    }
}
