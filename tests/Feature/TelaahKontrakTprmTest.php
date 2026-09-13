<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\ContractReviewLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Telaah kontrak yang berasal dari TPRM harus tahu itu kontrak SIAPA.
 *
 * Bug yang ditutup: `sourceDocument()` SELALU mencari sumber telaah di
 * `generated_documents`, padahal `source_module` bisa juga `vendor_contract` —
 * kontrak pihak ketiga yang dikirim ke telaah oleh ContractReviewLinker.
 * Akibatnya telaah dari TPRM tidak pernah menemukan judulnya, dan tombol
 * "Dokumen Sumber" di UI menavigasi ke `/document-maker/<id vendor_contracts>`:
 * tautan yang pasti rusak, tanpa satu pun petunjuk kontrak itu milik siapa.
 */
class TelaahKontrakTprmTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $peran = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'dpo',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'dpo',
            'tenant_role_id' => $peran->id,
        ]));
    }

    private function kontrak(Vendor $pihak, string $judul = 'DPA Awan Data'): VendorContract
    {
        return VendorContract::create([
            'org_id' => $this->org->id,
            'vendor_id' => $pihak->id,
            'title' => $judul,
            'contract_type' => 'dpa',
            'file' => ['path' => 'kontrak/dpa.pdf', 'filename' => 'dpa.pdf'],
        ]);
    }

    public function test_telaah_dari_tprm_menyebut_pihak_ketiga_pemiliknya(): void
    {
        $pihak = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data']);
        $kontrak = $this->kontrak($pihak);

        $reviewId = app(ContractReviewLinker::class)->link($kontrak);
        $this->assertNotNull($reviewId, 'kontrak ber-berkas harus menghasilkan telaah');

        $data = $this->getJson("/api/contract-reviews/{$reviewId}")->assertOk()->json('data');

        $this->assertSame('vendor_contract', $data['source_link']['module']);
        $this->assertSame('DPA Awan Data', $data['source_link']['title']);
        $this->assertSame('PT Awan Data', $data['source_link']['pihak_ketiga']['name']);
        // Tautannya menunjuk pihak ketiganya di TPRM, BUKAN Document Maker.
        $this->assertStringContainsString('/vendor-risk', $data['source_link']['href']);
        $this->assertStringNotContainsString('document-maker', $data['source_link']['href']);

        // Judul lama ikut benar, bukan null seperti sebelumnya.
        $this->assertSame('DPA Awan Data', $data['source_document_title']);
    }

    public function test_pihak_ketiga_terhapus_tidak_membuat_telaah_gagal_dibuka(): void
    {
        $pihak = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Sudah Hilang']);
        $kontrak = $this->kontrak($pihak, 'MSA Lama');
        $reviewId = app(ContractReviewLinker::class)->link($kontrak);

        $pihak->delete();

        $data = $this->getJson("/api/contract-reviews/{$reviewId}")->assertOk()->json('data');

        // Telaahnya tetap terbuka dan tetap menyebut kontraknya — hanya nama
        // pemiliknya yang hilang. Menutup telaah karena pemiliknya terhapus
        // akan menyandera bukti yang justru masih dibutuhkan.
        $this->assertSame('MSA Lama', $data['source_link']['title']);
        $this->assertNull($data['source_link']['pihak_ketiga']);
    }

    public function test_kontrak_tenant_lain_tidak_pernah_terbaca(): void
    {
        $lain = Organization::factory()->create();
        $pihakLain = Vendor::create(['org_id' => $lain->id, 'name' => 'PT Tetangga']);

        // Telaah milik org ini, tetapi menunjuk kontrak milik tenant lain.
        $kontrakLain = VendorContract::create([
            'org_id' => $lain->id, 'vendor_id' => $pihakLain->id,
            'title' => 'Kontrak Tetangga', 'contract_type' => 'dpa',
            'file' => ['path' => 'x.pdf', 'filename' => 'x.pdf'],
        ]);

        $reviewId = (string) Str::uuid();
        DB::table('contract_reviews')->insert([
            'id' => $reviewId, 'org_id' => $this->org->id, 'title' => 'Telaah Menyimpang',
            'status' => 'pending', 'risk_score' => 0,
            'source_document_id' => $kontrakLain->id,
            'source_module' => ContractReviewLinker::SOURCE_MODULE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson("/api/contract-reviews/{$reviewId}")->assertOk()->json('data');

        $this->assertNull($data['source_link'], 'sumber milik tenant lain tidak boleh terbaca');
        $this->assertStringNotContainsString('Kontrak Tetangga', json_encode($data));
    }

    public function test_telaah_dari_document_maker_tetap_seperti_semula(): void
    {
        $docId = (string) Str::uuid();
        DB::table('generated_documents')->insert([
            'id' => $docId, 'org_id' => $this->org->id, 'title' => 'Draf Kontrak AI',
            'user_id' => User::factory()->create(['org_id' => $this->org->id])->id,
            'kind' => 'contract', 'document_type' => 'nda',
            'wizard_inputs' => json_encode([]), 'ai_output' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $reviewId = (string) Str::uuid();
        DB::table('contract_reviews')->insert([
            'id' => $reviewId, 'org_id' => $this->org->id, 'title' => 'Telaah Draf',
            'status' => 'pending', 'risk_score' => 0,
            'source_document_id' => $docId, 'source_module' => 'document_maker',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson("/api/contract-reviews/{$reviewId}")->assertOk()->json('data');

        $this->assertSame('document_maker', $data['source_link']['module']);
        $this->assertSame('Draf Kontrak AI', $data['source_link']['title']);
        $this->assertStringContainsString('/document-maker/', $data['source_link']['href']);
        $this->assertNull($data['source_link']['pihak_ketiga']);
    }
}
