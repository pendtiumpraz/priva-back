<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Ropa;
use App\Models\TenantRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tinjauan dan pemusnahan RoPA yang masa retensinya berakhir
 * (UU PDP Pasal 40–42, PP 33 Pasal 80).
 *
 * Sebelum ini sistem hanya MENGINGATKAN bahwa retensi jatuh tempo, lalu tidak
 * pernah menindaklanjuti. Di sini keputusannya dijadikan tindakan yang tercatat.
 *
 * Dua keputusan rancangan yang disengaja:
 *
 *  1. Pemusnahan MENUNGGU DPO, bukan cron. Yang hilang kalau salah bukan cuma
 *     data, melainkan bukti bahwa kegiatan pemrosesan itu pernah dicatat —
 *     catatan yang wajib ada menurut Pasal 31. Menghapus otomatis akan menaati
 *     satu pasal dengan melanggar yang lain.
 *
 *  2. Yang dimusnahkan adalah ISI data pribadinya, barisnya tetap. Nomor
 *     pendaftaran, jejak persetujuan, dan tanggal pemusnahan justru harus
 *     bertahan sebagai bukti bahwa kewajiban itu ditunaikan. Prinsipnya sama
 *     dengan anonimisasi pengguna.
 */
class RopaRetentionController extends Controller
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_EXTENDED = 'extended';

    public const STATUS_APPROVED = 'approved_for_destruction';

    public const STATUS_DESTROYED = 'destroyed';

    /** GET /ropa/retensi/jatuh-tempo — antrean tinjauan DPO. */
    public function due(Request $request)
    {
        $orgId = $request->user()->org_id;

        $items = Ropa::where('org_id', $orgId)
            ->whereNotNull('retention_due_date')
            ->whereNull('retention_destroyed_at')
            // Draft belum jadi pemrosesan berjalan — sejalan dengan
            // ScanRopaRetention dan SanctionExposureService.
            ->where('status', '!=', 'draft')
            ->whereDate('retention_due_date', '<=', now()->addDays((int) ($request->days ?? 30)))
            ->orderBy('retention_due_date')
            ->get([
                'id', 'registration_number', 'processing_activity', 'status',
                'retention_period', 'retention_due_date',
                'retention_review_status', 'retention_reviewed_at',
            ]);

        return response()->json([
            'data' => $items,
            'ringkasan' => [
                'total' => $items->count(),
                // Yang sudah terlampaui adalah utang kepatuhan berjalan, bukan
                // sekadar "akan datang".
                'terlampaui' => $items->filter(
                    fn ($r) => ($t = $this->jatuhTempo($r)) && $t->isPast()
                )->count(),
                'menunggu_keputusan' => $items->whereNull('retention_review_status')->count(),
            ],
        ]);
    }

    /**
     * POST /ropa/{id}/retensi/perpanjang — DPO memutuskan retensi diperpanjang.
     *
     * Alasan WAJIB: memperpanjang penyimpanan data pribadi adalah keputusan
     * yang harus bisa dipertanggungjawabkan, bukan sekadar menunda.
     */
    public function extend(Request $request, string $id)
    {
        if ($denied = $this->denyIfNotDpo($request)) {
            return $denied;
        }

        $data = $request->validate(['alasan' => 'required|string|min:5|max:2000']);
        $ropa = $this->find($request, $id);

        if ($ropa->retention_destroyed_at) {
            return response()->json(['message' => 'RoPA ini sudah dimusnahkan.'], 422);
        }

        $ropa->forceFill([
            'retention_review_status' => self::STATUS_EXTENDED,
            'retention_reviewed_by' => $request->user()->id,
            'retention_reviewed_at' => now(),
            'retention_review_notes' => $data['alasan'],
        ])->save();

        $this->log($ropa, 'retention_extended', $request, $data['alasan']);

        return response()->json(['message' => 'Masa retensi diperpanjang.', 'data' => $ropa->fresh()]);
    }

    /** POST /ropa/{id}/retensi/setujui-pemusnahan — DPO menyetujui, belum memusnahkan. */
    public function approveDestruction(Request $request, string $id)
    {
        if ($denied = $this->denyIfNotDpo($request)) {
            return $denied;
        }

        $data = $request->validate(['alasan' => 'nullable|string|max:2000']);
        $ropa = $this->find($request, $id);

        if ($ropa->retention_destroyed_at) {
            return response()->json(['message' => 'RoPA ini sudah dimusnahkan.'], 422);
        }
        $jatuhTempo = $this->jatuhTempo($ropa);
        if (! $jatuhTempo || $jatuhTempo->isFuture()) {
            return response()->json([
                'message' => 'Masa retensi belum berakhir. Pemusnahan hanya untuk RoPA yang sudah jatuh tempo.',
                'retention_due_date' => $jatuhTempo?->toDateString(),
            ], 422);
        }

        $ropa->forceFill([
            'retention_review_status' => self::STATUS_APPROVED,
            'retention_reviewed_by' => $request->user()->id,
            'retention_reviewed_at' => now(),
            'retention_review_notes' => $data['alasan'] ?? null,
            // Tanggal yang MENJADI DASAR persetujuan dipatri di sini, saat
            // keputusannya diambil. Hook saving() menurunkan ulang
            // `retention_due_date` dari `wizard_data` pada SETIAP penyimpanan —
            // termasuk penyimpanan ini. Membacanya lagi saat pemusnahan berarti
            // membaca hasil hitung ulang, bukan tanggal yang ditinjau DPO.
            'retention_due_date_at_destruction' => $jatuhTempo,
        ])->save();

        $this->log($ropa, 'retention_destruction_approved', $request, $data['alasan'] ?? 'Disetujui untuk dimusnahkan');

        return response()->json(['message' => 'Pemusnahan disetujui. Jalankan pemusnahan untuk menuntaskan.', 'data' => $ropa->fresh()]);
    }

    /**
     * POST /ropa/{id}/retensi/musnahkan — tuntaskan pemusnahan.
     *
     * Dipisah dari persetujuan supaya tindakan yang tidak bisa dibatalkan
     * memerlukan dua langkah sadar, bukan satu klik.
     */
    public function destroyData(Request $request, string $id)
    {
        if ($denied = $this->denyIfNotDpo($request)) {
            return $denied;
        }

        $ropa = $this->find($request, $id);

        if ($ropa->retention_destroyed_at) {
            return response()->json(['message' => 'RoPA ini sudah dimusnahkan.'], 422);
        }
        if ($ropa->retention_review_status !== self::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Pemusnahan harus disetujui DPO terlebih dahulu.',
                'retention_review_status' => $ropa->retention_review_status,
            ], 422);
        }

        // Utamakan tanggal yang dipatri saat DPO menyetujui: itulah dasar
        // keputusannya. Membaca `retention_due_date` lagi di sini berisiko
        // mengambil hasil hitung ulang hook saving() — dan sesudah wizard
        // dikosongkan nilainya null, sehingga alasan pemusnahannya hilang.
        $jatuhTempo = $ropa->retention_due_date_at_destruction ?? $this->jatuhTempo($ropa);

        $ropa->forceFill([
            // Isi data pribadinya dimusnahkan; identitas catatannya tetap.
            'data_subjects' => null,
            'data_categories' => null,
            'recipients' => null,
            'security_measures' => null,
            'description' => null,
            'wizard_data' => null,
            'retention_review_status' => self::STATUS_DESTROYED,
            'retention_destroyed_at' => now(),
            'retention_due_date_at_destruction' => $jatuhTempo,
            'retention_reviewed_by' => $request->user()->id,
            'retention_reviewed_at' => now(),
        ])->save();

        $this->log($ropa, 'retention_destroyed', $request, 'Data pribadi dimusnahkan; catatan dipertahankan sebagai bukti kepatuhan');

        return response()->json([
            'message' => 'Data pribadi pada RoPA ini telah dimusnahkan. Catatan dipertahankan sebagai bukti kepatuhan.',
            'data' => $ropa->fresh(),
        ]);
    }

    private function find(Request $request, string $id): Ropa
    {
        return Ropa::where('org_id', $request->user()->org_id)->findOrFail($id);
    }

    /**
     * `retention_due_date` TIDAK di-cast di model — seluruh pemakainya
     * memperlakukannya sebagai string, dan memberinya cast 'date' akan
     * mengubah keluaran ekspor CSV/XLSX yang memakai `(string)`. Jadi
     * parsing dilakukan di sini, sama seperti ScanRopaRetention.
     */
    private function jatuhTempo(Ropa $ropa): ?Carbon
    {
        if (! $ropa->retention_due_date) {
            return null;
        }

        try {
            return Carbon::parse($ropa->retention_due_date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Gerbang DPO — cermin RopaApprovalController::isDPO.
     *
     * Nama peran diambil lewat pencarian foreign key eksplisit, bukan properti
     * relasi `$user->tenantRole`: relasi di model User tidak beranotasi tipe,
     * sehingga analisis statis membacanya sebagai properti tak dikenal. Model
     * User dipakai bersama puluhan berkas lain — menambah anotasi di sana
     * mengubah hasil analisis jauh di luar perubahan ini, sementara pencarian
     * eksplisit ini memberi nilai yang persis sama tanpa menyentuhnya.
     */
    private function denyIfNotDpo(Request $request)
    {
        $user = $request->user();
        if (in_array($user->role, ['root', 'superadmin', 'dpo'], true)) {
            return null;
        }

        $namaPeran = $user->tenant_role_id
            ? (string) TenantRole::whereKey($user->tenant_role_id)->value('name')
            : '';
        if (str_contains(strtolower($namaPeran), 'dpo')) {
            return null;
        }

        return response()->json(['error' => 'Hanya DPO yang dapat memutuskan retensi.'], 403);
    }

    private function log(Ropa $ropa, string $action, Request $request, string $detail): void
    {
        try {
            AuditLog::create([
                'module' => 'ropa',
                'record_id' => $ropa->id,
                'action' => $action,
                'user_name' => $request->user()->name ?? 'Unknown',
                'user_role' => $request->user()->role ?? 'user',
                'section' => 'retensi',
                'changes' => [
                    'registration_number' => $ropa->registration_number,
                    'retention_due_date' => $this->jatuhTempo($ropa)?->toDateString(),
                    'detail' => $detail,
                ],
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log retensi RoPA gagal: '.$e->getMessage());
        }
    }
}
