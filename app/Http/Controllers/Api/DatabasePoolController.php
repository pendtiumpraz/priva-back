<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DatabasePool;
use App\Services\TenantDb\TenantDatabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Root/superadmin CRUD for the database_pools registry. Each row is a
 * Postgres/MySQL cluster Privasimu can provision tenant DBs into.
 *
 * Provisioner credentials (provisioner_password, ca_cert) are write-only
 * on this surface: input is plaintext, storage is encrypted via the model
 * accessors, output never includes the secret. Updates accept partial
 * payloads — empty string means "keep existing value".
 *
 * Gated by `role.root` middleware at the route level.
 */
class DatabasePoolController extends Controller
{
    public function __construct(protected TenantDatabaseService $dbService) {}

    public function index(Request $request)
    {
        $query = DatabasePool::query()->whereNull('deleted_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('engine')) {
            $query->where('engine', $request->engine);
        }
        if ($request->filled('region')) {
            $query->where('region', $request->region);
        }
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhere('host', 'like', $term));
        }

        $pools = $query->orderBy('current_tenants_count')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        // Mask secrets in response
        $pools->getCollection()->transform(fn ($p) => $this->presentPool($p));

        return response()->json(['data' => $pools]);
    }

    public function show(string $id)
    {
        $pool = DatabasePool::query()->whereNull('deleted_at')->findOrFail($id);
        return response()->json(['data' => $this->presentPool($pool)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120|unique:database_pools,name',
            'description' => 'nullable|string|max:2000',
            'engine' => ['required', Rule::in([DatabasePool::ENGINE_PGSQL, DatabasePool::ENGINE_MYSQL])],
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'provisioner_user' => 'required|string|max:64',
            'provisioner_password' => 'required|string',
            'sslmode' => 'nullable|string|max:20',
            'ca_cert' => 'nullable|string',
            'region' => 'nullable|string|max:40',
            'status' => ['nullable', Rule::in([DatabasePool::STATUS_ACTIVE, DatabasePool::STATUS_DISABLED, DatabasePool::STATUS_DRAINING])],
            'max_tenants' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
        ]);

        $pool = new DatabasePool();
        $pool->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'engine' => $data['engine'],
            'host' => $data['host'],
            'port' => $data['port'],
            'provisioner_user' => $data['provisioner_user'],
            'sslmode' => $data['sslmode'] ?? 'require',
            'region' => $data['region'] ?? null,
            'status' => $data['status'] ?? DatabasePool::STATUS_ACTIVE,
            'max_tenants' => $data['max_tenants'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'created_by' => $request->user()?->id,
        ]);
        // Use mutators to encrypt
        $pool->provisioner_password = $data['provisioner_password'];
        if (!empty($data['ca_cert'])) {
            $pool->ca_cert = $data['ca_cert'];
        }
        $pool->save();

        $this->audit($request, $pool, 'database_pool_created');

        return response()->json([
            'message' => "Database pool '{$pool->name}' created.",
            'data' => $this->presentPool($pool),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $pool = DatabasePool::query()->whereNull('deleted_at')->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('database_pools', 'name')->ignore($pool->id)],
            'description' => 'sometimes|nullable|string|max:2000',
            'engine' => ['sometimes', Rule::in([DatabasePool::ENGINE_PGSQL, DatabasePool::ENGINE_MYSQL])],
            'host' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'provisioner_user' => 'sometimes|string|max:64',
            'provisioner_password' => 'sometimes|nullable|string',  // empty = keep existing
            'sslmode' => 'sometimes|nullable|string|max:20',
            'ca_cert' => 'sometimes|nullable|string',
            'region' => 'sometimes|nullable|string|max:40',
            'status' => ['sometimes', Rule::in([DatabasePool::STATUS_ACTIVE, DatabasePool::STATUS_DISABLED, DatabasePool::STATUS_DRAINING])],
            'max_tenants' => 'sometimes|nullable|integer|min:0',
            'metadata' => 'sometimes|nullable|array',
        ]);

        // Plain fields
        foreach (['name', 'description', 'engine', 'host', 'port', 'provisioner_user', 'sslmode', 'region', 'status', 'max_tenants', 'metadata'] as $key) {
            if (array_key_exists($key, $data)) {
                $pool->$key = $data[$key];
            }
        }

        // Secret fields: only update when caller submitted a non-empty value
        if (!empty($data['provisioner_password'])) {
            $pool->provisioner_password = $data['provisioner_password'];
        }
        if (array_key_exists('ca_cert', $data)) {
            // Allow explicit clear by sending null
            if ($data['ca_cert'] === null) {
                $pool->setRawAttributes(['ca_cert' => null] + $pool->getAttributes());
            } elseif ($data['ca_cert'] !== '') {
                $pool->ca_cert = $data['ca_cert'];
            }
        }

        $pool->save();

        $this->audit($request, $pool, 'database_pool_updated');

        return response()->json([
            'message' => "Database pool '{$pool->name}' updated.",
            'data' => $this->presentPool($pool),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $pool = DatabasePool::query()->whereNull('deleted_at')->findOrFail($id);

        if ($pool->current_tenants_count > 0) {
            return response()->json([
                'message' => "Cannot delete: pool still has {$pool->current_tenants_count} tenant(s) assigned. Migrate them first or set status to 'draining'.",
            ], 422);
        }

        $pool->delete();
        $this->audit($request, $pool, 'database_pool_deleted');

        return response()->json(['message' => "Database pool '{$pool->name}' deleted."]);
    }

    /**
     * Probe a connection config without persisting. The "Test Connection"
     * button in the form. If `id` is provided in the body and password is
     * empty, fall back to the saved pool's credentials so the user doesn't
     * have to re-enter secrets.
     */
    public function testConnection(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|uuid',
            'engine' => ['required', Rule::in([DatabasePool::ENGINE_PGSQL, DatabasePool::ENGINE_MYSQL])],
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'provisioner_user' => 'required|string|max:64',
            'provisioner_password' => 'nullable|string',
            'sslmode' => 'nullable|string|max:20',
            'ca_cert' => 'nullable|string',
        ]);

        $password = $data['provisioner_password'] ?? null;
        if (empty($password) && !empty($data['id'])) {
            $existing = DatabasePool::query()->find($data['id']);
            if ($existing) $password = $existing->provisioner_password;
        }
        if (empty($password)) {
            return response()->json(['success' => false, 'message' => 'Password required (or pool id with saved password).'], 422);
        }

        $config = [
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['engine'] === 'pgsql' ? 'postgres' : 'mysql',
            'username' => $data['provisioner_user'],
            'password' => $password,
            'sslmode' => $data['sslmode'] ?? 'require',
        ];

        $result = $this->dbService->testConnectionWithConfig($data['engine'], $config);
        return response()->json($result);
    }

    /**
     * Render a pool with secrets masked. Never returns the decrypted
     * provisioner_password / ca_cert in the API response.
     */
    private function presentPool(DatabasePool $pool): array
    {
        return [
            'id' => $pool->id,
            'name' => $pool->name,
            'description' => $pool->description,
            'engine' => $pool->engine,
            'host' => $pool->host,
            'port' => $pool->port,
            'provisioner_user' => $pool->provisioner_user,
            'has_provisioner_password' => !empty($pool->getRawOriginal('provisioner_password')),
            'has_ca_cert' => !empty($pool->getRawOriginal('ca_cert')),
            'sslmode' => $pool->sslmode,
            'region' => $pool->region,
            'status' => $pool->status,
            'max_tenants' => $pool->max_tenants,
            'current_tenants_count' => $pool->current_tenants_count,
            'is_accepting_tenants' => $pool->isAcceptingTenants(),
            'metadata' => $pool->metadata,
            'created_at' => $pool->created_at,
            'updated_at' => $pool->updated_at,
        ];
    }

    private function audit(Request $request, DatabasePool $pool, string $action): void
    {
        try {
            AuditLog::log('database_pool', $pool->id, $action, [
                'name' => $pool->name,
                'engine' => $pool->engine,
                'host' => $pool->host,
            ], 'manual');
        } catch (\Exception $e) {
            // best-effort
        }
    }
}
