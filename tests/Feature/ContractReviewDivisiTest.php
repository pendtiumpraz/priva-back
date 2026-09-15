<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\ContractReviewLinker;
use App\Support\AssignmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batas divisi pada Contract Review — DITURUNKAN dari pihak ketiganya.
 *
 * Daftar kontrak TPRM sudah lama menyaring lewat pihak ketiganya
 * (VendorContractController::index, `whereHas('vendor', …->visibleTo($user))`),
 * tetapi modul Contract Review tidak pernah ikut. Akibatnya berkas yang sama
 * punya dua jawaban berbeda: kontraknya tidak kelihatan di TPRM, telaahnya
 * terbuka lebar di /contract-review — lengkap dengan judul, skor risiko, dan
 * seluruh isi hasil telaahnya.
 *
 * Yang diuji di sini bukan aturan divisinya (itu milik AssignmentScope dan
 * sudah diuji tersendiri), melainkan bahwa PEWARISANNYA benar-benar terpasang
 * di setiap jalur — termasuk jalur tulis — dan bahwa telaah yang memang tidak
 * punya pihak ketiga tidak ikut tersapu.
 */
class ContractReviewDivisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
    }

    /**
     * Divisi seorang pengguna datang dari relasi `department`, bukan kolom di
     * `users` — sama seperti AiAgentDivisiTest.
     *
     * @param  array<int, string>  $izin
     */
    private function pengguna(string $role, string $namaPeran, ?string $divisi, array $izin = ['contract_review']): User
    {
        $departemen = $divisi ? Department::create([
            'org_id' => $this->org->id,
            'name' => $divisi,
        ]) : null;

        return User::factory()->create([
            'org_id' => $this->org->id,
            'role' => $role,
            'tenant_role_id' => TenantRole::create([
                'org_id' => $this->org->id,
                'name' => $namaPeran,
                'slug' => 'role-'.uniqid(),
                'permissions' => $izin,
            ])->id,
            'department_id' => $departemen?->id,
        ]);
    }

    /**
     * Telaah yang lahir dari TPRM, lewat jalur yang sebenarnya
     * (ContractReviewLinker) supaya bentuk tautannya tidak ditebak-tebak.
     *
     * @return array{0: string, 1: Vendor, 2: VendorContract} id telaah, pihak ketiga, kontrak
     */
    private function telaahKontrak(string $judulKontrak, ?string $divisi, string $namaPihak = 'PT Awan Data'): array
    {
        $pihak = Vendor::create([
            'org_id' => $this->org->id,
            'name' => $namaPihak,
            'assign_group' => $divisi,
        ]);

        $kontrak = VendorContract::create([
            'org_id' => $this->org->id,
            'vendor_id' => $pihak->id,
            'title' => $judulKontrak,
            'contract_type' => 'dpa',
            'file' => ['path' => 'kontrak/dpa.pdf', 'filename' => 'dpa.pdf'],
        ]);

        $id = app(ContractReviewLinker::class)->link($kontrak);
        $this->assertNotNull($id, 'kontrak ber-berkas harus menghasilkan telaah');

        return [(string) $id, $pihak, $kontrak];
    }

    /** Telaah yang TIDAK berasal dari TPRM — unggahan langsung atau Document Maker. */
    private function telaahLepas(string $judul, ?string $sumberModul = null, ?string $sumberId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('contract_reviews')->insert([
            'id' => $id,
            'org_id' => $this->org->id,
            'title' => $judul,
            'contract_type' => 'other',
            'status' => 'completed',
            'risk_score' => 0,
            'source_module' => $sumberModul,
            'source_document_id' => $sumberId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<int, string> */
    private function judulTerlihat(): array
    {
        return array_column(
            $this->getJson('/api/contract-reviews')->assertOk()->json('data'),
            'title',
        );
    }

    public function test_staf_hanya_melihat_telaah_kontrak_divisinya(): void
    {
        $this->telaahKontrak('DPA Rekrutmen', 'HR', 'PT Talenta');
        $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = $this->judulTerlihat();
        $this->assertContains('DPA Rekrutmen', $judul);
        $this->assertNotContains('DPA Pembayaran', $judul);
    }

    public function test_pihak_ketiga_multi_divisi_ikut_terlihat(): void
    {
        // Pihak ketiganya milik Keuangan TETAPI HR ikut ditugaskan.
        $this->telaahKontrak('MSA Payroll', 'Keuangan'.AssignmentScope::DELIM.'HR', 'PT Gaji Sentosa');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->assertContains('MSA Payroll', $this->judulTerlihat());
    }

    public function test_pihak_ketiga_semua_divisi_terlihat_semua_orang(): void
    {
        $this->telaahKontrak('NDA Korporat', AssignmentScope::SEMUA, 'PT Umum');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->assertContains('NDA Korporat', $this->judulTerlihat());
    }

    public function test_telaah_unggahan_langsung_terlihat_semua_divisi(): void
    {
        // Tidak punya pihak ketiga sama sekali → tidak punya divisi → milik
        // semua, persis seperti `assign_group` NULL di tabel lain.
        $this->telaahLepas('Telaah Unggahan Manual');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->assertContains('Telaah Unggahan Manual', $this->judulTerlihat());
    }

    public function test_telaah_dari_document_maker_terlihat_semua_divisi(): void
    {
        // Sumbernya ADA tapi bukan kontrak pihak ketiga — id-nya milik
        // `generated_documents`, jadi tidak akan pernah ketemu di
        // `vendor_contracts` dan telaahnya tetap tanpa divisi.
        $this->telaahLepas('Draf Kontrak Baru', 'document_maker', (string) Str::uuid());

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->assertContains('Draf Kontrak Baru', $this->judulTerlihat());
    }

    public function test_dpo_melihat_telaah_seluruh_tenant(): void
    {
        $this->telaahKontrak('DPA Rekrutmen', 'HR', 'PT Talenta');
        $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('dpo', 'dpo', 'HR'));

        $judul = $this->judulTerlihat();
        $this->assertContains('DPA Rekrutmen', $judul);
        $this->assertContains('DPA Pembayaran', $judul);
    }

    public function test_admin_tenant_berizin_bintang_juga_lintas_divisi(): void
    {
        $this->telaahKontrak('DPA Rekrutmen', 'HR', 'PT Talenta');
        $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('maker', 'Kepala Kepatuhan', 'HR', ['*']));

        $this->assertCount(2, $this->judulTerlihat());
    }

    public function test_detail_telaah_divisi_lain_tidak_bisa_dibuka(): void
    {
        [$id] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        // 404, bukan 403: keberadaan barisnya pun tidak perlu bocor.
        $this->getJson("/api/contract-reviews/{$id}")->assertNotFound();
    }

    public function test_ekspor_pdf_telaah_divisi_lain_ditolak(): void
    {
        [$id] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->getJson("/api/contract-reviews/{$id}/export.pdf")->assertNotFound();
    }

    public function test_keranjang_sampah_hanya_memuat_divisi_sendiri(): void
    {
        [$milikHr] = $this->telaahKontrak('DPA Rekrutmen', 'HR', 'PT Talenta');
        [$milikKeuangan] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        DB::table('contract_reviews')
            ->whereIn('id', [$milikHr, $milikKeuangan])
            ->update(['deleted_at' => now()]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $judul = array_column(
            $this->getJson('/api/contract-reviews/trashed')->assertOk()->json('data'),
            'title',
        );
        $this->assertContains('DPA Rekrutmen', $judul);
        $this->assertNotContains('DPA Pembayaran', $judul);
    }

    public function test_telaah_divisi_lain_tidak_bisa_dihapus(): void
    {
        [$id] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->deleteJson("/api/contract-reviews/{$id}")->assertNotFound();

        // Bukan sekadar pesannya yang 404 — barisnya memang tidak tersentuh.
        $this->assertNull(
            DB::table('contract_reviews')->where('id', $id)->value('deleted_at'),
            'telaah divisi lain tidak boleh ikut masuk keranjang sampah',
        );
    }

    public function test_telaah_divisi_lain_tidak_bisa_dihapus_permanen(): void
    {
        [$id] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->deleteJson("/api/contract-reviews/{$id}/force")->assertNotFound();

        $this->assertDatabaseHas('contract_reviews', ['id' => $id]);
    }

    public function test_telaah_divisi_lain_tidak_bisa_dipulihkan(): void
    {
        [$id] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');
        DB::table('contract_reviews')->where('id', $id)->update(['deleted_at' => now()]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        $this->postJson("/api/contract-reviews/{$id}/restore")->assertNotFound();

        $this->assertNotNull(DB::table('contract_reviews')->where('id', $id)->value('deleted_at'));
    }

    public function test_pihak_ketiga_dihapus_lunak_divisinya_tetap_berlaku(): void
    {
        [, $pihak] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');
        $pihak->delete();

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        // Barisnya masih ada beserta `assign_group`-nya, jadi divisinya masih
        // bisa dibaca — hapus lunak tidak boleh membuka apa pun.
        $this->assertNotContains('DPA Pembayaran', $this->judulTerlihat());
    }

    public function test_rantai_putus_membuat_telaah_kembali_tanpa_divisi(): void
    {
        [$id, $pihak, $kontrak] = $this->telaahKontrak('DPA Pembayaran', 'Keuangan', 'PT Bayar Cepat');

        // `DELETE /vendors/{id}/force` menghapus keras; vendor_id ber-cascade,
        // jadi kontraknya ikut hilang — sementara baris telaahnya sengaja
        // dibuat tanpa foreign key dan tetap tinggal.
        $pihak->forceDelete();

        $this->assertDatabaseMissing('vendor_contracts', ['id' => $kontrak->id]);
        $this->assertDatabaseHas('contract_reviews', ['id' => $id]);

        Sanctum::actingAs($this->pengguna('maker', 'staff', 'HR'));

        // Divisinya tidak bisa ditentukan lagi. Menyembunyikannya akan membuat
        // baris yang sah lenyap selamanya dari semua orang kecuali DPO/admin,
        // jadi ia kembali diperlakukan sebagai telaah tanpa divisi.
        $this->assertContains('DPA Pembayaran', $this->judulTerlihat());
    }
}
