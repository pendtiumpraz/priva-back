<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\RopaDataFlowBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Peta alur data RoPA — penurunan otomatis dan lapisan suntingan manual.
 *
 * Test yang paling menentukan di berkas ini bukan "peta terbentuk", melainkan
 * "suntingan manual selamat ketika RoPA berubah". Peta yang kehilangan hasil
 * kerja pengguna setiap kali RoPA-nya diperbarui akan ditinggalkan setelah
 * pemakaian kedua.
 */
class RopaDataFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO',
            'permissions' => ['ropa:read', 'ropa:write'],
        ]);

        $this->user = User::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'email' => 'dpo'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]);
    }

    private function makeRopa(array $overrides = []): Ropa
    {
        return Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -3),
            'processing_activity' => 'Pembukaan Rekening Digital',
            'purpose' => 'Onboarding nasabah',
            'division' => 'Retail Banking',
            'legal_basis' => 'Pelaksanaan kontrak',
            'risk_level' => 'high',
            'recipients' => ['Biro Kredit', 'Vendor KYC'],
            'wizard_data' => [
                'pengumpulan_data' => [
                    'kategori_subjek' => ['Nasabah Perorangan'],
                    'sumber_data' => 'Formulir digital, Mitra agen',
                ],
                'informasi_pemrosesan' => ['sistem_terkait' => ['Core Banking']],
                'penggunaan_penyimpanan' => [
                    'lokasi_penyimpanan' => ['Data Center Jakarta'],
                    'cara_pemrosesan' => 'Otomatis',
                ],
                'pengiriman_data' => [
                    'negara_tujuan' => ['Singapura'],
                    'safeguards' => 'Standard Contractual Clauses',
                ],
                'retensi_keamanan' => [
                    'retention_period' => '5 tahun',
                    'prosedur_pemusnahan' => 'Penghapusan permanen',
                ],
            ],
        ], $overrides));
    }

    #[Test]
    public function peta_diturunkan_otomatis_dari_isi_ropa(): void
    {
        $ropa = $this->makeRopa();
        Sanctum::actingAs($this->user);

        $data = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->assertOk()->json('data');

        $labels = array_column($data['nodes'], 'label');
        $types = array_column($data['nodes'], 'type');

        // Perjalanan datanya utuh: dari subjek dan sumber, ke pemrosesan,
        // sistem, penyimpanan, penerima, lintas negara, sampai pemusnahan.
        $this->assertContains('Nasabah Perorangan', $labels);
        $this->assertContains('Mitra agen', $labels, 'Sumber dipisah koma harus terurai jadi simpul sendiri.');
        $this->assertContains('Pembukaan Rekening Digital', $labels);
        $this->assertContains('Core Banking', $labels);
        $this->assertContains('Data Center Jakarta', $labels);
        $this->assertContains('Biro Kredit', $labels);
        $this->assertContains('Singapura', $labels);
        $this->assertContains('Penghapusan permanen', $labels);

        $this->assertContains(RopaDataFlowBuilder::TYPE_CROSS_BORDER, $types);
        $this->assertSame(1, $data['stats']['cross_border']);
        $this->assertGreaterThan(0, $data['stats']['total_edges']);
    }

    #[Test]
    public function transfer_lintas_negara_dibedakan_dari_transfer_domestik(): void
    {
        $ropa = $this->makeRopa();
        Sanctum::actingAs($this->user);

        $data = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->assertOk()->json('data');

        $kinds = array_column($data['edges'], 'kind');
        $this->assertContains('cross_border', $kinds);
        $this->assertContains('transfer', $kinds);

        // Menyamakan keduanya akan menyembunyikan kewajiban Pasal 56.
        $this->assertNotSame(
            count(array_filter($kinds, fn ($k) => $k === 'cross_border')),
            count($kinds),
            'Transfer domestik dan lintas negara tidak boleh memakai jenis panah yang sama.'
        );
    }

    #[Test]
    public function safeguard_kosong_ditandai_pada_panahnya(): void
    {
        $wizard = $this->makeRopa()->wizard_data;
        $wizard['pengiriman_data']['safeguards'] = null;
        $ropa = $this->makeRopa(['wizard_data' => $wizard]);

        Sanctum::actingAs($this->user);
        $data = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->assertOk()->json('data');

        $crossEdge = collect($data['edges'])->firstWhere('kind', 'cross_border');
        $this->assertNotNull($crossEdge);
        $this->assertStringContainsString('safeguard belum diisi', $crossEdge['label']);
    }

    #[Test]
    public function suntingan_manual_selamat_ketika_ropa_berubah(): void
    {
        $ropa = $this->makeRopa();
        Sanctum::actingAs($this->user);

        $before = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $storageKey = collect($before['nodes'])->firstWhere('type', 'storage')['key'];

        $this->putJson("/api/ropa/{$ropa->id}/data-flow", [
            'manual_nodes' => [[
                'key' => 'manual:pusat-arsip',
                'type' => 'storage',
                'label' => 'Pusat Arsip Fisik',
                'description' => 'Ditambahkan saat pemetaan lapangan',
            ]],
            'manual_edges' => [[
                'key' => 'manual:edge-1',
                'from' => 'process:'.$ropa->id,
                'to' => 'manual:pusat-arsip',
                'label' => 'Salinan cetak',
                'kind' => 'store',
            ]],
            'overrides' => [$storageKey => ['label' => 'DC Jakarta (Tier III)']],
            'positions' => ['manual:pusat-arsip' => ['x' => 120, 'y' => 340]],
            'notes' => 'Divalidasi bersama tim operasional.',
        ])->assertOk();

        // RoPA berubah: penerima baru ditambahkan.
        $ropa->update(['recipients' => ['Biro Kredit', 'Vendor KYC', 'Regulator']]);

        $after = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->assertOk()->json('data');
        $labels = array_column($after['nodes'], 'label');

        // Yang otomatis ikut diperbarui...
        $this->assertContains('Regulator', $labels, 'Penurunan ulang harus memunculkan penerima baru.');
        // ...sementara suntingan manual tetap utuh.
        $this->assertContains('Pusat Arsip Fisik', $labels);
        $this->assertContains('DC Jakarta (Tier III)', $labels, 'Penyuntingan atas simpul otomatis harus bertahan.');
        $this->assertNotContains('Data Center Jakarta', $labels);

        $manualNode = collect($after['nodes'])->firstWhere('key', 'manual:pusat-arsip');
        $this->assertSame(['x' => 120, 'y' => 340], $manualNode['position']);
        $this->assertSame('Divalidasi bersama tim operasional.', $after['notes']);
        $this->assertSame(1, $after['stats']['manual_nodes']);
    }

    #[Test]
    public function elemen_yang_disembunyikan_tidak_muncul_tapi_tidak_hilang(): void
    {
        $ropa = $this->makeRopa();
        Sanctum::actingAs($this->user);

        $before = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $recipient = collect($before['nodes'])->firstWhere('label', 'Vendor KYC');

        $this->putJson("/api/ropa/{$ropa->id}/data-flow", [
            'hidden_keys' => [$recipient['key']],
        ])->assertOk();

        $hidden = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $this->assertNotContains('Vendor KYC', array_column($hidden['nodes'], 'label'));

        // Panah menuju simpul yang disembunyikan ikut hilang — kalau tidak,
        // penggambar akan menampilkan panah menggantung.
        $this->assertEmpty(array_filter(
            $hidden['edges'],
            fn ($e) => $e['to'] === $recipient['key']
        ));

        // Setelah dikembalikan, simpulnya muncul lagi: menyembunyikan bukan
        // menghapus, karena sumbernya tetap ada di RoPA.
        $this->deleteJson("/api/ropa/{$ropa->id}/data-flow")->assertOk();
        $restored = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $this->assertContains('Vendor KYC', array_column($restored['nodes'], 'label'));
    }

    #[Test]
    public function penyuntingan_tidak_dapat_memutus_tautan_simpul(): void
    {
        $ropa = $this->makeRopa();
        Sanctum::actingAs($this->user);

        $before = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $node = collect($before['nodes'])->firstWhere('type', 'recipient');

        // Mencoba menimpa kunci, jenis, dan asal lewat override.
        $this->putJson("/api/ropa/{$ropa->id}/data-flow", [
            'overrides' => [$node['key'] => [
                'key' => 'diretas', 'type' => 'process', 'origin' => 'manual', 'label' => 'Label baru',
            ]],
        ])->assertOk();

        $after = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $same = collect($after['nodes'])->firstWhere('key', $node['key']);

        $this->assertNotNull($same, 'Kunci simpul tidak boleh dapat ditimpa.');
        $this->assertSame('recipient', $same['type'], 'Jenis simpul tidak boleh dapat ditimpa.');
        $this->assertSame('auto', $same['origin'], 'Asal simpul tidak boleh dapat ditimpa.');
        $this->assertSame('Label baru', $same['label'], 'Label tetap boleh disunting.');
    }

    #[Test]
    public function sistem_tertaut_tidak_berlipat_dengan_isian_wizard(): void
    {
        $ropa = $this->makeRopa();
        Sanctum::actingAs($this->user);

        $data = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->json('data');
        $coreBanking = array_filter(
            array_column($data['nodes'], 'label'),
            fn ($l) => $l === 'Core Banking'
        );

        $this->assertCount(1, $coreBanking);
    }

    #[Test]
    public function ropa_kosong_tetap_menghasilkan_peta_yang_sah(): void
    {
        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -3),
            'processing_activity' => 'Kegiatan Baru',
        ]);

        Sanctum::actingAs($this->user);
        $data = $this->getJson("/api/ropa/{$ropa->id}/data-flow")->assertOk()->json('data');

        // Hanya simpul pemrosesan, tanpa panah — dan itu bukan galat melainkan
        // gambaran jujur atas RoPA yang memang belum diisi.
        $this->assertCount(1, $data['nodes']);
        $this->assertSame('process', $data['nodes'][0]['type']);
        $this->assertSame([], $data['edges']);
    }

    #[Test]
    public function tanpa_izin_ropa_peta_ditolak(): void
    {
        $ropa = $this->makeRopa();

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'permissions' => ['consent:read'],
        ]);
        $outsider = User::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'email' => 'no'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'tenant_role_id' => $role->id,
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/ropa/{$ropa->id}/data-flow")->assertStatus(403);
        $this->putJson("/api/ropa/{$ropa->id}/data-flow", [])->assertStatus(403);
    }
}
