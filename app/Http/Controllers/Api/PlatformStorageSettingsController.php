<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform-wide default storage configuration. Root/superadmin only.
 *
 * Resolution order applied at runtime by TenantStorageService::getDisk():
 *   1. Tenant override (organizations.storage_driver + storage_config)
 *   2. Platform default (managed here, in app_settings)
 *   3. Laravel default disk (config/filesystems.php)
 *
 * Settings keys touched:
 *   platform.storage.driver  — 's3' | 'minio' | 'do_spaces' | 'gcs' | null
 *   platform.storage.config  — encrypted JSON of credentials/bucket
 */
class PlatformStorageSettingsController extends Controller
{
    public function __construct(protected TenantStorageService $storageService) {}

    public function show(Request $request)
    {
        $driver = AppSetting::get('platform.storage.driver');
        $configRaw = AppSetting::get('platform.storage.config');

        $config = null;
        if ($configRaw) {
            try {
                $config = json_decode(Crypt::decryptString($configRaw), true);
                $config = $this->maskConfig($config);
            } catch (\Exception $e) {
                $config = ['error' => 'Gagal mendekripsi konfigurasi'];
            }
        }

        return response()->json([
            'data' => [
                'driver' => $driver ?: null,
                'config' => $config,
                'is_active' => $driver && $driver !== 'default' && $configRaw,
                'note' => 'Konfigurasi ini berlaku sebagai default untuk SEMUA tenant yang belum mengatur storage sendiri. Tenant yang sudah punya konfigurasi storage akan mengabaikan setting ini.',
            ],
        ]);
    }

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

        if ($request->driver === 'default') {
            AppSetting::set('platform.storage.driver', null);
            AppSetting::set('platform.storage.config', null);

            $this->audit($request, 'platform_storage_reset', ['driver' => 'default']);

            return response()->json(['message' => 'Platform storage direset ke server default. Tenant tanpa override akan kembali pakai Laravel local disk.']);
        }

        // Merge with existing config so partial update doesn't wipe secrets.
        $newConfig = $request->config ?? [];
        $existingRaw = AppSetting::get('platform.storage.config');
        if ($existingRaw) {
            try {
                $existing = json_decode(Crypt::decryptString($existingRaw), true) ?? [];
                foreach ($newConfig as $k => $v) {
                    if ($v !== null && $v !== '') $existing[$k] = $v;
                }
                $newConfig = $existing;
            } catch (\Exception $e) {
                // Decrypt failed — start fresh with submitted values.
            }
        }

        AppSetting::set('platform.storage.driver', $request->driver);
        AppSetting::set('platform.storage.config', Crypt::encryptString(json_encode($newConfig)));

        $this->audit($request, 'platform_storage_updated', ['driver' => $request->driver]);

        return response()->json([
            'message' => "Platform storage default di-set ke {$request->driver}. Tenant tanpa override sekarang pakai konfigurasi ini.",
        ]);
    }

    public function testConnection(Request $request)
    {
        $request->validate([
            'driver' => 'required|string|in:s3,minio,do_spaces,gcs,default',
            'config' => 'nullable|array',
        ]);

        // If config not submitted, test the saved one (so user can verify
        // an existing platform config without re-entering secrets).
        $config = $request->config ?? [];
        if (empty($config)) {
            $existingRaw = AppSetting::get('platform.storage.config');
            if ($existingRaw) {
                try {
                    $config = json_decode(Crypt::decryptString($existingRaw), true) ?? [];
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Gagal mendekripsi konfigurasi tersimpan.']);
                }
            }
        } else {
            // Merge with existing so masked fields still work
            $existingRaw = AppSetting::get('platform.storage.config');
            if ($existingRaw) {
                try {
                    $existing = json_decode(Crypt::decryptString($existingRaw), true) ?? [];
                    foreach ($config as $k => $v) {
                        if ($v !== null && $v !== '') $existing[$k] = $v;
                    }
                    $config = $existing;
                } catch (\Exception $e) {}
            }
        }

        $result = $this->storageService->testConnectionWithConfig($request->driver, $config);

        return response()->json($result);
    }

    public function destroy(Request $request)
    {
        AppSetting::set('platform.storage.driver', null);
        AppSetting::set('platform.storage.config', null);

        $this->audit($request, 'platform_storage_reset', []);

        return response()->json(['message' => 'Platform storage default dihapus.']);
    }

    private function maskConfig(?array $config): ?array
    {
        if (!$config) return null;
        $masked = $config;
        $sensitive = ['secret', 'key', 'key_file', 'password'];
        foreach ($sensitive as $sk) {
            if (isset($masked[$sk]) && is_string($masked[$sk]) && strlen($masked[$sk]) > 8) {
                $val = $masked[$sk];
                $masked[$sk] = substr($val, 0, 4) . str_repeat('•', min(20, strlen($val) - 8)) . substr($val, -4);
            }
        }
        return $masked;
    }

    private function audit(Request $request, string $action, array $meta): void
    {
        try {
            $orgId = $request->user()?->org_id ?? 'platform';
            AuditLog::log('platform_storage', $orgId, $action, $meta, 'manual');
        } catch (\Exception $e) {
            // best-effort
        }
    }
}
