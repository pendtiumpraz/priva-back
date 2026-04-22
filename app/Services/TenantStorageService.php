<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantStorageService
{
    /**
     * Resolve the filesystem disk for a given tenant.
     * If tenant has custom storage config, creates an on-the-fly disk.
     * Otherwise, falls back to the server default disk.
     */
    public function getDisk(Organization $org): FilesystemAdapter
    {
        if (!$org->storage_driver || !$org->storage_config) {
            return Storage::disk(config('filesystems.default'));
        }

        try {
            $config = json_decode(Crypt::decryptString($org->storage_config), true);
        } catch (\Exception $e) {
            \Log::warning("TenantStorage: Failed to decrypt config for org {$org->id}, falling back to default.");
            return Storage::disk(config('filesystems.default'));
        }

        if (!$config || !is_array($config)) {
            return Storage::disk(config('filesystems.default'));
        }

        $driver = $org->storage_driver;

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

        if (!$diskConfig) {
            return Storage::disk(config('filesystems.default'));
        }

        return Storage::build($diskConfig);
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
     * - If tenant has cloud config (S3/MinIO/GCS): use that (always URL-capable).
     * - Otherwise: fall back to Laravel's `public` disk (served via storage:link).
     *   NOT `filesystems.default` (usually `local` which has no public URL).
     */
    public function getPublicDisk(Organization $org): FilesystemAdapter
    {
        if ($org->storage_driver && $org->storage_config) {
            return $this->getDisk($org);
        }
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
        $disk = $org->storage_driver && $org->storage_config
            ? $this->getDisk($org)
            : Storage::disk('local');

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $name = $filename ?: (pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '_' . bin2hex(random_bytes(4)) . '.' . $ext);
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        $basePath = "tenants/{$org->id}/" . trim($directory, '/') . '/';
        $path = $basePath . $name;

        $disk->put($path, file_get_contents($file->getRealPath()));

        return [
            'path'   => $path,
            'driver' => $org->storage_driver ?: 'local',
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
        $disk = $this->getDisk($org);
        $usesCloud = $org->storage_driver && in_array($org->storage_driver, ['s3', 'minio', 'do_spaces', 'gcs'], true);

        if (!$usesCloud) {
            return [$disk->path($path), function () { /* noop */ }];
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
     */
    public function testConnection(Organization $org): array
    {
        if (!$org->storage_driver || !$org->storage_config) {
            return ['success' => true, 'message' => 'Menggunakan storage default server', 'driver' => 'default'];
        }

        try {
            $disk = $this->getDisk($org);
            $testPath = "tenants/{$org->id}/.connection_test";

            // Write test file
            $disk->put($testPath, 'privasimu_connection_test_' . now()->toIso8601String());

            // Read it back
            $contents = $disk->get($testPath);

            // Clean up
            $disk->delete($testPath);

            if ($contents && str_starts_with($contents, 'privasimu_connection_test_')) {
                return [
                    'success' => true,
                    'message' => "Berhasil terhubung ke {$org->storage_driver}",
                    'driver' => $org->storage_driver,
                ];
            }

            return ['success' => false, 'message' => 'File test berhasil ditulis tapi gagal dibaca kembali.'];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Gagal terhubung: ' . $e->getMessage(),
                'driver' => $org->storage_driver,
            ];
        }
    }

    /**
     * Get storage usage summary for a tenant.
     */
    public function getUsageSummary(Organization $org): array
    {
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
                'driver' => $org->storage_driver ?? 'default',
            ];
        } catch (\Exception $e) {
            return [
                'file_count' => 0,
                'total_size_bytes' => 0,
                'total_size_human' => '0 B',
                'driver' => $org->storage_driver ?? 'default',
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
