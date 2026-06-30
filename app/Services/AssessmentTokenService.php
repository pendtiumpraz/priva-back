<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\VendorAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Generate, verify, dan finalize token akses publik untuk Vendor Assessment.
 *
 * Flow operasional:
 *   1. Admin tenant panggil generate() saat ingin kirim kuisoner ke pihak ketiga
 *      → assessment_token (UUID v7) + token_expires_at di-set, status='sent'.
 *   2. URL yang dibagikan: https://nexus.privasimu.com/asesmen-pihak-ketiga/{token}
 *   3. Middleware PublicAssessmentTokenMiddleware memanggil verify() di setiap
 *      request publik. Hasil dilampirkan ke request sebagai `_assessment`.
 *   4. markConsumed() dipanggil sekali saat /submit — meng-set submitted_at,
 *      submitted_ip, submitted_user_agent, status='submitted', dan stempel
 *      token_consumed_at. Submit kedua di-tolak oleh middleware.
 *
 * Keamanan:
 *   - UUID v7 punya prefix timestamp ordered → tidak menambah informasi
 *     sensitif tapi mempermudah index B-tree.
 *   - Expiry default 30 hari; configurable via system_settings key
 *     `tprm_public_link_expiry_days`.
 *   - Single-use: token_consumed_at hanya di-set sekali.
 */
class AssessmentTokenService
{
    public const DEFAULT_EXPIRY_DAYS = 30;
    public const SETTING_EXPIRY_KEY = 'tprm_public_link_expiry_days';

    /**
     * Generate token UUID v7, set expiry, ubah status ke 'sent'.
     *
     * Tidak mengirim email — caller (controller admin) yang handle pengiriman.
     * Return token string (UUID) supaya bisa di-embed ke URL share.
     */
    public function generate(VendorAssessment $assessment, ?int $expiryDays = null): string
    {
        $token = (string) Str::uuid7();

        // KEBIJAKAN (revisi 2026-06-30): tautan publik TIDAK kedaluwarsa
        // berbasis waktu. Tautan hanya menjadi tidak berlaku saat di-REGENERATE
        // (token baru menimpa UUID lama pada row yang sama → UUID lama tak lagi
        // ter-resolve = otomatis invalid). `token_expires_at` di-set null supaya
        // assessment yang sudah selesai diisi tetap bisa dibuka kapan pun.
        // (Parameter $expiryDays dipertahankan utk kompatibilitas pemanggil;
        //  bila >0 eksplisit, baru pakai expiry waktu — default null.)
        $expiresAt = ($expiryDays !== null && $expiryDays > 0)
            ? now()->addDays($expiryDays)
            : null;

        $assessment->forceFill([
            'assessment_token' => $token,
            'token_expires_at' => $expiresAt,
            'token_consumed_at' => null,
            'status' => 'sent',
        ])->save();

        return $token;
    }

    /**
     * Resolve token ke VendorAssessment. Tidak melakukan validasi expiry /
     * consumed — itu tugas middleware supaya bisa return HTTP code yang tepat
     * (410 vs 404). Return null kalau token tidak ada.
     *
     * Wajib pakai withoutGlobalScope('org') karena pemanggil belum ter-auth,
     * jadi CurrentOrgContext masih null — global scope sudah no-op, tapi kita
     * jaga eksplisit kalau ke depan model di-attach trait BelongsToOrg.
     */
    public function verify(string $token): ?VendorAssessment
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $query = VendorAssessment::query();
        if ($query->getModel()::hasGlobalScope('org')) {
            $query->withoutGlobalScope('org');
        }

        return $query->where('assessment_token', $token)->first();
    }

    /**
     * Finalize submission: set consumed_at, ip, ua, status, dan tulis audit
     * log. Idempotent guard ada di middleware — service ini asumsikan caller
     * sudah lulus pengecekan.
     */
    public function markConsumed(VendorAssessment $assessment, Request $request): void
    {
        $now = now();

        $assessment->forceFill([
            'token_consumed_at' => $now,
            'submitted_at' => $now,
            'submitted_ip' => substr((string) $request->ip(), 0, 45),
            'submitted_user_agent' => $request->userAgent(),
            'status' => 'submitted',
        ])->save();

        // Audit trail — pakai schema AuditLog yang ada (module/record_id/
        // action/changes). Field `changes` carry payload tambahan: token
        // (truncated), ip, ua, supaya investigator bisa trace asal submit.
        AuditLog::create([
            'module' => 'tprm.public_submit',
            'record_id' => $assessment->id,
            'action' => 'public_submit',
            'user_id' => null,
            'user_name' => 'Public Token',
            'user_role' => 'public_token',
            'section' => 'vendor_assessment',
            'field' => null,
            'changes' => [
                'token_prefix' => substr((string) $assessment->assessment_token, 0, 8),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'submitted_at' => $now->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Hari expiry default, baca dari system_settings dengan fallback ke
     * DEFAULT_EXPIRY_DAYS. Dipanggil sekali per generate() — tidak hot path.
     */
    private function configuredExpiryDays(): int
    {
        $value = SystemSetting::get(self::SETTING_EXPIRY_KEY, self::DEFAULT_EXPIRY_DAYS);

        if (is_array($value)) {
            $value = $value[0] ?? self::DEFAULT_EXPIRY_DAYS;
        }

        $days = (int) $value;
        return $days > 0 ? $days : self::DEFAULT_EXPIRY_DAYS;
    }
}
