<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use Database\Seeders\AiProviderComplianceSeeder;
use Database\Seeders\AiProviderSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Katalog Sumopod AI — gateway OpenAI-compatible dengan 53 model.
 *
 * Seeder ini dijalankan di basis data yang sudah berisi provider lain, jadi yang
 * dijaga di sini: idempoten, tidak menyenggol provider lain, dan angka harganya
 * memang yang dipilih secara sadar (peak / setelah diskon / tingkat tertinggi)
 * bukan hasil salah salin.
 */
class SumopodAiSeederTest extends TestCase
{
    use RefreshDatabase;

    private function sumopod(): AiProvider
    {
        $p = AiProvider::where('slug', 'sumopod')->first();
        $this->assertNotNull($p, 'provider sumopod tidak ter-seed');

        return $p;
    }

    private function model(string $modelId): AiModel
    {
        $m = AiModel::where('provider_id', $this->sumopod()->id)->where('model_id', $modelId)->first();
        $this->assertNotNull($m, "model {$modelId} tidak ter-seed");

        return $m;
    }

    public function test_provider_terdaftar_dengan_endpoint_openai_compatible(): void
    {
        $this->seed(AiProviderSeeder::class);
        $p = $this->sumopod();

        $this->assertSame('Sumopod AI', $p->name);
        $this->assertSame('https://ai.sumopod.com/v1', $p->api_base_url);
        // Bearer + Authorization = bawaan, itulah sebabnya tidak perlu cabang kode
        // baru: AiProviderController menembak {api_base_url}/chat/completions.
        $this->assertSame('Authorization', $p->auth_header);
        $this->assertSame('Bearer', $p->auth_prefix);
        $this->assertTrue($p->supports_tools);
        $this->assertTrue($p->is_active);
    }

    public function test_seluruh_53_model_ter_seed_tanpa_kembar(): void
    {
        $this->seed(AiProviderSeeder::class);

        $ids = AiModel::where('provider_id', $this->sumopod()->id)->pluck('model_id');
        $this->assertCount(53, $ids);
        $this->assertSame($ids->count(), $ids->unique()->count(), 'ada model_id kembar di provider yang sama');

        // Sepuluh keluarga hulu yang dijanjikan deskripsi provider benar-benar ada.
        foreach (['claude-opus-5', 'gpt-5.6-sol', 'gemini/gemini-3.1-pro-preview', 'deepseek-v4-pro',
            'glm-5.2', 'qwen3.8-max', 'kimi-k3', 'MiniMax-M3', 'mimo-v2.5-pro', 'hy3'] as $id) {
            $this->model($id);
        }
    }

    public function test_harga_memakai_angka_yang_dipilih_bukan_salah_salin(): void
    {
        $this->seed(AiProviderSeeder::class);

        // Harga lurus (tanpa diskon/peak/tingkat).
        $this->assertEqualsWithDelta(5.0, (float) $this->model('claude-opus-5')->input_price_per_m, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $this->model('claude-opus-5')->output_price_per_m, 0.0001);

        // Peak, bukan off-peak: $0.30/$1.20 (off-peak $0.15/$0.60).
        $this->assertEqualsWithDelta(0.3, (float) $this->model('deepseek-v4-flash')->input_price_per_m, 0.0001);
        $this->assertEqualsWithDelta(1.2, (float) $this->model('deepseek-v4-flash')->output_price_per_m, 0.0001);
        $this->assertEqualsWithDelta(1.32, (float) $this->model('deepseek-v4-pro')->input_price_per_m, 0.0001);

        // Setelah diskon, bukan harga coret: GLM-5.2 25% off ($1.40 -> $1.05).
        $this->assertEqualsWithDelta(1.05, (float) $this->model('glm-5.2')->input_price_per_m, 0.0001);
        $this->assertEqualsWithDelta(3.3, (float) $this->model('glm-5.2')->output_price_per_m, 0.0001);
        // MiniMax M2.7 Highspeed 90% off ($0.30 -> $0.03).
        $this->assertEqualsWithDelta(0.03, (float) $this->model('MiniMax-M2.7-highspeed')->input_price_per_m, 0.0001);

        // Bertingkat menurut konteks -> tingkat TERTINGGI ($0.20/$0.80, bukan $0.03/$0.13).
        $this->assertEqualsWithDelta(0.2, (float) $this->model('qwen3.7-flash-2026-07-15')->input_price_per_m, 0.0001);
        $this->assertEqualsWithDelta(0.8, (float) $this->model('qwen3.7-flash-2026-07-15')->output_price_per_m, 0.0001);

        // Embedding: keluarannya gratis, dan itu 0 — bukan null "harga tidak diketahui".
        $embed = $this->model('gemini/gemini-embedding-001');
        $this->assertSame('embedding', $embed->category);
        $this->assertEqualsWithDelta(0.0, (float) $embed->output_price_per_m, 0.0001);
        $this->assertSame(2048, $embed->context_window);
    }

    public function test_kemampuan_model_tidak_menyalakan_vision_pada_model_teks(): void
    {
        $this->seed(AiProviderSeeder::class);

        // Vision hanya untuk yang memang model penglihatan.
        $this->assertTrue($this->model('glm-5v-turbo')->supports_vision);
        $this->assertTrue($this->model('deepseek-v4-flash-vision-exp')->supports_vision);
        $this->assertTrue($this->model('claude-opus-5')->supports_vision);
        foreach (['deepseek-v4-pro', 'glm-5.2', 'qwen3.8-max', 'kimi-k3', 'hy3', 'mimo-v2.5'] as $id) {
            $this->assertFalse($this->model($id)->supports_vision, "{$id} seharusnya bukan model penglihatan");
        }

        // Embedding bukan model percakapan.
        $embed = $this->model('gemini/gemini-embedding-001');
        $this->assertFalse($embed->supports_tools);
        $this->assertFalse($embed->recommended_for_agent);
    }

    public function test_kepatuhan_ditandai_caution_tanpa_klaim_yang_belum_diverifikasi(): void
    {
        $this->seed(AiProviderSeeder::class);
        $this->seed(AiProviderComplianceSeeder::class);

        $p = $this->sumopod()->fresh();
        $this->assertSame('caution', $p->pdp_risk);
        // null, BUKAN false: `no_training === false` memicu gerbang risiko di
        // AiProviderController, padahal yang benar adalah "belum diketahui".
        $this->assertNull($p->no_training);
        $this->assertNull($p->gdpr_status);
        $this->assertNull($p->dpa_url);
        $this->assertStringContainsString('Belum diverifikasi', (string) $p->jurisdiction);
        $this->assertStringContainsString('MENGIKUTI MODEL', (string) $p->compliance_note);
    }

    public function test_dijalankan_dua_kali_tidak_menggandakan_apa_pun(): void
    {
        $this->seed(AiProviderSeeder::class);
        $providerAwal = AiProvider::count();
        $modelAwal = AiModel::count();
        $idAwal = $this->sumopod()->id;

        $this->seed(AiProviderSeeder::class);

        $this->assertSame($providerAwal, AiProvider::count());
        $this->assertSame($modelAwal, AiModel::count());
        $this->assertSame($idAwal, $this->sumopod()->id, 'provider dibuat ulang, bukan diperbarui');
    }

    /**
     * `php artisan db:seed` polos — inilah yang orang jalankan, bukan `--class=`.
     *
     * DatabaseSeeder pulang lebih awal begitu superadmin@privasimu.com ada
     * ("Database is already seeded"), dan katalog AI DULU dipanggil di bawah
     * gerbang itu. Akibatnya provider baru tidak pernah masuk ke basis data
     * mana pun yang sudah terisi — hanya ke basis data kosong. Katalognya kini
     * dipanggil di atas gerbang; test ini yang menjaganya tetap di sana.
     */
    public function test_db_seed_polos_tetap_memasukkan_katalog_di_basis_data_yang_sudah_terisi(): void
    {
        // Menandai basis data "sudah pernah di-seed" persis seperti gerbangnya membaca.
        User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'email' => 'superadmin@privasimu.com',
            'password' => bcrypt('rahasia-uji'),
            'role' => 'superadmin',
        ]);

        $this->seed(DatabaseSeeder::class);

        $p = $this->sumopod();
        $this->assertSame('https://ai.sumopod.com/v1', $p->api_base_url);
        $this->assertSame(53, AiModel::where('provider_id', $p->id)->count());
        // Metadata kepatuhan ikut terisi — urutan pemanggilannya benar.
        $this->assertSame('caution', $p->pdp_risk);
    }

    public function test_provider_lain_tidak_tersenggol(): void
    {
        $this->seed(AiProviderSeeder::class);

        // Sumopod menyeed model_id yang sama dengan milik OpenAI/Anthropic/DeepSeek
        // (gpt-4o, claude-haiku-4-5, deepseek-v4-pro). Keunikannya per provider,
        // jadi keduanya harus tetap berdiri sendiri-sendiri.
        $openai = AiProvider::where('slug', 'openai')->firstOrFail();
        $this->assertNotNull(AiModel::where('provider_id', $openai->id)->where('model_id', 'gpt-4o')->first());
        $this->assertNotNull($this->model('gpt-4o'));

        $anthropic = AiProvider::where('slug', 'anthropic')->firstOrFail();
        $this->assertSame(
            0.8,
            (float) AiModel::where('provider_id', $anthropic->id)->where('model_id', 'claude-haiku-4-5')->firstOrFail()->input_price_per_m,
            'harga Anthropic langsung ikut tertimpa harga Sumopod',
        );
        $this->assertEqualsWithDelta(1.0, (float) $this->model('claude-haiku-4-5')->input_price_per_m, 0.0001);
    }
}
