<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\VendorRopa;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Token tautan publik untuk RoPA pihak ketiga — kembaran AssessmentTokenService.
 *
 * Alur:
 *   1. Pengendali menerbitkan tautan (generate) → access_token UUID v7 di baris
 *      vendor_ropas, status kembali ke 'draft' supaya bisa diisi.
 *   2. URL yang dibagikan: {frontend}/ropa-pihak-ketiga/{token}
 *   3. Middleware PublicVendorRopaTokenMiddleware memanggil verify() tiap
 *      request publik dan melampirkan hasilnya sebagai `_vendorRopa`.
 *   4. markConsumed() dipanggil sekali di /submit → terkunci. Perubahan
 *      berikutnya hanya lewat permintaan akses ubah yang disetujui pengendali
 *      (generate ulang = token lama otomatis tidak berlaku).
 */
class VendorRopaTokenService
{
    public function generate(VendorRopa $vendorRopa, ?int $expiryDays = null): string
    {
        $token = (string) Str::uuid7();

        // Sejalan dengan tautan asesmen: tanpa kedaluwarsa waktu secara default.
        // Tautan mati saat di-rotate, bukan saat waktunya habis.
        $expiresAt = ($expiryDays !== null && $expiryDays > 0) ? now()->addDays($expiryDays) : null;

        $vendorRopa->forceFill([
            'access_token' => $token,
            'token_expires_at' => $expiresAt,
            'token_consumed_at' => null,
            'status' => VendorRopa::STATUS_DRAFT,
        ])->save();

        return $token;
    }

    /**
     * Resolve token → VendorRopa. Tidak memvalidasi expiry/consumed; itu tugas
     * middleware supaya status HTTP-nya tepat (404 vs 410).
     */
    public function verify(string $token): ?VendorRopa
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $query = VendorRopa::query();
        if ($query->getModel()::hasGlobalScope('org')) {
            $query->withoutGlobalScope('org');
        }

        return $query->where('access_token', $token)->first();
    }

    /** Finalisasi kiriman: kunci token, stempel pengirim, tulis jejak audit. */
    public function markConsumed(VendorRopa $vendorRopa, Request $request): void
    {
        $now = now();

        $vendorRopa->forceFill([
            'token_consumed_at' => $now,
            'submitted_at' => $now,
            'submitted_ip' => substr((string) $request->ip(), 0, 45),
            'submitted_user_agent' => $request->userAgent(),
            'status' => VendorRopa::STATUS_SUBMITTED,
        ])->save();

        AuditLog::create([
            'org_id' => $vendorRopa->org_id,
            'module' => 'tprm.vendor_ropa_submit',
            'record_id' => $vendorRopa->id,
            'action' => 'public_submit',
            'user_id' => null,
            'user_name' => 'Public Token',
            'user_role' => 'public_token',
            'section' => 'vendor_ropa',
            'field' => null,
            'changes' => [
                'token_prefix' => substr((string) $vendorRopa->access_token, 0, 8),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'submitted_at' => $now->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
