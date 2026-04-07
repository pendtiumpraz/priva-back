<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class StorageSettingsController extends Controller
{
    protected TenantStorageService $storageService;

    public function __construct(TenantStorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    /**
     * Get current storage config (with masked secrets).
     */
    public function show(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);

        $result = [
            'driver' => $org->storage_driver,
            'config' => null,
            'usage'  => $this->storageService->getUsageSummary($org),
        ];

        if ($org->storage_config) {
            try {
                $config = json_decode(Crypt::decryptString($org->storage_config), true);
                // Mask sensitive fields
                $result['config'] = $this->maskConfig($config);
            } catch (\Exception $e) {
                $result['config'] = ['error' => 'Gagal mendekripsi konfigurasi'];
            }
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Save/update storage config.
     */
    public function update(Request $request)
    {
        $request->validate([
            'driver' => 'required|string|in:s3,minio,do_spaces,gcs,default',
            'config' => 'nullable|array',
            'config.key' => 'nullable|string',
            'config.secret' => 'nullable|string',
            'config.bucket' => 'nullable|string',
            'config.region' => 'nullable|string',
            'config.endpoint' => 'nullable|string',
            'config.project_id' => 'nullable|string',
            'config.key_file' => 'nullable|string',
            'config.use_path_style_endpoint' => 'nullable|boolean',
        ]);

        $org = Organization::findOrFail($request->user()->org_id);

        if ($request->driver === 'default') {
            // Reset to default
            $org->update([
                'storage_driver' => null,
                'storage_config' => null,
            ]);

            return response()->json(['message' => 'Storage direset ke default server.']);
        }

        // If updating, merge with existing config (so user can update partially without re-entering secrets)
        $newConfig = $request->config ?? [];

        if ($org->storage_config) {
            try {
                $existingConfig = json_decode(Crypt::decryptString($org->storage_config), true) ?? [];
                // Only update fields that are provided and non-empty
                foreach ($newConfig as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $existingConfig[$key] = $value;
                    }
                }
                $newConfig = $existingConfig;
            } catch (\Exception $e) {
                // Can't decrypt old config, use new config entirely
            }
        }

        $org->update([
            'storage_driver' => $request->driver,
            'storage_config' => Crypt::encryptString(json_encode($newConfig)),
        ]);

        try {
            \App\Models\AuditLog::log('storage', $org->id, 'storage_config_updated', [
                'driver' => $request->driver,
            ], 'manual');
        } catch (\Exception $e) {}

        return response()->json(['message' => "Storage dikonfigurasi ke {$request->driver}."]);
    }

    /**
     * Test connection to the configured storage.
     */
    public function testConnection(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);

        // Allow testing with unsaved config
        if ($request->has('driver') && $request->has('config')) {
            $tempOrg = clone $org;
            $tempOrg->storage_driver = $request->driver;
            $tempOrg->storage_config = Crypt::encryptString(json_encode($request->config));
            $result = $this->storageService->testConnection($tempOrg);
        } else {
            $result = $this->storageService->testConnection($org);
        }

        return response()->json($result);
    }

    /**
     * Reset to default storage.
     */
    public function destroy(Request $request)
    {
        $org = Organization::findOrFail($request->user()->org_id);

        $org->update([
            'storage_driver' => null,
            'storage_config' => null,
        ]);

        try {
            \App\Models\AuditLog::log('storage', $org->id, 'storage_config_reset', [], 'manual');
        } catch (\Exception $e) {}

        return response()->json(['message' => 'Storage direset ke default server.']);
    }

    /**
     * Mask sensitive config values for display.
     */
    private function maskConfig(?array $config): ?array
    {
        if (!$config) return null;

        $masked = $config;
        $sensitiveKeys = ['secret', 'key', 'key_file', 'password'];

        foreach ($sensitiveKeys as $sk) {
            if (isset($masked[$sk]) && is_string($masked[$sk]) && strlen($masked[$sk]) > 8) {
                $val = $masked[$sk];
                $masked[$sk] = substr($val, 0, 4) . str_repeat('•', min(20, strlen($val) - 8)) . substr($val, -4);
            }
        }

        return $masked;
    }
}
