<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StoragePool;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Root/superadmin CRUD for storage_pools registry — registered S3/MinIO/
 * GCS endpoints Privasimu can route tenant uploads through.
 *
 * Generalization of the prior single-row `app_settings.platform.storage.*`
 * pattern. The migration auto-seeded one default row from app_settings if
 * the platform had configured storage there. Now multiple pools coexist
 * with `is_default = true` marking the fallback used by tenants without
 * an explicit assignment.
 *
 * Gated by `role.root` middleware at the route level.
 */
class StoragePoolController extends Controller
{
    public function __construct(protected TenantStorageService $storageService) {}

    public function index(Request $request)
    {
        $query = StoragePool::query()->whereNull('deleted_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('driver')) {
            $query->where('driver', $request->driver);
        }
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhere('bucket', 'like', $term));
        }

        $pools = $query->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        $pools->getCollection()->transform(fn ($p) => $this->presentPool($p));

        return response()->json(['data' => $pools]);
    }

    public function show(string $id)
    {
        $pool = StoragePool::query()->whereNull('deleted_at')->findOrFail($id);
        return response()->json(['data' => $this->presentPool($pool)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120|unique:storage_pools,name',
            'description' => 'nullable|string|max:2000',
            'driver' => ['required', Rule::in([StoragePool::DRIVER_S3, StoragePool::DRIVER_MINIO, StoragePool::DRIVER_DO_SPACES, StoragePool::DRIVER_GCS])],
            'endpoint' => 'nullable|string|max:500',
            'region' => 'nullable|string|max:40',
            'bucket' => 'required|string|max:255',
            'access_key' => 'required|string',
            'secret_key' => 'required|string',
            'use_path_style_endpoint' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'status' => ['nullable', Rule::in([StoragePool::STATUS_ACTIVE, StoragePool::STATUS_DISABLED])],
            'metadata' => 'nullable|array',
        ]);

        $pool = DB::transaction(function () use ($data, $request) {
            // Only one row can be default — unset previous default if needed
            if (!empty($data['is_default'])) {
                StoragePool::where('is_default', true)->update(['is_default' => false]);
            }

            $pool = new StoragePool();
            $pool->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'driver' => $data['driver'],
                'endpoint' => $data['endpoint'] ?? null,
                'region' => $data['region'] ?? null,
                'bucket' => $data['bucket'],
                'use_path_style_endpoint' => (bool) ($data['use_path_style_endpoint'] ?? ($data['driver'] !== 's3')),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'status' => $data['status'] ?? StoragePool::STATUS_ACTIVE,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $pool->access_key = $data['access_key'];
            $pool->secret_key = $data['secret_key'];
            $pool->save();
            return $pool;
        });

        $this->audit($request, $pool, 'storage_pool_created');

        return response()->json([
            'message' => "Storage pool '{$pool->name}' created.",
            'data' => $this->presentPool($pool),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $pool = StoragePool::query()->whereNull('deleted_at')->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('storage_pools', 'name')->ignore($pool->id)],
            'description' => 'sometimes|nullable|string|max:2000',
            'driver' => ['sometimes', Rule::in([StoragePool::DRIVER_S3, StoragePool::DRIVER_MINIO, StoragePool::DRIVER_DO_SPACES, StoragePool::DRIVER_GCS])],
            'endpoint' => 'sometimes|nullable|string|max:500',
            'region' => 'sometimes|nullable|string|max:40',
            'bucket' => 'sometimes|string|max:255',
            'access_key' => 'sometimes|nullable|string',  // empty = keep
            'secret_key' => 'sometimes|nullable|string',  // empty = keep
            'use_path_style_endpoint' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'status' => ['sometimes', Rule::in([StoragePool::STATUS_ACTIVE, StoragePool::STATUS_DISABLED])],
            'metadata' => 'sometimes|nullable|array',
        ]);

        DB::transaction(function () use ($data, $pool) {
            // Plain fields
            foreach (['name', 'description', 'driver', 'endpoint', 'region', 'bucket', 'use_path_style_endpoint', 'status', 'metadata'] as $key) {
                if (array_key_exists($key, $data)) {
                    $pool->$key = $data[$key];
                }
            }

            // is_default with unset-others guard
            if (array_key_exists('is_default', $data)) {
                if ($data['is_default'] && !$pool->is_default) {
                    StoragePool::where('is_default', true)->update(['is_default' => false]);
                }
                $pool->is_default = (bool) $data['is_default'];
            }

            // Secrets: only update on non-empty
            if (!empty($data['access_key'])) {
                $pool->access_key = $data['access_key'];
            }
            if (!empty($data['secret_key'])) {
                $pool->secret_key = $data['secret_key'];
            }

            $pool->save();
        });

        $this->audit($request, $pool, 'storage_pool_updated');

        return response()->json([
            'message' => "Storage pool '{$pool->name}' updated.",
            'data' => $this->presentPool($pool->fresh()),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $pool = StoragePool::query()->whereNull('deleted_at')->findOrFail($id);

        // Refuse to delete a pool any tenant is currently pinned to
        $assignedCount = DB::table('organizations')
            ->where('storage_pool_id', $pool->id)
            ->whereNull('deleted_at')
            ->count();

        if ($assignedCount > 0) {
            return response()->json([
                'message' => "Cannot delete: {$assignedCount} tenant(s) still assigned to this storage pool. Reassign them first.",
            ], 422);
        }

        $pool->delete();
        $this->audit($request, $pool, 'storage_pool_deleted');

        return response()->json(['message' => "Storage pool '{$pool->name}' deleted."]);
    }

    /**
     * Mark this pool as the platform default. Atomically unsets any other
     * default. Tenants without an explicit storage_pool_id will start
     * resolving here as Layer 3 of the storage fallback.
     */
    public function setDefault(Request $request, string $id)
    {
        $pool = StoragePool::query()->whereNull('deleted_at')->findOrFail($id);

        DB::transaction(function () use ($pool) {
            StoragePool::where('is_default', true)->where('id', '!=', $pool->id)->update(['is_default' => false]);
            $pool->is_default = true;
            $pool->save();
        });

        $this->audit($request, $pool, 'storage_pool_set_default');

        return response()->json([
            'message' => "Storage pool '{$pool->name}' is now the platform default.",
            'data' => $this->presentPool($pool->fresh()),
        ]);
    }

    /**
     * Probe a storage config without persisting. Builds a transient
     * Flysystem disk and writes/reads/deletes a probe file under
     * `_probe/...` to verify credentials + bucket access.
     */
    public function testConnection(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|uuid',
            'driver' => ['required', Rule::in([StoragePool::DRIVER_S3, StoragePool::DRIVER_MINIO, StoragePool::DRIVER_DO_SPACES, StoragePool::DRIVER_GCS])],
            'endpoint' => 'nullable|string|max:500',
            'region' => 'nullable|string|max:40',
            'bucket' => 'required|string|max:255',
            'access_key' => 'nullable|string',
            'secret_key' => 'nullable|string',
            'use_path_style_endpoint' => 'nullable|boolean',
        ]);

        // Fall back to saved secrets if id provided + secrets blank
        $accessKey = $data['access_key'] ?? null;
        $secretKey = $data['secret_key'] ?? null;
        if ((empty($accessKey) || empty($secretKey)) && !empty($data['id'])) {
            $existing = StoragePool::query()->find($data['id']);
            if ($existing) {
                $accessKey = $accessKey ?: $existing->access_key;
                $secretKey = $secretKey ?: $existing->secret_key;
            }
        }
        if (empty($accessKey) || empty($secretKey)) {
            return response()->json(['success' => false, 'message' => 'access_key and secret_key required (or pool id with saved secrets).'], 422);
        }

        // Map to TenantStorageService::testConnectionWithConfig shape
        $config = [
            'key' => $accessKey,
            'secret' => $secretKey,
            'bucket' => $data['bucket'],
            'region' => $data['region'] ?? 'us-east-1',
            'endpoint' => $data['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($data['use_path_style_endpoint'] ?? ($data['driver'] !== 's3')),
        ];

        // GCS uses different field shape
        if ($data['driver'] === 'gcs') {
            $config = [
                'project_id' => $data['region'] ?? null,
                'key_file' => $accessKey,
                'bucket' => $data['bucket'],
            ];
        }

        $result = $this->storageService->testConnectionWithConfig($data['driver'], $config);
        return response()->json($result);
    }

    private function presentPool(StoragePool $pool): array
    {
        return [
            'id' => $pool->id,
            'name' => $pool->name,
            'description' => $pool->description,
            'driver' => $pool->driver,
            'endpoint' => $pool->endpoint,
            'region' => $pool->region,
            'bucket' => $pool->bucket,
            'has_access_key' => !empty($pool->getRawOriginal('access_key')),
            'has_secret_key' => !empty($pool->getRawOriginal('secret_key')),
            'use_path_style_endpoint' => (bool) $pool->use_path_style_endpoint,
            'is_default' => (bool) $pool->is_default,
            'status' => $pool->status,
            'metadata' => $pool->metadata,
            'created_at' => $pool->created_at,
            'updated_at' => $pool->updated_at,
        ];
    }

    private function audit(Request $request, StoragePool $pool, string $action): void
    {
        try {
            AuditLog::log('storage_pool', $pool->id, $action, [
                'name' => $pool->name,
                'driver' => $pool->driver,
                'bucket' => $pool->bucket,
            ], 'manual');
        } catch (\Exception $e) {
            // best-effort
        }
    }
}
