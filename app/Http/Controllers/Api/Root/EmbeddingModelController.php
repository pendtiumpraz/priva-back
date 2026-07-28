<?php

namespace App\Http\Controllers\Api\Root;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadEmbeddingModelJob;
use App\Models\AuditLog;
use App\Services\Embedding\EmbeddingModelManager;
use App\Services\Embedding\VercelBlobClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bidang kendali model embedding — khusus root.
 *
 * Mengatur tiga hal: mode penyajian (local / blob / api), model mana yang
 * aktif, dan penyediaan artefaknya di tiap tujuan.
 *
 * Unduhan dijalankan sebagai job antrean. Bila antrean berjalan sinkron
 * (dev), pekerjaannya tetap selesai — hanya saja request-nya ikut menunggu.
 */
class EmbeddingModelController extends Controller
{
    public function __construct(
        private EmbeddingModelManager $manager,
        private VercelBlobClient $blob,
    ) {}

    /** Daftar model + status per tujuan + mode aktif. */
    public function index(Request $request)
    {
        $this->requireRoot($request);

        $fresh = $request->boolean('fresh');

        return response()->json([
            'mode' => $this->manager->mode(),
            'modes' => EmbeddingModelManager::MODES,
            'active_model' => $this->manager->activeModelId(),
            'blob_configured' => $this->blob->isConfigured(),
            'local_dir' => (string) config('embedding_models.local.dir'),
            'runtime_url' => (string) config('embedding_models.local.runtime_url'),
            'models' => $this->manager->statusAll($fresh),
        ]);
    }

    /** Ubah mode penyajian. */
    public function setMode(Request $request)
    {
        $this->requireRoot($request);

        $data = $request->validate([
            'mode' => 'required|string|in:'.implode(',', EmbeddingModelManager::MODES),
        ]);

        $previous = $this->manager->mode();
        $this->manager->setMode($data['mode']);

        $this->audit($request, 'embedding_mode_changed', [
            'from' => $previous,
            'to' => $data['mode'],
        ]);

        return response()->json([
            'message' => "Mode embedding diubah ke {$data['mode']}.",
            'mode' => $this->manager->mode(),
            'models' => $this->manager->statusAll(),
        ]);
    }

    /** Tetapkan model aktif. */
    public function activate(Request $request, string $modelId)
    {
        $this->requireRoot($request);

        try {
            $previous = $this->manager->activeModelId();
            $this->manager->activate($modelId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'embedding_model_activated', [
            'from' => $previous,
            'to' => $modelId,
            'mode' => $this->manager->mode(),
        ]);

        return response()->json([
            'message' => 'Model aktif diperbarui. Indeks lama perlu di-embed ulang karena ruang vektornya berbeda.',
            'active_model' => $this->manager->activeModelId(),
            'models' => $this->manager->statusAll(),
        ]);
    }

    /**
     * Antrekan penyediaan artefak.
     *
     * $target 'local' mengisi direktori VPS (dari blob bila sudah ada di
     * sana, selain itu dari HuggingFace); 'blob' mengunggah ke Vercel Blob.
     */
    public function download(Request $request, string $modelId, string $target)
    {
        $this->requireRoot($request);

        if (! in_array($target, ['local', 'blob'], true)) {
            return response()->json(['message' => "Tujuan unduhan tidak dikenal: {$target}"], 422);
        }

        try {
            $model = $this->manager->model($modelId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if ($this->manager->isBusy($modelId)) {
            return response()->json([
                'message' => "Unduhan {$model['label']} sedang berjalan.",
                'progress' => $this->manager->progress($modelId),
            ], 409);
        }

        if ($target === 'blob' && ! $this->blob->isConfigured()) {
            return response()->json([
                'message' => 'Token Vercel Blob belum diset. Isi priva_READ_WRITE_TOKEN di .env backend.',
            ], 422);
        }

        if ($target === 'local' && $this->manager->existsLocally($modelId)) {
            return response()->json(['message' => "Model {$model['label']} sudah ada di VPS."], 409);
        }

        if ($target === 'blob' && $this->manager->existsInBlob($modelId, true)) {
            return response()->json(['message' => "Model {$model['label']} sudah ada di blob."], 409);
        }

        $this->manager->setProgress($modelId, [
            'status' => 'running',
            'target' => $target,
            'source' => $target === 'local' && $this->manager->existsInBlob($modelId) ? 'blob' : 'huggingface',
            'done' => 0,
            'total' => count((array) $model['files']),
            'file' => null,
            'bytes' => 0,
        ]);

        DownloadEmbeddingModelJob::dispatch($modelId, $target);

        $this->audit($request, 'embedding_model_download_queued', [
            'model' => $modelId,
            'target' => $target,
        ]);

        return response()->json([
            'message' => $target === 'blob'
                ? "Unggahan {$model['label']} ke blob dimulai."
                : "Unduhan {$model['label']} ke VPS dimulai.",
            'progress' => $this->manager->progress($modelId),
        ], 202);
    }

    /** Progres satu model — dipanggil berkala oleh UI selama unduhan. */
    public function progress(Request $request, string $modelId)
    {
        $this->requireRoot($request);

        return response()->json([
            'progress' => $this->manager->progress($modelId),
            'in_local' => $this->manager->existsLocally($modelId),
            'in_blob' => $this->manager->existsInBlob($modelId, true),
        ]);
    }

    /** Hapus artefak lokal (blob tidak disentuh). */
    public function removeLocal(Request $request, string $modelId)
    {
        $this->requireRoot($request);

        try {
            $model = $this->manager->model($modelId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if ($this->manager->activeModelId() === $modelId && $this->manager->mode() === 'local') {
            return response()->json([
                'message' => "Model {$model['label']} sedang aktif pada mode local. Ganti model atau mode dulu.",
            ], 422);
        }

        $this->manager->removeLocal($modelId);
        $this->manager->clearProgress($modelId);

        $this->audit($request, 'embedding_model_local_removed', ['model' => $modelId]);

        return response()->json([
            'message' => "Artefak lokal {$model['label']} dihapus.",
            'models' => $this->manager->statusAll(),
        ]);
    }

    // ------------------------------------------------------------------

    private function requireRoot(Request $request): void
    {
        if (($request->user()->role ?? null) !== 'root') {
            abort(403, 'Hanya role root.');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function audit(Request $request, string $action, array $meta): void
    {
        try {
            $user = $request->user();

            AuditLog::create([
                'module' => 'platform.embedding_models',
                'record_id' => $meta['model'] ?? ($meta['to'] ?? null),
                'action' => $action,
                'user_id' => $user->id ?? null,
                'user_name' => $user->name ?? 'root',
                'user_role' => $user->role ?? 'root',
                'section' => 'embedding',
                'changes' => $meta,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // Audit tidak boleh menggagalkan aksi operasional root.
            Log::warning('[EmbeddingModelController] gagal menulis audit', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
