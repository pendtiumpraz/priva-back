<?php

namespace App\Services\Embedding;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pengelola artefak model embedding: unduh, cermin ke blob, deteksi
 * keberadaan, dan penetapan model/mode aktif.
 *
 * Dua tujuan penyimpanan yang saling melengkapi:
 *   - LOKAL : direktori di VPS yang dibaca sidecar ONNX (config
 *             embedding_models.local.dir). Wajib ada agar mode `local` jalan.
 *   - BLOB  : Vercel Blob sebagai sumber bersama. Sidecar mengunduh dari sini
 *             saat start, jadi satu kali unggah dipakai semua instans.
 *
 * Urutan sumber saat mengisi lokal: bila artefak sudah ada di blob, salin
 * dari blob (dekat, tanpa egress ke HuggingFace); bila belum, ambil dari
 * HuggingFace. Itu sebabnya tombol lokal di UI berubah label menjadi
 * "Ambil dari Blob" ketika blob sudah terisi.
 *
 * Status unduhan disimpan di cache (bukan tabel) karena bersifat sementara
 * dan hanya dipakai untuk polling progres di UI.
 */
class EmbeddingModelManager
{
    public const MODES = ['local', 'blob', 'api'];

    private const SETTING_MODE = 'embedding.mode';

    private const SETTING_ACTIVE = 'embedding.active_model';

    private const PROGRESS_TTL = 3600;

    public function __construct(private VercelBlobClient $blob) {}

    // ------------------------------------------------------------------
    // Katalog & metadata
    // ------------------------------------------------------------------

    /**
     * Seluruh model di katalog.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        $catalog = config('embedding_models.catalog', []);

        return is_array($catalog) ? array_values($catalog) : [];
    }

    /**
     * Metadata satu model.
     *
     * @return array<string, mixed>
     */
    public function model(string $modelId): array
    {
        foreach ($this->catalog() as $model) {
            if (($model['id'] ?? null) === $modelId) {
                return $model;
            }
        }

        throw new InvalidArgumentException("Model embedding tidak dikenal: {$modelId}");
    }

    // ------------------------------------------------------------------
    // Mode & model aktif
    // ------------------------------------------------------------------

    public function mode(): string
    {
        $stored = AppSetting::get(self::SETTING_MODE);
        $mode = is_string($stored) && $stored !== ''
            ? $stored
            : (string) config('embedding_models.mode', 'blob');

        return in_array($mode, self::MODES, true) ? $mode : 'blob';
    }

    public function setMode(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Mode embedding tidak valid: {$mode}");
        }

        AppSetting::set(self::SETTING_MODE, $mode);
    }

    public function activeModelId(): string
    {
        $stored = AppSetting::get(self::SETTING_ACTIVE);
        $id = is_string($stored) && $stored !== ''
            ? $stored
            : (string) config('embedding_models.active', 'minilm-l6-v2');

        // Bila katalog berubah dan model tersimpan tidak ada lagi, jatuh ke
        // model pertama supaya layanan tidak mati total.
        foreach ($this->catalog() as $model) {
            if (($model['id'] ?? null) === $id) {
                return $id;
            }
        }

        return (string) ($this->catalog()[0]['id'] ?? 'minilm-l6-v2');
    }

    /**
     * Tetapkan model aktif. Menolak model yang artefaknya belum tersedia di
     * tujuan sesuai mode saat ini — mengaktifkan model yang berkasnya belum
     * ada hanya akan membuat sidecar gagal saat permintaan pertama.
     */
    public function activate(string $modelId): void
    {
        $model = $this->model($modelId);
        $mode = $this->mode();

        if ($mode === 'local' && ! $this->existsLocally($modelId)) {
            throw new RuntimeException(
                "Model {$model['label']} belum diunduh ke VPS. Unduh dulu sebelum dipakai pada mode local."
            );
        }

        if ($mode === 'blob' && ! $this->existsInBlob($modelId)) {
            throw new RuntimeException(
                "Model {$model['label']} belum ada di blob. Unggah dulu sebelum dipakai pada mode blob."
            );
        }

        AppSetting::set(self::SETTING_ACTIVE, $modelId);
    }

    /**
     * Konfigurasi model aktif yang dipakai EmbeddingService dan sidecar.
     *
     * @return array<string, mixed>
     */
    public function activeConfig(): array
    {
        $model = $this->model($this->activeModelId());
        $mode = $this->mode();

        return [
            'mode' => $mode,
            'model_id' => $model['id'],
            'repo' => $model['repo'],
            'dimension' => (int) $model['dimension'],
            'pooling' => $model['pooling'],
            'normalize' => (bool) $model['normalize'],
            'prefix' => $model['prefix'],
            'max_tokens' => (int) $model['max_tokens'],
            'source' => match ($mode) {
                'local' => ['kind' => 'local', 'dir' => $this->localDir($model['id'])],
                'blob' => ['kind' => 'blob', 'files' => $this->blobUrls($model['id'])],
                default => ['kind' => 'api'],
            },
        ];
    }

    // ------------------------------------------------------------------
    // Deteksi keberadaan
    // ------------------------------------------------------------------

    public function localDir(string $modelId): string
    {
        $base = rtrim((string) config('embedding_models.local.dir'), '/\\');

        return $base.DIRECTORY_SEPARATOR.$modelId;
    }

    public function existsLocally(string $modelId): bool
    {
        $model = $this->model($modelId);
        $dir = $this->localDir($modelId);

        foreach ((array) $model['files'] as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (! is_file($path) || filesize($path) === 0) {
                return false;
            }
        }

        return true;
    }

    public function localBytes(string $modelId): int
    {
        $model = $this->model($modelId);
        $dir = $this->localDir($modelId);
        $total = 0;

        foreach ((array) $model['files'] as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (is_file($path)) {
                $total += (int) (filesize($path) ?: 0);
            }
        }

        return $total;
    }

    public function blobPrefix(string $modelId): string
    {
        $prefix = trim((string) config('embedding_models.blob.prefix', 'embedding-models'), '/');

        return $prefix.'/'.$modelId;
    }

    /**
     * Daftar blob milik satu model, dipetakan nama berkas => info.
     *
     * Hasil di-cache singkat supaya membuka halaman pengaturan tidak
     * memanggil API blob berkali-kali untuk tiap model.
     *
     * @return array<string, array{url: string, size: int}>
     */
    public function blobFiles(string $modelId, bool $fresh = false): array
    {
        if (! $this->blob->isConfigured()) {
            return [];
        }

        $cacheKey = "embedding.blob.files.{$modelId}";
        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 60, function () use ($modelId) {
            $prefix = $this->blobPrefix($modelId).'/';

            try {
                $blobs = $this->blob->list($prefix);
            } catch (\Throwable $e) {
                Log::warning('[EmbeddingModelManager] gagal membaca daftar blob', [
                    'model' => $modelId,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }

            $out = [];
            foreach ($blobs as $b) {
                $name = substr($b['pathname'], strlen($prefix));
                if ($name !== '' && $name !== false) {
                    $out[$name] = ['url' => $b['url'], 'size' => $b['size']];
                }
            }

            return $out;
        });
    }

    public function existsInBlob(string $modelId, bool $fresh = false): bool
    {
        $model = $this->model($modelId);
        $present = $this->blobFiles($modelId, $fresh);

        foreach ((array) $model['files'] as $file) {
            if (! isset($present[$file]) || $present[$file]['size'] <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * URL blob per berkas untuk model tertentu.
     *
     * @return array<string, string>
     */
    public function blobUrls(string $modelId): array
    {
        return array_map(
            static fn (array $f) => $f['url'],
            $this->blobFiles($modelId)
        );
    }

    public function blobBytes(string $modelId): int
    {
        return array_sum(array_column($this->blobFiles($modelId), 'size'));
    }

    // ------------------------------------------------------------------
    // Ringkasan status untuk UI
    // ------------------------------------------------------------------

    /**
     * Status seluruh katalog: keberadaan per tujuan, progres unduhan yang
     * sedang berjalan, dan aksi apa yang sedang mungkin dilakukan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusAll(bool $fresh = false): array
    {
        $activeId = $this->activeModelId();
        $mode = $this->mode();
        $blobReady = $this->blob->isConfigured();

        $out = [];
        foreach ($this->catalog() as $model) {
            $id = (string) $model['id'];
            $inLocal = $this->existsLocally($id);
            $inBlob = $blobReady && $this->existsInBlob($id, $fresh);

            $out[] = [
                'id' => $id,
                'label' => $model['label'],
                'repo' => $model['repo'],
                'dimension' => (int) $model['dimension'],
                'max_tokens' => (int) $model['max_tokens'],
                'size_label' => $model['size_label'],
                'size_bytes' => (int) $model['size_bytes'],
                'languages' => $model['languages'],
                'multilingual' => (bool) $model['multilingual'],
                'recommended' => (bool) $model['recommended'],
                'summary' => $model['summary'],
                'caveat' => $model['caveat'],
                'pooling' => $model['pooling'],
                'files' => array_values((array) $model['files']),

                'in_local' => $inLocal,
                'in_blob' => $inBlob,
                'local_bytes' => $inLocal ? $this->localBytes($id) : 0,
                'blob_bytes' => $inBlob ? $this->blobBytes($id) : 0,
                'is_active' => $id === $activeId,

                // Sumber yang akan dipakai bila tombol unduh lokal ditekan.
                // Blob lebih diutamakan: lebih cepat dan tanpa egress ke HF.
                'local_source' => $inBlob ? 'blob' : 'huggingface',

                'progress' => $this->progress($id),

                // Aturan tombol, dihitung di server supaya UI tidak
                // menduplikasi logikanya.
                'can_download_local' => ! $inLocal,
                'can_download_blob' => $blobReady && ! $inBlob,
                'can_activate' => match ($mode) {
                    'local' => $inLocal,
                    'blob' => $inBlob,
                    default => true,
                },
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Progres unduhan
    // ------------------------------------------------------------------

    private function progressKey(string $modelId): string
    {
        return "embedding.download.{$modelId}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public function progress(string $modelId): ?array
    {
        $state = Cache::get($this->progressKey($modelId));

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function setProgress(string $modelId, array $state): void
    {
        Cache::put($this->progressKey($modelId), $state, self::PROGRESS_TTL);
    }

    public function clearProgress(string $modelId): void
    {
        Cache::forget($this->progressKey($modelId));
    }

    public function isBusy(string $modelId): bool
    {
        $p = $this->progress($modelId);

        return is_array($p) && ($p['status'] ?? null) === 'running';
    }

    // ------------------------------------------------------------------
    // Unduhan
    // ------------------------------------------------------------------

    /**
     * Isi direktori lokal untuk satu model. Mengambil dari blob bila
     * artefaknya sudah ada di sana, selain itu dari HuggingFace.
     *
     * @return array{source: string, bytes: int, files: int}
     */
    public function downloadToLocal(string $modelId): array
    {
        $model = $this->model($modelId);
        $files = array_values((array) $model['files']);
        $dir = $this->localDir($modelId);

        $blobFiles = $this->blobFiles($modelId, true);
        $useBlob = $this->existsInBlob($modelId);
        $source = $useBlob ? 'blob' : 'huggingface';

        $this->setProgress($modelId, [
            'status' => 'running', 'target' => 'local', 'source' => $source,
            'done' => 0, 'total' => count($files), 'file' => null, 'bytes' => 0,
        ]);

        $bytes = 0;

        try {
            foreach ($files as $i => $file) {
                $this->setProgress($modelId, [
                    'status' => 'running', 'target' => 'local', 'source' => $source,
                    'done' => $i, 'total' => count($files), 'file' => $file, 'bytes' => $bytes,
                ]);

                $destination = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);

                if ($useBlob && isset($blobFiles[$file])) {
                    $bytes += $this->blob->download($blobFiles[$file]['url'], $destination);
                } else {
                    $bytes += $this->downloadFromHuggingFace($model, $file, $destination);
                }
            }
        } catch (\Throwable $e) {
            $this->setProgress($modelId, [
                'status' => 'failed', 'target' => 'local', 'source' => $source,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->setProgress($modelId, [
            'status' => 'done', 'target' => 'local', 'source' => $source,
            'done' => count($files), 'total' => count($files), 'bytes' => $bytes,
        ]);

        return ['source' => $source, 'bytes' => $bytes, 'files' => count($files)];
    }

    /**
     * Unggah artefak model ke Vercel Blob. Berkas yang belum ada di disk
     * diambil dulu dari HuggingFace ke direktori sementara.
     *
     * @return array{bytes: int, files: int}
     */
    public function uploadToBlob(string $modelId): array
    {
        if (! $this->blob->isConfigured()) {
            throw new RuntimeException('Token Vercel Blob belum diset. Isi priva_READ_WRITE_TOKEN di .env backend.');
        }

        $model = $this->model($modelId);
        $files = array_values((array) $model['files']);
        $prefix = $this->blobPrefix($modelId);
        $localDir = $this->localDir($modelId);

        $this->setProgress($modelId, [
            'status' => 'running', 'target' => 'blob', 'source' => 'huggingface',
            'done' => 0, 'total' => count($files), 'file' => null, 'bytes' => 0,
        ]);

        $bytes = 0;
        $temporary = [];

        try {
            foreach ($files as $i => $file) {
                $this->setProgress($modelId, [
                    'status' => 'running', 'target' => 'blob', 'source' => 'huggingface',
                    'done' => $i, 'total' => count($files), 'file' => $file, 'bytes' => $bytes,
                ]);

                $localPath = $localDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);

                // Sudah ada di VPS → unggah langsung, tidak perlu ambil ulang.
                if (! is_file($localPath) || filesize($localPath) === 0) {
                    $localPath = $this->temporaryPath($modelId, $file);
                    $temporary[] = $localPath;
                    $this->downloadFromHuggingFace($model, $file, $localPath);
                }

                $this->blob->putFile(
                    $prefix.'/'.$file,
                    $localPath,
                    $this->contentTypeFor($file)
                );

                $bytes += (int) (filesize($localPath) ?: 0);
            }
        } catch (\Throwable $e) {
            $this->setProgress($modelId, [
                'status' => 'failed', 'target' => 'blob', 'error' => $e->getMessage(),
            ]);
            $this->cleanUp($temporary);

            throw $e;
        }

        $this->cleanUp($temporary);
        Cache::forget("embedding.blob.files.{$modelId}");

        $this->setProgress($modelId, [
            'status' => 'done', 'target' => 'blob', 'source' => 'huggingface',
            'done' => count($files), 'total' => count($files), 'bytes' => $bytes,
        ]);

        return ['bytes' => $bytes, 'files' => count($files)];
    }

    /** Hapus artefak lokal satu model. */
    public function removeLocal(string $modelId): void
    {
        $model = $this->model($modelId);
        $dir = $this->localDir($modelId);

        foreach ((array) $model['files'] as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        // Buang direktori kosong yang tersisa (mis. subfolder onnx/).
        foreach ([$dir.DIRECTORY_SEPARATOR.'onnx', $dir] as $candidate) {
            if (is_dir($candidate) && count((array) scandir($candidate)) <= 2) {
                @rmdir($candidate);
            }
        }
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $model
     */
    private function downloadFromHuggingFace(array $model, string $file, string $destination): int
    {
        $base = rtrim((string) config('embedding_models.hf_base_url', 'https://huggingface.co'), '/');
        $url = sprintf('%s/%s/resolve/%s/%s', $base, $model['repo'], $model['revision'] ?? 'main', $file);

        $dir = dirname($destination);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Gagal membuat direktori: {$dir}");
        }

        $response = Http::timeout((int) config('embedding_models.download_timeout', 900))
            ->withOptions(['allow_redirects' => true])
            ->sink($destination)
            ->get($url);

        if (! $response->successful()) {
            @unlink($destination);
            throw new RuntimeException("Unduhan dari HuggingFace gagal untuk {$file} (HTTP {$response->status()})");
        }

        $size = (int) (filesize($destination) ?: 0);
        if ($size === 0) {
            @unlink($destination);
            throw new RuntimeException("Berkas {$file} terunduh kosong");
        }

        return $size;
    }

    private function temporaryPath(string $modelId, string $file): string
    {
        $path = storage_path('app/tmp/embedding-models/'.$modelId.'/'.$file);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $path;
    }

    /** @param array<int, string> $paths */
    private function cleanUp(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function contentTypeFor(string $file): string
    {
        return str_ends_with($file, '.json') ? 'application/json' : 'application/octet-stream';
    }
}
