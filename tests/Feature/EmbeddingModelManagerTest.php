<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\Embedding\EmbeddingModelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menguji aturan yang menggerakkan panel root Model Embedding: deteksi
 * keberadaan artefak per tujuan, aturan aktif/nonaktif tombol, dan penolakan
 * mengaktifkan model yang berkasnya belum tersedia.
 *
 * Semua pemanggilan HTTP di-fake — tidak ada yang benar-benar menembak
 * HuggingFace maupun Vercel Blob.
 */
class EmbeddingModelManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $modelsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsDir = storage_path('framework/testing/embedding-models-'.uniqid());
        config()->set('embedding_models.local.dir', $this->modelsDir);
        config()->set('embedding_models.blob.token', 'vercel_blob_rw_TESTTOKEN');

        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->modelsDir);

        parent::tearDown();
    }

    private function manager(): EmbeddingModelManager
    {
        return app(EmbeddingModelManager::class);
    }

    /** Tulis berkas tiruan agar model dianggap sudah ada di VPS. */
    private function fakeLocalFiles(string $modelId): void
    {
        $manager = $this->manager();
        $model = $manager->model($modelId);

        foreach ((array) $model['files'] as $file) {
            $path = $manager->localDir($modelId).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, 'isi-tiruan');
        }
    }

    /** Balas permintaan list blob dengan daftar berkas lengkap untuk satu model. */
    private function fakeBlobContains(string $modelId): void
    {
        $manager = $this->manager();
        $model = $manager->model($modelId);
        $prefix = $manager->blobPrefix($modelId).'/';

        $blobs = [];
        foreach ((array) $model['files'] as $file) {
            $blobs[] = [
                'pathname' => $prefix.$file,
                'url' => "https://store.public.blob.vercel-storage.com/{$prefix}{$file}",
                'size' => 1024,
            ];
        }

        Http::fake(['blob.vercel-storage.com*' => Http::response(['blobs' => $blobs], 200)]);
    }

    private function fakeBlobEmpty(): void
    {
        Http::fake(['blob.vercel-storage.com*' => Http::response(['blobs' => []], 200)]);
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------

    public function test_katalog_berisi_tiga_model_384_dimensi(): void
    {
        $catalog = $this->manager()->catalog();

        $this->assertCount(3, $catalog);
        $this->assertSame(
            ['minilm-l6-v2', 'bge-small-en-v15', 'multilingual-e5-small'],
            array_column($catalog, 'id')
        );

        foreach ($catalog as $model) {
            $this->assertSame(384, $model['dimension'], "{$model['id']} harus 384 dimensi");
            $this->assertContains('onnx/model_quantized.onnx', $model['files']);
        }
    }

    public function test_mode_default_blob_dan_dapat_diubah(): void
    {
        $manager = $this->manager();

        $this->assertSame('blob', $manager->mode());

        $manager->setMode('local');
        $this->assertSame('local', $manager->mode());
        $this->assertSame('local', AppSetting::get('embedding.mode'));
    }

    public function test_mode_tidak_dikenal_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->setMode('gpu-cloud');
    }

    public function test_model_default_adalah_yang_paling_kecil(): void
    {
        $this->assertSame('minilm-l6-v2', $this->manager()->activeModelId());
    }

    public function test_deteksi_keberadaan_lokal_butuh_seluruh_berkas(): void
    {
        $manager = $this->manager();

        $this->assertFalse($manager->existsLocally('minilm-l6-v2'));

        // Satu berkas saja tidak cukup — model dianggap belum lengkap.
        $path = $manager->localDir('minilm-l6-v2').DIRECTORY_SEPARATOR.'config.json';
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '{}');
        $this->assertFalse($manager->existsLocally('minilm-l6-v2'));

        $this->fakeLocalFiles('minilm-l6-v2');
        $this->assertTrue($manager->existsLocally('minilm-l6-v2'));
    }

    public function test_tombol_unduh_lokal_mati_setelah_ada_di_vps(): void
    {
        $this->fakeBlobEmpty();
        $this->fakeLocalFiles('minilm-l6-v2');

        $rows = collect($this->manager()->statusAll())->keyBy('id');

        $this->assertTrue($rows['minilm-l6-v2']['in_local']);
        $this->assertFalse($rows['minilm-l6-v2']['can_download_local']);
        // Model lain belum ada, tombolnya tetap hidup.
        $this->assertTrue($rows['bge-small-en-v15']['can_download_local']);
    }

    public function test_ada_di_blob_membuat_sumber_lokal_beralih_ke_blob(): void
    {
        $this->fakeBlobContains('minilm-l6-v2');

        $rows = collect($this->manager()->statusAll(true))->keyBy('id');
        $row = $rows['minilm-l6-v2'];

        $this->assertTrue($row['in_blob']);
        // Sudah di blob → tombol unggah blob mati, tombol lokal TETAP hidup
        // tetapi mengambil dari blob, bukan dari HuggingFace.
        $this->assertFalse($row['can_download_blob']);
        $this->assertTrue($row['can_download_local']);
        $this->assertSame('blob', $row['local_source']);
    }

    public function test_sumber_lokal_huggingface_saat_blob_kosong(): void
    {
        $this->fakeBlobEmpty();

        $rows = collect($this->manager()->statusAll(true))->keyBy('id');

        $this->assertSame('huggingface', $rows['minilm-l6-v2']['local_source']);
        $this->assertTrue($rows['minilm-l6-v2']['can_download_blob']);
    }

    public function test_aktivasi_ditolak_saat_artefak_belum_ada_di_tujuan_mode(): void
    {
        $manager = $this->manager();
        $manager->setMode('local');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/belum diunduh ke VPS/');

        $manager->activate('multilingual-e5-small');
    }

    public function test_aktivasi_berhasil_setelah_artefak_lokal_tersedia(): void
    {
        $manager = $this->manager();
        $manager->setMode('local');
        $this->fakeLocalFiles('multilingual-e5-small');

        $manager->activate('multilingual-e5-small');

        $this->assertSame('multilingual-e5-small', $manager->activeModelId());
        $this->assertSame(384, $manager->activeConfig()['dimension']);
        $this->assertSame('mean', $manager->activeConfig()['pooling']);
    }

    public function test_mode_blob_menuntut_artefak_ada_di_blob(): void
    {
        $this->fakeBlobEmpty();
        $manager = $this->manager();
        $manager->setMode('blob');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/belum ada di blob/');

        $manager->activate('bge-small-en-v15');
    }

    public function test_mode_api_tidak_menuntut_artefak_apa_pun(): void
    {
        $this->fakeBlobEmpty();
        $manager = $this->manager();
        $manager->setMode('api');

        $manager->activate('bge-small-en-v15');

        $this->assertSame('bge-small-en-v15', $manager->activeModelId());
        $this->assertSame('api', $manager->activeConfig()['source']['kind']);
    }

    public function test_model_tidak_dikenal_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->model('model-karangan');
    }

    public function test_e5_membawa_imbuhan_query_dan_passage(): void
    {
        // Imbuhan ini menentukan kualitas E5; kalau hilang, kemiripan melenceng
        // tanpa error apa pun. Jadi ikut dikunci di test.
        $model = $this->manager()->model('multilingual-e5-small');

        $this->assertSame('query: ', $model['prefix']['query']);
        $this->assertSame('passage: ', $model['prefix']['passage']);
        $this->assertTrue($model['multilingual']);
    }

    public function test_blob_tanpa_token_dilaporkan_tidak_tersedia(): void
    {
        config()->set('embedding_models.blob.token', null);
        Cache::flush();

        $rows = collect($this->manager()->statusAll())->keyBy('id');

        $this->assertFalse($rows['minilm-l6-v2']['in_blob']);
        $this->assertFalse($rows['minilm-l6-v2']['can_download_blob']);
    }
}
