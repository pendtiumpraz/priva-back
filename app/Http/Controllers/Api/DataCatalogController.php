<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DataCatalogAsset;
use App\Models\DataCatalogLineage;
use App\Services\DataCatalogService;
use App\Services\PurviewConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog metadata terpusat dan penelusuran silsilah data.
 */
class DataCatalogController extends Controller
{
    public function __construct(private readonly DataCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $query = DataCatalogAsset::query();

        if ($type = $request->input('asset_type')) {
            $query->where('asset_type', $type);
        }
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        if ($class = $request->input('classification')) {
            $query->where('classification', $class);
        }
        if ($systemId = $request->input('information_system_id')) {
            $query->where('information_system_id', $systemId);
        }
        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('qualified_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('steward', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->orderBy('asset_type')->orderBy('qualified_name')
                ->paginate((int) $request->input('per_page', 50)),
            'meta' => [
                'types' => DataCatalogAsset::TYPES,
                'sources' => DataCatalogAsset::SOURCES,
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->summary($request->user()->org_id)]);
    }

    /** Bangun ulang katalog dan silsilah dari data platform. */
    public function sync(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $assets = $this->catalog->syncFromDiscovery($orgId);
        $lineage = $this->catalog->rebuildLineage($orgId);

        AuditLog::log('data-discovery', $orgId, 'catalog_synced', $assets + $lineage, 'catalog');

        return response()->json([
            'message' => 'Katalog disinkronkan.',
            'data' => $assets + $lineage,
        ]);
    }

    /** Telusuri silsilah dari satu aset, ke hulu dan ke hilir. */
    public function trace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_key' => 'required|string|max:300',
            'depth' => 'nullable|integer|min:1|max:6',
        ]);

        return response()->json([
            'data' => $this->catalog->trace(
                $request->user()->org_id,
                $data['asset_key'],
                (int) ($data['depth'] ?? 3),
            ),
        ]);
    }

    /** Tambah aset yang tidak dapat diturunkan dari pemindaian. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_key' => 'required|string|max:300',
            'asset_type' => 'required|in:'.implode(',', DataCatalogAsset::TYPES),
            'name' => 'required|string|max:400',
            'qualified_name' => 'nullable|string|max:600',
            'description' => 'nullable|string',
            'classification' => 'nullable|string|max:32',
            'pdp_category' => 'nullable|string|max:20',
            'steward' => 'nullable|string|max:200',
            'owner_user_id' => 'nullable|uuid',
            'tags' => 'nullable|array',
        ]);

        $asset = DataCatalogAsset::updateOrCreate(
            ['org_id' => $request->user()->org_id, 'asset_key' => $data['asset_key']],
            array_merge($data, ['source' => 'manual', 'last_synced_at' => now()]),
        );

        return response()->json(['data' => $asset], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $asset = DataCatalogAsset::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:400',
            'description' => 'sometimes|nullable|string',
            'classification' => 'sometimes|nullable|string|max:32',
            'pdp_category' => 'sometimes|nullable|string|max:20',
            // Sempat terlewat dari daftar ini, sehingga kewajiban enkripsi yang
            // ditetapkan pengguna dibuang diam-diam oleh validator — tersimpan
            // menurut layar, hilang menurut basis data.
            'encryption_required' => 'sometimes|boolean',
            'steward' => 'sometimes|nullable|string|max:200',
            'owner_user_id' => 'sometimes|nullable|uuid',
            'tags' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        // Menyentuh salah satu dari tiga kolom ini berarti manusia mengambil
        // alih keputusan sensitivitasnya. Sejak itu, sinkronisasi — baik dari
        // pemindaian sendiri maupun tarikan Purview — tidak boleh menimpanya
        // lagi. Inilah yang membuat klasifikasi cukup ditetapkan di satu
        // tempat, dan tetap bertahan di sana.
        $claimsClassification = array_intersect_key(
            $data,
            array_flip(['classification', 'pdp_category', 'encryption_required']),
        );

        if ($claimsClassification !== []) {
            $data['manually_classified'] = true;
            $data['classified_by'] = $request->user()->id;
            $data['classified_at'] = now();

            AuditLog::log('data-discovery', $asset->id, 'asset_classified', [
                'asset_key' => $asset->asset_key,
                'from' => $asset->only(['classification', 'pdp_category', 'encryption_required']),
                'to' => $claimsClassification,
            ], 'catalog');
        }

        $asset->update($data);

        return response()->json(['data' => $asset->fresh()]);
    }

    /**
     * Kembalikan aset agar mengikuti klasifikasi sumbernya lagi.
     *
     * Kebalikan dari penyuntingan manual. Disediakan supaya keputusan yang
     * terlanjur salah dapat dicabut — tanpa ini, satu-satunya jalan pulang
     * adalah menyunting basis data secara langsung.
     */
    public function releaseClassification(Request $request, string $id): JsonResponse
    {
        $asset = DataCatalogAsset::findOrFail($id);

        $asset->update([
            'manually_classified' => false,
            'classified_by' => null,
            'classified_at' => null,
        ]);

        AuditLog::log('data-discovery', $asset->id, 'asset_classification_released', [
            'asset_key' => $asset->asset_key,
        ], 'catalog');

        return response()->json([
            'message' => 'Aset kembali mengikuti klasifikasi sumbernya pada sinkronisasi berikutnya.',
            'data' => $asset->fresh(),
        ]);
    }

    /** Tambah tepi silsilah secara manual. */
    public function storeLineage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_key' => 'required|string|max:300',
            'to_key' => 'required|string|max:300|different:from_key',
            'relation' => 'required|in:'.implode(',', DataCatalogLineage::RELATIONS),
            'description' => 'nullable|string|max:500',
        ]);

        $edge = DataCatalogLineage::updateOrCreate(
            [
                'org_id' => $request->user()->org_id,
                'from_key' => $data['from_key'],
                'to_key' => $data['to_key'],
                'relation' => $data['relation'],
            ],
            ['source' => 'manual', 'description' => $data['description'] ?? null],
        );

        return response()->json(['data' => $edge], 201);
    }

    public function destroyLineage(Request $request, string $id): JsonResponse
    {
        $edge = DataCatalogLineage::findOrFail($id);
        $edge->delete();

        return response()->json(['message' => 'Tepi silsilah dihapus.']);
    }

    /**
     * Kembalikan tepi silsilah yang dihapus.
     *
     * Paling menolong untuk tepi bersumber manual: ia pengetahuan yang diketik
     * orang dan tidak dapat dibangun ulang oleh sinkronisasi.
     */
    public function restoreLineage(Request $request, string $id): JsonResponse
    {
        $edge = DataCatalogLineage::onlyTrashed()->findOrFail($id);
        $edge->restore();

        return response()->json(['message' => 'Tepi silsilah dikembalikan.', 'data' => $edge]);
    }

    public function destroyAsset(Request $request, string $id): JsonResponse
    {
        $asset = DataCatalogAsset::findOrFail($id);
        $asset->delete();

        return response()->json(['message' => 'Aset dihapus.']);
    }

    public function restoreAsset(Request $request, string $id): JsonResponse
    {
        $asset = DataCatalogAsset::onlyTrashed()->findOrFail($id);
        $asset->restore();

        return response()->json(['message' => 'Aset dikembalikan.', 'data' => $asset]);
    }

    /**
     * Tarik aset langsung dari Microsoft Purview.
     *
     * Melengkapi `import` yang menerima ekspor tempelan. Bedanya bukan sekadar
     * kenyamanan: tempelan adalah potret sesaat, sedangkan ini dapat dijalankan
     * ulang kapan pun sehingga katalog mengikuti perubahan di Purview.
     *
     * Kredensial TIDAK disimpan di sini. Ia dikirim per permintaan dan hanya
     * hidup selama pemanggilan — modul katalog bukan tempat menyimpan rahasia
     * Azure, dan menyimpannya akan menuntut penjagaan yang belum ada di sini.
     */
    public function syncPurview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => 'required|string|max:100',
            'client_id' => 'required|string|max:100',
            'client_secret' => 'required|string|max:500',
            'account_name' => 'required|string|max:100',
            'keyword' => 'nullable|string|max:200',
            'test_only' => 'sometimes|boolean',
        ]);

        try {
            $purview = PurviewConnector::fromConfig($data);

            if ($request->boolean('test_only')) {
                return response()->json(['data' => $purview->testConnection()]);
            }

            $fetched = $purview->fetchAssets($data['keyword'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $fetched['rows']) {
            return response()->json([
                'message' => 'Purview tidak mengembalikan aset. Periksa kata kunci, atau pastikan koleksi yang dituju berisi aset yang dapat dibaca peran ini.',
                'data' => ['imported' => 0, 'skipped' => 0, 'edges' => 0],
            ]);
        }

        $result = $this->catalog->import(
            $request->user()->org_id,
            $fetched['rows'],
            'purview',
        );

        AuditLog::log('data-discovery', $request->user()->org_id, 'purview_synced', array_merge($result, [
            'account' => $data['account_name'],
            'fetched' => count($fetched['rows']),
            'truncated' => $fetched['truncated'],
        ]), 'catalog');

        return response()->json([
            'message' => "{$result['imported']} aset ditarik dari Purview.".
                ($fetched['truncated'] ? ' Sebagian dipotong karena melebihi batas satu sinkronisasi.' : ''),
            'data' => array_merge($result, ['truncated' => $fetched['truncated']]),
        ]);
    }

    /**
     * Impor aset dari ekspor katalog pihak lain.
     *
     * Menerima bentuk umum ekspor Collibra, Alation, dan Purview dengan
     * pemetaan kolom yang dapat disesuaikan. Tetap dipertahankan berdampingan
     * dengan syncPurview: tidak semua klien bersedia memberikan kredensial
     * Azure, dan sebagian katalog memang hanya bisa dikeluarkan sebagai berkas.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => 'required|string|max:32',
            'rows' => 'required|array|min:1|max:5000',
            'mapping' => 'nullable|array',
        ]);

        $result = $this->catalog->import(
            $request->user()->org_id,
            $data['rows'],
            strtolower($data['source']),
            $data['mapping'] ?? [],
        );

        AuditLog::log('data-discovery', $request->user()->org_id, 'catalog_imported', array_merge(
            $result,
            ['source' => $data['source']],
        ), 'catalog');

        return response()->json([
            'message' => "{$result['imported']} aset diimpor dari {$data['source']}.",
            'data' => $result,
        ]);
    }
}
