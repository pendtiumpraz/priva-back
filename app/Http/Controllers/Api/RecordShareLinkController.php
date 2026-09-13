<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dpia;
use App\Models\RecordShareLink;
use App\Models\Ropa;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Penerbitan tautan dokumen ke lembaga (terautentikasi, oleh DPO).
 *
 * Modul diikat dari rute, bukan dari badan permintaan, supaya gerbang izin
 * (`permission:ropa,write` / `permission:dpia,write`) benar-benar menjaga modul
 * yang bersangkutan.
 *
 * Token dan kata sandi HANYA dikembalikan sekali, saat diterbitkan atau
 * dirotasi. Tidak ada endpoint untuk melihatnya lagi: kata sandinya disimpan
 * sebagai hash, dan menyediakan cara membacanya kembali sama saja dengan
 * menyimpan dokumen rahasia dalam bentuk terbuka. Kalau DPO kehilangan
 * catatannya, jalan keluarnya rotasi.
 */
class RecordShareLinkController extends Controller
{
    private const DEFAULT_MAX_VIEWS = 5;

    private const DEFAULT_TTL_DAYS = 30;

    public function index(Request $request, string $module)
    {
        $query = RecordShareLink::where('org_id', $request->user()->org_id)
            ->where('module', $module);

        if ($request->record_id) {
            $query->where('record_id', $request->record_id);
        }

        return response()->json([
            'data' => $query->orderBy('created_at', 'desc')->get()->map(fn ($l) => $this->present($l)),
        ]);
    }

    public function store(Request $request, string $module)
    {
        $data = $request->validate([
            'record_id' => 'required|uuid',
            'recipient_label' => 'nullable|string|max:160',
            // Kata sandi boleh ditentukan DPO (mis. sudah disepakati lewat kanal
            // lain); kalau tidak, sistem yang membuatkan.
            'password' => 'nullable|string|min:8|max:200',
            'max_views' => 'nullable|integer|min:1|max:100',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        $orgId = $request->user()->org_id;
        $record = $this->recordModel($module)->newQuery()
            ->where('org_id', $orgId)
            ->findOrFail($data['record_id']);

        $token = RecordShareLink::generateUniqueToken();
        $password = $data['password'] ?? RecordShareLink::generatePassword();

        $link = RecordShareLink::create([
            'org_id' => $orgId,
            'module' => $module,
            'record_id' => $record->id,
            'token' => $token,
            'password_hash' => Hash::make($password),
            'recipient_label' => $data['recipient_label'] ?? null,
            'max_views' => (int) ($data['max_views'] ?? self::DEFAULT_MAX_VIEWS),
            'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? self::DEFAULT_TTL_DAYS)),
            'created_by' => $request->user()->id,
        ]);

        AuditLog::log($module, $record->id, 'share_link.issued', [
            'link_id' => $link->id,
            'recipient' => $link->recipient_label,
            'max_views' => $link->max_views,
            'expires_at' => optional($link->expires_at)->toIso8601String(),
        ], 'share');

        return response()->json([
            'message' => 'Tautan dokumen dibuat. Kirimkan URL dan kata sandinya lewat kanal terpisah.',
            'data' => $this->present($link, $token, $password),
        ], 201);
    }

    /**
     * Rotasi: token DAN kata sandi baru, jatah kunjungan dihitung ulang.
     *
     * URUTAN ARGUMEN PENTING: parameter yang datang dari URI (`{id}`) harus
     * mendahului yang disuntik `->defaults('module', ...)` di rute. Dengan
     * urutan terbalik, nilai default masuk ke argumen yang salah dan pencarian
     * berubah menjadi `where('module', <uuid>)` — selalu 404. Jangan dirapikan
     * menjadi ($module, $id).
     */
    public function rotate(Request $request, string $id, string $module)
    {
        $link = $this->find($request, $module, $id);
        $token = RecordShareLink::generateUniqueToken();
        $password = RecordShareLink::generatePassword();

        $link->update([
            'token' => $token,
            'password_hash' => Hash::make($password),
            'view_count' => 0,
            'revoked_at' => null,
            'revoked_reason' => null,
        ]);

        AuditLog::log($module, $link->record_id, 'share_link.rotated', [
            'link_id' => $link->id,
            'warning' => 'tautan dan kata sandi lama langsung tidak berlaku',
        ], 'share');

        return response()->json([
            'message' => 'Tautan dan kata sandi baru dibuat. Tautan lama langsung tidak berlaku.',
            'data' => $this->present($link->fresh(), $token, $password),
        ]);
    }

    /** Lihat catatan urutan argumen pada rotate(). */
    public function revoke(Request $request, string $id, string $module)
    {
        $link = $this->find($request, $module, $id);
        $link->update([
            'revoked_at' => now(),
            'revoked_reason' => RecordShareLink::REASON_MANUAL,
        ]);

        AuditLog::log($module, $link->record_id, 'share_link.revoked', [
            'link_id' => $link->id,
            'recipient' => $link->recipient_label,
        ], 'share');

        return response()->json([
            'message' => 'Tautan dokumen dicabut.',
            'data' => $this->present($link->fresh()),
        ]);
    }

    private function recordModel(string $module)
    {
        return $module === RecordShareLink::MODULE_DPIA ? new Dpia : new Ropa;
    }

    private function find(Request $request, string $module, string $id): RecordShareLink
    {
        return RecordShareLink::where('org_id', $request->user()->org_id)
            ->where('module', $module)
            ->findOrFail($id);
    }

    private function present(RecordShareLink $l, ?string $token = null, ?string $password = null): array
    {
        $out = [
            'id' => $l->id,
            'module' => $l->module,
            'record_id' => $l->record_id,
            'recipient_label' => $l->recipient_label,
            'max_views' => $l->max_views,
            'view_count' => $l->view_count,
            'remaining_views' => $l->remainingViews(),
            'expires_at' => optional($l->expires_at)->toIso8601String(),
            'revoked_at' => optional($l->revoked_at)->toIso8601String(),
            'revoked_reason' => $l->revoked_reason,
            'last_viewed_at' => optional($l->last_viewed_at)->toIso8601String(),
            'active' => $l->isActive(),
            'created_at' => optional($l->created_at)->toIso8601String(),
        ];

        if ($token !== null) {
            $out['url'] = FrontendUrl::link('/berbagi/'.$token);
            $out['password'] = $password;
            $out['notice'] = 'URL dan kata sandi ini hanya ditampilkan sekali. Simpan sekarang, dan kirimkan lewat kanal terpisah.';
        }

        return $out;
    }
}
