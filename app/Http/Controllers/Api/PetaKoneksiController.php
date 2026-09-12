<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Services\ConnectionMap\RecordGraphBuilder;
use App\Services\ConnectionMap\RelationCatalog;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Peta koneksi per modul dan per record.
 *
 * Dua tampilan, satu mesin (RecordGraphBuilder di atas RelationCatalog):
 *   GET /peta-koneksi/{modul}        — seluruh record modul, dikelompokkan per tahap
 *   GET /peta-koneksi/{modul}/{id}   — satu record beserta tetangganya
 *
 * Izinnya TIDAK ditulis ulang di sini. Modulnya dinamis di URL sehingga
 * `permission:` tidak bisa dipasang sebagai middleware rute, jadi middleware
 * yang sama dipanggil langsung. Menyalin aturannya akan menjadi salinan KETIGA
 * (setelah CheckPermission dan ModuleCrudController) — dan CLAUDE.md sudah
 * memperingatkan dua salinan yang ada harus dijaga sinkron. Cara ini juga
 * membawa serta gerbang entitlement: modul yang tidak dimiliki organisasi tidak
 * dapat dibuka petanya, bukan hanya disembunyikan menunya.
 */
class PetaKoneksiController extends Controller
{
    /**
     * Slug URL → [jenis simpul, module_id untuk izin].
     *
     * Modul yang tidak ada di sini sengaja tidak punya peta: GAP, Maturity, dan
     * Policy Review tidak memiliki satu pun tautan lintas modul, sehingga
     * petanya hanya akan berisi dirinya sendiri. Menyajikan peta kosong yang
     * terlihat seperti fitur rusak lebih buruk daripada tidak menyajikannya.
     */
    private const MODULES = [
        'ropa' => ['ropa', 'ropa'],
        'dpia' => ['dpia', 'dpia'],
        'dsr' => ['dsr', 'dsr'],
        'consent' => ['consent', 'consent'],
        'breach' => ['breach', 'breach'],
        'data-discovery' => ['data_discovery', 'data_discovery'],
        'vendor-risk' => ['third_party', 'vendor_risk'],
        'cross-border' => ['cross_border', 'cross_border'],
        'lia' => ['lia', 'lia'],
        'tia' => ['tia', 'tia'],
        'contract-review' => ['contract_review', 'contract_review'],
    ];

    public function __construct(private RecordGraphBuilder $builder) {}

    public function module(Request $request, string $module): Response
    {
        return $this->gate($request, $module, function (string $type, string $orgId) {
            return response()->json(['data' => $this->builder->forModule($orgId, $type)]);
        });
    }

    public function record(Request $request, string $module, string $id): Response
    {
        return $this->gate($request, $module, function (string $type, string $orgId) use ($id) {
            return response()->json(['data' => $this->builder->forRecord($orgId, $type, $id)]);
        });
    }

    /** Modul mana yang punya peta — dipakai frontend untuk memutuskan tombolnya muncul. */
    public function supported(): JsonResponse
    {
        return response()->json([
            'data' => array_keys(self::MODULES),
            'tanpa_relasi' => RelationCatalog::WITHOUT_RELATIONS,
        ]);
    }

    /**
     * Jenis simpul yang modulnya masih dimiliki organisasi ini.
     *
     * Memakai `allowsModule($user, …)` yang sudah publik — termasuk bypass
     * platform staff, yang memang harus melihat seluruh tenant. Menyalin aturan
     * entitlement ke sini akan menjadi salinan lain yang harus dijaga sinkron.
     *
     * @return array<int, string>
     */
    private function jenisYangDimiliki(Request $request): array
    {
        $ent = app(EntitlementService::class);
        $user = $request->user();

        $izin = [];
        foreach (array_keys(RelationCatalog::nodeSources()) as $jenis) {
            $moduleId = RelationCatalog::MODULE_ID[$jenis] ?? null;
            // Jenis tanpa konsep entitlement tidak bisa dicabut, jadi selalu boleh.
            if ($moduleId === null || $ent->allowsModule($user, $moduleId)) {
                $izin[] = $jenis;
            }
        }

        return $izin;
    }

    private function gate(Request $request, string $module, \Closure $build): Response
    {
        $peta = self::MODULES[$module] ?? null;
        if (! $peta) {
            return response()->json([
                'message' => 'Modul ini tidak memiliki peta koneksi.',
            ], 404);
        }
        [$type, $permissionModule] = $peta;

        $orgId = (string) ($request->user()->org_id ?? '');
        if ($orgId === '') {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        return app(CheckPermission::class)->handle(
            $request,
            function () use ($build, $type, $orgId, $request) {
                // CheckPermission hanya menggerbangi modul yang DIPUSATKAN.
                // Simpul tetangganya berasal dari modul lain dan harus
                // digerbangi sendiri — tanpa ini, tenant yang modul DPIA-nya
                // dicabut tetap melihat simpul DPIA di peta RoPA-nya.
                $this->builder->hanyaJenis($this->jenisYangDimiliki($request));

                return $build($type, $orgId);
            },
            $permissionModule,
            'read',
        );
    }
}
