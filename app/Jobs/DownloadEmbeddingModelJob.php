<?php

namespace App\Jobs;

use App\Services\Embedding\EmbeddingModelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Unduh artefak model embedding ke tujuan tertentu (VPS lokal atau Vercel
 * Blob) di latar belakang.
 *
 * Dijalankan sebagai job karena berkas bisa mencapai ratusan MB — jauh di
 * atas batas waktu request HTTP yang wajar. Progresnya ditulis
 * EmbeddingModelManager ke cache, dan UI root menariknya lewat polling.
 *
 * Tidak ada percobaan ulang otomatis: unduhan separuh jalan lebih baik
 * ditinjau root (kuota, jaringan, token blob) daripada diulang membabi buta
 * dan menghabiskan bandwidth.
 */
class DownloadEmbeddingModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  string  $target  'local' atau 'blob'
     */
    public function __construct(
        public string $modelId,
        public string $target,
    ) {}

    public function handle(EmbeddingModelManager $manager): void
    {
        try {
            if ($this->target === 'blob') {
                $result = $manager->uploadToBlob($this->modelId);
            } else {
                $result = $manager->downloadToLocal($this->modelId);
            }

            Log::info('[DownloadEmbeddingModelJob] selesai', [
                'model' => $this->modelId,
                'target' => $this->target,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            // Progres gagal sudah dicatat manager; di sini cukup jejak log.
            Log::error('[DownloadEmbeddingModelJob] gagal', [
                'model' => $this->modelId,
                'target' => $this->target,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        app(EmbeddingModelManager::class)->setProgress($this->modelId, [
            'status' => 'failed',
            'target' => $this->target,
            'error' => $e->getMessage(),
        ]);
    }
}
