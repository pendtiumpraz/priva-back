<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\VendorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tautan publik sekali-unggah untuk kontrak pihak ketiga.
 *
 * Kontrak boleh datang dari dua arah: diunggah perusahaan sendiri, atau — bila
 * perusahaan tidak memilikinya — oleh pihak ketiga lewat tautan ini. Polanya
 * sama dengan tautan asesmen dan RoPA pihak ketiga: UUID v7 pada baris datanya,
 * mati begitu di-rotate, dan terkunci setelah dipakai.
 */
class VendorContractTokenService
{
    public function generate(VendorContract $contract): string
    {
        $token = (string) Str::uuid7();

        $contract->forceFill([
            'access_token' => $token,
            'token_expires_at' => null,
            'token_consumed_at' => null,
        ])->save();

        return $token;
    }

    public function verify(string $token): ?VendorContract
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $query = VendorContract::query();
        if ($query->getModel()::hasGlobalScope('org')) {
            $query->withoutGlobalScope('org');
        }

        return $query->where('access_token', $token)->first();
    }

    public function markConsumed(VendorContract $contract, Request $request): void
    {
        $contract->forceFill(['token_consumed_at' => now()])->save();

        AuditLog::create([
            'org_id' => $contract->org_id,
            'module' => 'tprm.contract_upload',
            'record_id' => $contract->id,
            'action' => 'public_upload',
            'user_id' => null,
            'user_name' => 'Public Token',
            'user_role' => 'public_token',
            'section' => 'vendor_contract',
            'changes' => [
                'token_prefix' => substr((string) $contract->access_token, 0, 8),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
