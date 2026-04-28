<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Organization;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantStorageService
{
    /**
     * Resolve the filesystem disk for a given tenant via 3-layer fallback:
     *   1. Tenant override (organizations.storage_driver + storage_config)
     *   2. Platform default (app_settings: platform.storage.driver + .config)
     *   3. Laravel default disk (config('filesystems.default'))
     *
     * Files are always written under `tenants/{$org->id}/` prefix regardless
     * of which layer resolved — that keeps cross-tenant isolation when layers
     * 2 & 3 share a single bucket/disk between tenants.
     */
    public function getDisk(Organization $org): FilesystemAdapter
    {
        // Layer 1: tenant override
        if ($org->storage_driver && $org->storage_config) {
            $config = $this->decryptConfig($org->storage_config, "org {$org->id}");
            if ($config !== null) {
                $disk = $this->buildDisk($org->storage_driver, $config);
                if ($disk !== null) return $disk;
            }
        }

        // Layer 2: platform default
        $platformDisk = $this->resolvePlatformDisk();
        if ($platformDisk !== null) return $platformDisk;

        // Layer 3: Laravel default disk
        return Storage::disk(config('filesystems.default'));
    }

    /**
     * Build a Flysystem disk on-the-fly from a driver + raw config array.
     * Returns null if driver is unknown so caller can fall through.
     */
    private function buildDisk(string $driver, array $config): ?FilesystemAdapter
    {
        $diskConfig = match ($driver) {
            's3', 'minio', 'do_spaces' => [
                'driver' => 's3',
                'key'    => $config['key'] ?? null,
                'secret' => $config['secret'] ?? null,
                'region' => $config['region'] ?? 'us-east-1',
                'bucket' => $config['bucket'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? ($driver !== 's3'),
                'throw' => false,
            ],
            'gcs' => [
                'driver' => 'gcs',
                'project_id' => $config['project_id'] ?? null,
                'key_file' => $config['key_file'] ?? null,  // JSON contents
                'bucket' => $config['bucket'] ?? null,
                'throw' => false,
            ],
            default => null,
        };

        if (!$diskConfig) return null;

        return Storage::build($diskConfig);
    }

    /**
     * Decrypt + decode a stored config blob. Returns null on failure.
     */
    private function decryptConfig(string $encrypted, string $contextLabel = ''): ?array
    {
        try {
            $config = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Exception $e) {
            \Log::warning("TenantStorage: Failed to decrypt config ({$contextLabel}); falling through.");
            return null;
        }
        return is_array($config) ? $config : null;
    }

    /**
     * Resolve platform-level storage settings to a disk, or null if unset/unusable.
     * Cached per-request via static map so concurrent calls within the same
     * request don't repeatedly hit AppSetting + Crypt.
     */
    private function resolvePlatformDisk(): ?FilesystemAdapter
    {
        static $cache = null;
        if ($cache !== null) return $cache === false ? null : $cache;

        $driver = AppSetting::get('platform.storage.driver');
        $configRaw = AppSetting::get('platform.storage.config');
        if (!$driver || !$configRaw || $driver === 'default') {
            $cache = false;
            return null;
        }

        $config = $this->decryptConfig($configRaw, 'platform');
        if ($config === null) {
            $cache = false;
            return null;
        }

        $disk = $this->buildDisk($driver, $config);
        $cache = $disk ?: false;
        return $disk;
    }

    /**
     * Whether the resolved disk for this org points at cloud storage (S3/GCS/etc),
     * considering the 3-layer fallback. Used to decide if a cloud-style download
     * to temp file is required for local-fs operations (PhpWord/OCR/etc).
     */
    private function resolvedDriver(Organization $org): string
    {
        if ($org->storage_driver && $org->storage_config) {
            return $org->storage_driver;
        }
        $platformDriver = AppSetting::get('platform.storage.driver');
        if ($platformDriver && $platformDriver !== 'default') {
            return $platformDriver;
        }
        return 'local';
    }

    private function isCloudDriver(string $driver): bool
    {
        return in_array($driver, ['s3', 'minio', 'do_spaces', 'gcs'], true);
    }

    /**
     * Store a file using the tenant's configured storage.
     */
    public function storeTenantFile(Organization $org, UploadedFile $file, ?string $directory = null): string
    {
        $disk = $this->getDisk($org);
        $basePath = "tenants/{$org->id}/" . ($directory ? trim($directory, '/') . '/' : '');
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $basePath . $filename;

        $disk->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Resolve a URL-generating disk for a tenant. Used for assets that must
     * be web-accessible (logos, watermarks, cover images).
     *
     * 3-layer fallback (mirrors getDisk):
     *   1. Tenant cloud config → always URL-capable
     *   2. Platform cloud config → always URL-capable
     *   3. Laravel `public` disk (served via storage:link). NOT `local` —
     *      local has no public URL.
     */
    public function getPublicDisk(Organization $org): FilesystemAdapter
    {
        if ($org->storage_driver && $org->storage_config) {
            return $this->getDisk($org);
        }
        $platformDisk = $this->resolvePlatformDisk();
        if ($platformDisk !== null) return $platformDisk;
        return Storage::disk('public');
    }

    /**
     * Store a publicly-accessible asset and return {path, url}.
     *
     * Uses tenant cloud storage when configured, else the Laravel `public` disk.
     * Caller provides a stable directory (e.g. "document-templates/watermark").
     */
    public function storePublicAsset(Organization $org, UploadedFile $file, string $directory, ?string $filename = null): array
    {
        $disk = $this->getPublicDisk($org);
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $name = $filename ?: (pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '_' . bin2hex(random_bytes(4)) . '.' . $ext);
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        $basePath = "tenants/{$org->id}/" . trim($directory, '/') . '/';
        $path = $basePath . $name;

        $disk->put($path, file_get_contents($file->getRealPath()), 'public');

        try {
            $url = $disk->url($path);
        } catch (\Throwable $e) {
            $url = $path;
        }

        return [
            'path' => $path,
            'url'  => $url,
            'driver' => $org->storage_driver ?: 'public',
        ];
    }

    /**
     * Store a private tenant file (evidence, uploaded contract for AI analysis, etc).
     * Returns {path, driver}. No public URL generated.
     *
     * Uses tenant cloud storage when configured, else Laravel's `local` disk.
     */
    public function storeTenantPrivateFile(Organization $org, UploadedFile $file, string $directory, ?string $filename = null): array
    {
        // Use 3-layer resolution. If neither tenant nor platform configured cloud
        // storage, the resolved disk is the Laravel default (typically `local`).
        $disk = $this->getDisk($org);

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $name = $filename ?: (pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '_' . bin2hex(random_bytes(4)) . '.' . $ext);
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        $basePath = "tenants/{$org->id}/" . trim($directory, '/') . '/';
        $path = $basePath . $name;

        $disk->put($path, file_get_contents($file->getRealPath()));

        return [
            'path'   => $path,
            'driver' => $this->resolvedDriver($org),
        ];
    }

    /**
     * Get a local filesystem path for processing (PhpWord/PhpSpreadsheet/OCR).
     *
     * - If tenant uses local/default disk: returns native ->path().
     * - If tenant uses cloud (S3/GCS/MinIO): downloads to a temp file and
     *   returns that path. Caller is responsible for unlinking the temp file
     *   after processing (second return value is a cleanup closure).
     *
     * Returns [localPath, cleanupClosure].
     */
    public function getLocalPathForProcessing(Organization $org, string $path): array
    {
        // System templates live on the server's `local` disk regardless of
        // whether the caller's tenant points at S3/MinIO/GCS. Resolving them
        // via $this->getDisk($org) would send a cloud-storage fetch for a
        // file that was never uploaded there (seeded only on local disk).
        if (str_starts_with($path, 'system-templates/')) {
            $local = Storage::disk('local');
            if (!$local->exists($path)) {
                throw new \RuntimeException("System template missing on server: {$path}");
            }
            return [$local->path($path), function () { /* noop */ }];
        }

        $disk = $this->getDisk($org);
        // Resolved driver respects 3-layer fallback — platform-level cloud
        // counts as "uses cloud" even when tenant has no override.
        $usesCloud = $this->isCloudDriver($this->resolvedDriver($org));

        if (!$usesCloud) {
            $absolute = $disk->path($path);
            if (!is_file($absolute)) {
                throw new \RuntimeException(
                    "Tenant file not found on local disk: {$path}. File mungkin belum ter-upload atau path tidak sinkron antar environment."
                );
            }
            return [$absolute, function () { /* noop */ }];
        }

        if (!$disk->exists($path)) {
            throw new \RuntimeException("Tenant cloud file not found: {$path}");
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'tmp';
        $tmp = tempnam(sys_get_temp_dir(), 'privasimu_') . '.' . $ext;
        file_put_contents($tmp, $disk->get($path));

        return [$tmp, function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); }];
    }

    /**
     * Get file contents from tenant storage.
     */
    public function getTenantFileContents(Organization $org, string $path): ?string
    {
        $disk = $this->getDisk($org);

        if (!$disk->exists($path)) {
            return null;
        }

        return $disk->get($path);
    }

    /**
     * Stream/download a file from tenant storage.
     */
    public function streamTenantFile(Organization $org, string $path, ?string $filename = null): StreamedResponse
    {
        $disk = $this->getDisk($org);
        $downloadName = $filename ?? basename($path);

        return $disk->download($path, $downloadName);
    }

    /**
     * Delete a file from tenant storage.
     */
    public function deleteTenantFile(Organization $org, string $path): bool
    {
        $disk = $this->getDisk($org);

        if ($disk->exists($path)) {
            return $disk->delete($path);
        }

        return false;
    }

    /**
     * Check if tenant storage is properly configured and accessible.
     * Reports which layer is in effect (tenant override / platform default / server default).
     */
    public function testConnection(Organization $org): array
    {
        $resolved = $this->resolvedDriver($org);
        $hasTenantOverride = $org->storage_driver && $org->storage_config;
        $hasPlatformDefault = !$hasTenantOverride && AppSetting::get('platform.storage.driver') && AppSetting::get('platform.storage.driver') !== 'default';

        // No cloud anywhere — using Laravel default disk (local/public).
        if ($resolved === 'local') {
            return ['success' => true, 'message' => 'Menggunakan storage default server (Laravel local)', 'driver' => 'default', 'layer' => 'server-default'];
        }

        try {
            $disk = $this->getDisk($org);
            $testPath = "tenants/{$org->id}/.connection_test";

            $disk->put($testPath, 'privasimu_connection_test_' . now()->toIso8601String());
            $contents = $disk->get($testPath);
            $disk->delete($testPath);

            $layer = $hasTenantOverride ? 'tenant-override' : ($hasPlatformDefault ? 'platform-default' : 'server-default');

            if ($contents && str_starts_with($contents, 'privasimu_connection_test_')) {
                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke {$resolved}" . ($layer === 'platform-default' ? ' (default platform)' : ''),
                    'driver' => $resolved,
                    'layer' => $layer,
                ];
            }

            return ['success' => false, 'message' => 'File test berhasil ditulis tapi gagal dibaca kembali.', 'layer' => $layer];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Gagal terhubung: ' . $e->getMessage(),
                'driver' => $resolved,
            ];
        }
    }

    /**
     * Test an arbitrary driver+config combo without persisting it. Used by
     * the per-tenant and platform-level "Test Connection" buttons before save.
     * The probe writes to a transient `_probe/...` path so it can't collide
     * with real tenant files.
     */
    public function testConnectionWithConfig(string $driver, array $config): array
    {
        if ($driver === 'default') {
            return ['success' => true, 'message' => 'Driver default — tidak perlu test koneksi', 'driver' => 'default'];
        }

        try {
            $disk = $this->buildDisk($driver, $config);
            if ($disk === null) {
                return ['success' => false, 'message' => "Driver '{$driver}' tidak dikenali", 'driver' => $driver];
            }

            $testPath = '_probe/connection_test_' . bin2hex(random_bytes(4));
            $disk->put($testPath, 'privasimu_connection_test_' . now()->toIso8601String());
            $contents = $disk->get($testPath);
            $disk->delete($testPath);

            if ($contents && str_starts_with($contents, 'privasimu_connection_test_')) {
                return ['success' => true, 'message' => "Berhasil terhubung ke {$driver}", 'driver' => $driver];
            }
            return ['success' => false, 'message' => 'File test berhasil ditulis tapi gagal dibaca kembali.', 'driver' => $driver];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Gagal terhubung: ' . $e->getMessage(), 'driver' => $driver];
        }
    }

    /**
     * Get storage usage summary for a tenant.
     */
    public function getUsageSummary(Organization $org): array
    {
        $resolved = $this->resolvedDriver($org);
        try {
            $disk = $this->getDisk($org);
            $prefix = "tenants/{$org->id}/";
            $files = $disk->allFiles($prefix);

            $totalSize = 0;
            foreach ($files as $file) {
                $totalSize += $disk->size($file);
            }

            return [
                'file_count' => count($files),
                'total_size_bytes' => $totalSize,
                'total_size_human' => $this->formatBytes($totalSize),
                'driver' => $resolved,
            ];
        } catch (\Exception $e) {
            return [
                'file_count' => 0,
                'total_size_bytes' => 0,
                'total_size_human' => '0 B',
                'driver' => $resolved,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
