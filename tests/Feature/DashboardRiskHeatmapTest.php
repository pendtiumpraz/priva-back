<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Heatmap risiko DPIA di dasbor.
 *
 * Bug yang dikunci di sini nyata dan berumur panjang: pembacanya menelusuri
 * `risk_assessment` seolah ia peta KATEGORI -> {likelihood, impact}, padahal
 * wizard DPIA menulis {likelihood, impact, risks: [...]} — satu pasang angka di
 * tingkat atas, bukan peta. Menelusurinya sebagai peta membuat nilai yang dibaca
 * berupa INT, indeksnya null, dan TIDAK ADA SATU SEL PUN yang terisi. Dasbor
 * selalu menampilkan "Belum ada DPIA dengan skor risiko" walau DPIA-nya ada dan
 * skornya terisi.
 *
 * Sumber yang benar sama dengan yang dibaca halaman rincian DPIA:
 * wizard_data.potensi_risiko[kategori].risk_events[] (`probabilitas`/`dampak`),
 * dengan risk_assessment.risks[] sebagai bentuk warisan.
 */
class DashboardRiskHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'dpo',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]));
    }

    /** @return array<string, int> "likelihood-impact" => count */
    private function sel(array $heatmap): array
    {
        $out = [];
        foreach ($heatmap as $s) {
            $out[$s['likelihood'].'-'.$s['impact']] = $s['count'];
        }

        return $out;
    }

    public function test_heatmap_membaca_skor_dari_wizard_potensi_risiko(): void
    {
        Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-001',
            'status' => 'draft',
            'description' => 'Penilaian penggajian',
            'wizard_data' => [
                'potensi_risiko' => [
                    'Kerahasiaan' => [
                        'risk_events' => [
                            ['risk_event' => 'Slip gaji bocor', 'probabilitas' => 4, 'dampak' => 5, 'kontrol' => 3],
                            ['risk_event' => 'Akses berlebih', 'probabilitas' => 2, 'dampak' => 3, 'penanganan' => 'mitigate'],
                        ],
                    ],
                    'Integritas' => [
                        'risk_events' => [
                            ['risk_event' => 'Salah hitung pajak', 'probabilitas' => 4, 'dampak' => 5, 'kontrol' => 2],
                        ],
                    ],
                ],
            ],
        ]);

        $data = $this->getJson('/api/dashboard/risk-analytics')->assertOk()->json();
        $sel = $this->sel($data['dpia_heatmap']);

        // Dua peristiwa berskor 4x5 dari kategori berbeda menumpuk di sel yang sama.
        $this->assertSame(2, $sel['4-5'] ?? 0);
        $this->assertSame(1, $sel['2-3'] ?? 0);
        $this->assertCount(2, $data['dpia_heatmap']);
    }

    public function test_bentuk_warisan_risk_assessment_tetap_terbaca(): void
    {
        Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-002',
            'status' => 'draft',
            'description' => 'Penilaian lama',
            // Persis bentuk yang ditulis wizard sebelum bagian risk_events ada:
            // sepasang angka ringkasan di tingkat atas + daftar risks.
            'risk_assessment' => [
                'likelihood' => 3,
                'impact' => 3,
                'risks' => [
                    ['risk' => 'Kerahasiaan', 'likelihood' => 4, 'impact' => 4, 'mitigation' => 'Enkripsi', 'status' => 'planned'],
                    ['risk' => 'Ketersediaan', 'likelihood' => 2, 'impact' => 2, 'mitigation' => '', 'status' => 'planned'],
                ],
            ],
        ]);

        $sel = $this->sel($this->getJson('/api/dashboard/risk-analytics')->assertOk()->json('dpia_heatmap'));

        $this->assertSame(1, $sel['4-4'] ?? 0);
        $this->assertSame(1, $sel['2-2'] ?? 0);
        // Pasangan ringkasan tingkat atas (3x3) BUKAN peristiwa risiko; ia
        // rata-rata turunan dan tidak boleh ikut jadi titik di heatmap.
        $this->assertArrayNotHasKey('3-3', $sel);
    }

    public function test_wizard_menang_atas_warisan_supaya_tidak_terhitung_dua_kali(): void
    {
        Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-003',
            'status' => 'draft',
            'description' => 'Keduanya terisi',
            'wizard_data' => [
                'potensi_risiko' => [
                    'Kerahasiaan' => ['risk_events' => [
                        ['risk_event' => 'Skor terbaru', 'probabilitas' => 5, 'dampak' => 5, 'kontrol' => 1],
                    ]],
                ],
            ],
            'risk_assessment' => [
                'risks' => [
                    ['risk' => 'Kerahasiaan', 'likelihood' => 1, 'impact' => 1, 'mitigation' => 'lama'],
                    ['risk' => 'Integritas', 'likelihood' => 3, 'impact' => 3, 'mitigation' => 'lama'],
                ],
            ],
        ]);

        $sel = $this->sel($this->getJson('/api/dashboard/risk-analytics')->assertOk()->json('dpia_heatmap'));

        $this->assertSame(1, $sel['5-5'] ?? 0, 'Skor dari wizard harus dipakai.');
        $this->assertArrayNotHasKey('1-1', $sel, 'Kategori yang sudah punya risk_events tidak boleh ditambah dari bentuk warisan.');
        // Kategori yang TIDAK ada di wizard tetap diambil dari bentuk warisan —
        // kalau tidak, DPIA setengah-pindah kehilangan separuh risikonya.
        $this->assertSame(1, $sel['3-3'] ?? 0);
    }

    public function test_risiko_tinggi_tanpa_penanganan_masuk_daftar_belum_ditangani(): void
    {
        Dpia::create([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-004',
            'status' => 'draft',
            'description' => 'Penilaian penggajian',
            'wizard_data' => [
                'potensi_risiko' => [
                    'Kerahasiaan' => ['risk_events' => [
                        // 4x5 = 20, tanpa kontrol maupun keputusan penanganan.
                        ['risk_event' => 'Slip gaji bocor', 'probabilitas' => 4, 'dampak' => 5],
                        // Skor sama tingginya, tetapi sudah ditangani.
                        ['risk_event' => 'Sudah dikendalikan', 'probabilitas' => 4, 'dampak' => 5, 'kontrol' => 4],
                        // Belum ditangani, tetapi skornya di bawah ambang 12.
                        ['risk_event' => 'Risiko kecil', 'probabilitas' => 2, 'dampak' => 2],
                    ]],
                ],
            ],
        ]);

        $daftar = $this->getJson('/api/dashboard/risk-analytics')->assertOk()->json('dpia_unmitigated');

        $this->assertCount(1, $daftar);
        $this->assertSame(20, $daftar[0]['risk_score']);
        $this->assertSame('Kerahasiaan', $daftar[0]['risk_category']);
    }

    public function test_dpia_tenant_lain_tidak_ikut_terhitung(): void
    {
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        Dpia::withoutGlobalScope('org')->create([
            'org_id' => $lain->id,
            'registration_number' => 'DPIA-2026-005',
            'status' => 'draft',
            'wizard_data' => [
                'potensi_risiko' => ['Kerahasiaan' => ['risk_events' => [
                    ['risk_event' => 'Milik tetangga', 'probabilitas' => 5, 'dampak' => 5],
                ]]],
            ],
        ]);

        $data = $this->getJson('/api/dashboard/risk-analytics')->assertOk()->json();

        $this->assertSame([], $data['dpia_heatmap']);
        $this->assertSame([], $data['dpia_unmitigated']);
    }
}
