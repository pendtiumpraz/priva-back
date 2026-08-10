<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\TenantModuleEntitlement;
use App\Models\User;

/**
 * Penegakan entitlement modul di level request.
 *
 * Entitlement adalah lapisan komersial, TERPISAH dari izin RBAC: RBAC menjawab
 * "apakah role ini boleh membuka modul", entitlement menjawab "apakah
 * organisasi ini membeli modul". Keduanya harus terpenuhi.
 *
 * Sebelum ini, entitlement hanya dievaluasi di MenuRegistryService::forUser()
 * — yang membangun daftar menu sidebar. Itu murni tampilan. Menu yang direvoke
 * hilang dari sidebar, tetapi endpoint-nya tetap menjawab 200, sehingga siapa
 * pun yang mengetik URL-nya tetap memperoleh datanya. Menyembunyikan menu
 * bukan keamanan; entitlement harus ditegakkan di tempat request diproses,
 * sama seperti izin.
 *
 * Aturan di sini SENGAJA cermin dari Layer 0 forUser():
 *   - ada record dan isActive()=false (dicabut / kedaluwarsa) → DITOLAK
 *   - ada record dan isActive()=true  (dibeli eksplisit)      → diizinkan
 *   - tidak ada record (default open)                          → diizinkan
 * Hanya pencabutan eksplisit yang memblokir. Itu penting: mayoritas tenant
 * tidak punya satu pun record, dan mereka tidak boleh ikut terkunci.
 */
class EntitlementService
{
    /**
     * Peta menu_key → daftar menu_id yang dicabut, per org, dalam satu request.
     *
     * @var array<string, array<string, true>>
     */
    private array $revokedCache = [];

    /**
     * Peta permission-module-id → menu_key.
     *
     * Route menyebut modul dengan id izin (mis. `data_discovery`, `vendor_risk`),
     * sedangkan entitlement disimpan per menu. Peta ini menjembatani keduanya.
     * Nilainya dibalik dari MenuRegistryService supaya kedua sisi tidak
     * mungkin bergeser diam-diam.
     *
     * @var array<string, string>|null
     */
    private ?array $moduleToMenuKey = null;

    /**
     * Boleh-kah user mengakses modul (dari sisi entitlement saja)?
     *
     * $moduleId adalah id izin seperti yang ditulis di route
     * (`->middleware('permission:dpia,read')`), bukan slug URL.
     */
    public function allowsModule(User $user, string $moduleId): bool
    {
        // Platform staff mengelola semua tenant — mereka tidak dibatasi oleh
        // apa yang dibeli satu tenant. Konsisten dengan PermissionService yang
        // juga membypass keduanya.
        if (in_array($user->role, ['root', 'superadmin'], true)) {
            return true;
        }

        $menuKey = $this->menuKeyForModule($moduleId);
        if ($menuKey === null) {
            // Modul tanpa konsep menu/entitlement — tidak ada yang bisa dicabut.
            return true;
        }

        return $this->allowsMenuKey($user->org_id, $menuKey);
    }

    /**
     * Boleh-kah org mengakses sebuah menu (dari sisi entitlement saja)?
     *
     * Dipakai langsung oleh middleware `entitlement:<menu_key>` pada route yang
     * tidak melewati gerbang izin.
     */
    public function allowsMenuKey(?string $orgId, string $menuKey): bool
    {
        if (! $orgId) {
            return true;
        }

        return ! isset($this->revokedMenuIds($orgId)[$this->menuIdFor($menuKey) ?? '']);
    }

    /**
     * Daftar menu_id yang dicabut untuk sebuah org (mengikuti isActive()).
     *
     * @return array<string, true>
     */
    private function revokedMenuIds(string $orgId): array
    {
        if (isset($this->revokedCache[$orgId])) {
            return $this->revokedCache[$orgId];
        }

        $revoked = [];
        foreach (TenantModuleEntitlement::where('org_id', $orgId)->get() as $row) {
            if (! $row->isActive()) {
                $revoked[$row->menu_id] = true;
            }
        }

        return $this->revokedCache[$orgId] = $revoked;
    }

    private function menuKeyForModule(string $moduleId): ?string
    {
        if ($this->moduleToMenuKey === null) {
            $map = [];
            foreach (MenuRegistryService::permissionMenuMap() as $menuKey => $module) {
                // Normalisasi ke garis bawah supaya `vendor-risk` (menu) dan
                // `vendor_risk` (izin) bertemu di satu bentuk.
                $map[str_replace('-', '_', $module)] = $menuKey;
            }
            $this->moduleToMenuKey = $map;
        }

        return $this->moduleToMenuKey[str_replace('-', '_', $moduleId)] ?? null;
    }

    private function menuIdFor(string $menuKey): ?string
    {
        return MenuItem::where('menu_key', $menuKey)->value('id');
    }
}
