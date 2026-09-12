<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\CurrentOrgContext;
use App\Services\TenantDb\TenantDatabaseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Padanan `tenant.context` + `tenant.db` untuk jalur berkunci API mitra.
 *
 * Keduanya yang asli menentukan tenant dari `$request->user()`, lalu berhenti
 * lebih awal bila tidak ada pengguna. Pada permintaan berkunci API memang tidak
 * pernah ada pengguna, sehingga memasang `tenant.context`/`tenant.db` di grup v1
 * hanya akan tampak seperti perbaikan tanpa benar-benar berbuat apa pun.
 *
 * Di sini tenantnya datang dari kunci API (`api_org_id`, disetel
 * AuthenticatePartnerApi), jadi middleware ini WAJIB dipasang SESUDAH middleware
 * itu — pada Laravel, middleware rute dijalankan sesuai urutan penulisannya.
 *
 * Dua hal yang dikerjakan:
 *   1. menyetel CurrentOrgContext, supaya scope global BelongsToOrg ikut hidup
 *      pada jalur ini dan bukan hanya bersandar pada filter org_id manual;
 *   2. mengalihkan koneksi bawaan ke basis data tenant bila tenantnya memang
 *      sudah terisolasi (BYODB).
 *
 * Tanpa nomor 2, penulisan atas nama tenant terisolasi akan mendarat di basis
 * data platform. Pada pembacaan akibatnya sekadar hasil kosong; pada penulisan
 * akibatnya record yatim di basis data yang salah — jauh lebih sulit dibereskan
 * daripada dicegah.
 *
 * Keadaan setengah jalan (`provisioning`/`migrating`) sengaja TIDAK dialihkan,
 * meniru InitializeTenantDatabase: menulis ke basis data yang baru separuh jadi
 * lebih buruk daripada menunda.
 */
class ResolveTenantDatabaseForApiKey
{
    public function __construct(
        private CurrentOrgContext $context,
        private TenantDatabaseService $service,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $orgId = $request->attributes->get('api_org_id');
        if (! $orgId) {
            // Tanpa kunci yang sudah tervalidasi, tidak ada yang bisa diputuskan.
            // AuthenticatePartnerApi yang berwenang menolak, bukan middleware ini.
            return $next($request);
        }

        $this->context->set($orgId);

        // Organization tinggal di basis data platform (landlord), jadi pencarian
        // ini aman dilakukan sebelum koneksi dialihkan.
        $org = Organization::find($orgId);
        if (! $org || $org->tenant_db_state !== 'isolated') {
            return $next($request);
        }

        $connectionName = $this->service->getConnection($org);
        if ($connectionName !== $this->service->landlordConnectionName()) {
            DB::setDefaultConnection($connectionName);
        }

        return $next($request);
    }
}
