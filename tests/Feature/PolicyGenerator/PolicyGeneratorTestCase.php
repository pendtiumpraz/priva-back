<?php

namespace Tests\Feature\PolicyGenerator;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\GeneratedPolicy;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shared fixtures for Policy Generator feature tests: tenant org + user,
 * AI license, a fake AI provider config (so AiService::isAvailable() is true),
 * and an Http::fake helper that returns canned chat-completion JSON.
 */
abstract class PolicyGeneratorTestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeOrg(array $attrs = []): Organization
    {
        $uid = uniqid();

        return Organization::create(array_merge([
            'name' => 'Org '.$uid,
            'slug' => 'org-'.$uid,
            'ai_credits_monthly' => 100,
            'ai_credits_remaining' => 100,
            'ai_credits_purchased' => 0,
            'ai_credits_reset_at' => now()->addMonth(),
        ], $attrs));
    }

    protected function makeUser(Organization $org, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'org_id' => $org->id,
            'role' => 'admin',
            'locale' => 'id',
        ], $attrs));
    }

    protected function giveAiLicense(Organization $org, string $package = 'ai'): License
    {
        return License::create([
            'license_key' => License::generateKey(),
            'package_type' => $package,
            'license_type' => 'subscription',
            'status' => 'active',
            'org_id' => $org->id,
        ]);
    }

    /** Seed a usable AI provider/model/config/selection so AiService is "available". */
    protected function seedAiProvider(Organization $org): void
    {
        $provider = AiProvider::create([
            'slug' => 'test-'.uniqid(),
            'name' => 'Test Provider',
            'api_base_url' => 'https://ai.test/v1',
            'auth_header' => 'Authorization',
            'auth_prefix' => 'Bearer',
            'is_active' => true,
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'model_id' => 'test-model',
            'name' => 'Test Model',
            'category' => 'chat',
            'is_active' => true,
        ]);
        DB::table('ai_provider_configs')->insert([
            'org_id' => $org->id,
            'provider_id' => $provider->id,
            'api_key_encrypted' => 'sk-test-key',
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_active_selections')->insert([
            'org_id' => $org->id,
            'chat_provider_id' => $provider->id,
            'chat_model_id' => $model->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Fake the LLM call so AiService::ask() returns the given sections output. */
    protected function fakeAi(array $aiOutput): void
    {
        Http::fake([
            '*chat/completions*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($aiOutput, JSON_UNESCAPED_UNICODE)]]],
            ], 200),
        ]);
    }

    /** A privacy policy draft covering all 15 mandatory elements. */
    protected function fullPolicyOutput(): array
    {
        return [
            'title' => 'Kebijakan Privasi PT Contoh',
            'metadata' => ['version' => '1.0'],
            'sections' => [
                ['type' => 'heading_1', 'text' => '1. Pengendali Data'],
                ['type' => 'paragraph', 'text' => 'PT Contoh selaku pengendali data pribadi bertanggung jawab atas pemrosesan data.'],
                ['type' => 'paragraph', 'text' => 'Narahubung DPO (data protection officer) di dpo@contoh.id.'],
                ['type' => 'paragraph', 'text' => 'Kategori data yang dikumpulkan: nama, email, nomor telepon.'],
                ['type' => 'paragraph', 'text' => 'Tujuan pemrosesan adalah penyediaan layanan.'],
                ['type' => 'paragraph', 'text' => 'Dasar hukum pemrosesan mengacu Pasal 20 UU PDP.'],
                ['type' => 'paragraph', 'text' => 'Masa retensi data disimpan selama 5 tahun.'],
                ['type' => 'paragraph', 'text' => 'Kami berbagi data dengan pihak ketiga penyedia pembayaran.'],
                ['type' => 'paragraph', 'text' => 'Hak subjek data: hak akses, koreksi, penghapusan.'],
                ['type' => 'paragraph', 'text' => 'Anda dapat melakukan penarikan persetujuan kapan saja.'],
                ['type' => 'paragraph', 'text' => 'Langkah keamanan data: enkripsi dan kontrol akses.'],
                ['type' => 'paragraph', 'text' => 'Kebijakan cookie: kami menggunakan kuki untuk analitik.'],
                ['type' => 'paragraph', 'text' => 'Data anak di bawah umur memerlukan persetujuan orang tua.'],
                ['type' => 'paragraph', 'text' => 'Transfer data lintas negara dengan safeguard Pasal 56.'],
                ['type' => 'paragraph', 'text' => 'Pemberitahuan pelanggaran data sesuai Pasal 46.'],
                ['type' => 'paragraph', 'text' => 'Perubahan kebijakan akan diberitahukan; versi kebijakan diperbarui.'],
            ],
        ];
    }

    protected function makePolicy(Organization $org, User $user, array $attrs = []): GeneratedPolicy
    {
        return GeneratedPolicy::create(array_merge([
            'org_id' => $org->id,
            'created_by' => $user->id,
            'audience' => 'customer',
            'language' => 'id',
            'document_type' => 'privacy_policy',
            'status' => 'draft',
            'title' => 'Kebijakan Privasi',
            'wizard_inputs' => ['company_name' => 'PT Contoh'],
            'ai_output' => [
                'title' => 'Kebijakan Privasi',
                'metadata' => [],
                'sections' => [
                    ['type' => 'heading_1', 'text' => '1. Pendahuluan'],
                    ['type' => 'paragraph', 'text' => 'Isi kebijakan privasi.'],
                ],
            ],
            'ai_metadata' => ['coverage' => ['covered_count' => 15, 'total' => 15, 'all_covered' => true, 'missing' => []]],
        ], $attrs));
    }
}
