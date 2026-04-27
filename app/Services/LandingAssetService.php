<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload + persist asset publik untuk landing page (hero, features, team, dll).
 *
 * Berbeda dari TenantStorageService — landing tidak per-tenant, semua disimpan
 * di public disk (`storage/app/public/landing/{type}/`) dan diserve via
 * `/storage/landing/...` (butuh `php artisan storage:link` sekali).
 */
class LandingAssetService
{
    /**
     * Subfolder yang valid + max size MB. Klien only bisa upload ke salah satu.
     */
    public const TYPES = [
        'hero' => 5,
        'features' => 5,
        'team' => 3,
        'testimonials' => 3,
        'logos' => 2,
        'products' => 5,
        'misc' => 5,
    ];

    /**
     * Store uploaded file ke public/landing/{type}/.
     * Return relative path (e.g. "landing/team/abc123_anto.webp") yang
     * bisa langsung disimpan di kolom *_path.
     */
    public function store(UploadedFile $file, string $type): string
    {
        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown landing asset type: {$type}");
        }

        $maxBytes = self::TYPES[$type] * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException('File terlalu besar. Max '.self::TYPES[$type].' MB untuk '.$type);
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($ext, $allowed, true)) {
            throw new \InvalidArgumentException('Format file tidak didukung. Gunakan: '.implode(', ', $allowed));
        }

        $name = Str::random(12).'_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$ext;
        $path = "landing/{$type}/{$name}";

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Delete asset file. Best-effort: tidak throw kalau file sudah hilang.
     */
    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            \Log::warning("LandingAssetService delete failed for {$path}: ".$e->getMessage());
        }
    }

    /**
     * Resolve disk path → absolute URL (relative to APP_URL).
     */
    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
