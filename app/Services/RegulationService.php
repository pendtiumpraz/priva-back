<?php

namespace App\Services;

use App\Models\OrgRegulation;
use App\Models\Regulation;
use Illuminate\Support\Facades\Cache;

/**
 * Resolusi regulasi yang aktif untuk sebuah tenant.
 *
 * Core (uu_pdp, pp_33) SELALU aktif dan tidak bisa dimatikan. Add-on aktif
 * hanya bila ada baris org_regulations.enabled = true. Dipakai untuk gating
 * konten KB (dan, ke depan, question library/framework).
 */
class RegulationService
{
    private const CACHE_TTL = 300;

    /**
     * Peta kode framework GAP (regulation_frameworks.code) → kode regulasi
     * registry. Framework tanpa entri di sini dianggap netral (selalu tampil).
     */
    public const FRAMEWORK_MAP = [
        'uupdp' => 'uu_pdp',
        'gdpr' => 'gdpr',
        'pdpa' => 'pdpa_sg',
    ];

    /** Apakah satu kode regulasi aktif untuk org. */
    public function isEnabled(?string $orgId, string $regCode): bool
    {
        return in_array($regCode, $this->enabledCodesFor($orgId), true);
    }

    /**
     * Kode regulasi aktif untuk org: core + add-on yang di-enable.
     * orgId null (superadmin/CLI) → core saja (fail-closed untuk add-on).
     *
     * @return array<int,string>
     */
    public function enabledCodesFor(?string $orgId): array
    {
        $core = Regulation::CORE_CODES;
        if (! $orgId) {
            return $core;
        }

        $addons = Cache::remember(
            $this->cacheKey($orgId),
            self::CACHE_TTL,
            fn () => OrgRegulation::query()
                ->where('org_id', $orgId)
                ->where('enabled', true)
                ->pluck('regulation_code')
                ->all()
        );

        return array_values(array_unique(array_merge($core, $addons)));
    }

    /**
     * Daftar regulasi (registry) + status enable untuk satu org.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listFor(?string $orgId): array
    {
        $enabled = $this->enabledCodesFor($orgId);

        return Regulation::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn (Regulation $r) => [
                'code' => $r->code,
                'name' => $r->name,
                'short' => $r->short,
                'category' => $r->category,
                'description' => $r->description,
                'is_core' => $r->is_core,
                'enabled' => $r->is_core || in_array($r->code, $enabled, true),
                'locked' => $r->is_core, // core tak bisa di-toggle
            ])
            ->all();
    }

    /**
     * Enable/disable satu add-on untuk org. Core ditolak (selalu aktif).
     */
    public function setEnabled(string $orgId, string $code, bool $enabled, ?string $userId = null): void
    {
        if (Regulation::isCore($code)) {
            abort(422, 'Regulasi inti (UU PDP / PP 33) wajib dan tidak bisa dinonaktifkan.');
        }

        OrgRegulation::updateOrCreate(
            ['org_id' => $orgId, 'regulation_code' => $code],
            ['enabled' => $enabled, 'updated_by' => $userId],
        );

        Cache::forget($this->cacheKey($orgId));
    }

    private function cacheKey(string $orgId): string
    {
        return "regulations:enabled:{$orgId}";
    }
}
