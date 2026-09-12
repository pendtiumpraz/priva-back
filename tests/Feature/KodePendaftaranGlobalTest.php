<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\PartnerApiKey;
use App\Models\Ropa;
use App\Models\User;
use App\Services\AiAgentToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keunikan nomor pendaftaran lintas tenant (dataroom F-03).
 *
 * Batasan unik pada `registration_number` / `incident_code` bersifat GLOBAL,
 * bukan per-org. Beberapa jalur pembuatan masih menghitung sendiri dan salah:
 *
 *   - BreachApiController v1  → `count()` disaring per-org. Dua tenant yang
 *     mencatat insiden ke-N menghasilkan kode yang sama, dan karena
 *     hitungannya tetap per-org, mengulang tidak pernah mengubah hasilnya —
 *     persis bentuk F-03 yang dulu terjadi di DsrIntakeService.
 *   - ImportDocumentJob       → `count()` per-org juga.
 *   - AiAgentToolExecutor     → `rand(100, 999)` untuk ROPA/DPIA/BRC, tanpa
 *     percobaan ulang sama sekali.
 *
 * Yang dikunci di sini: penghitungnya global, penomorannya berlanjut lintas
 * tenant (bukan mengulang dari 1 di tiap tenant), dan penanda "-AI-" tetap
 * hidup sebagai namespace tersendiri sehingga asal-usul catatan masih terbaca
 * tanpa pernah bertabrakan dengan penomoran biasa.
 */
class KodePendaftaranGlobalTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $nama): Organization
    {
        return Organization::factory()->create(['name' => $nama]);
    }

    /** @return array<string, string> header kunci API mitra milik org tsb. */
    private function kunci(Organization $org): array
    {
        $user = User::factory()->create(['org_id' => $org->id]);

        return ['X-Api-Key' => PartnerApiKey::generateKey([
            'org_id' => $org->id,
            'name' => 'Sistem Mitra',
            'permissions' => ['*'],
            'environment' => 'live',
            'rate_limit_per_minute' => 120,
            'is_active' => true,
            'created_by' => $user->id,
        ])['key']];
    }

    private function catatBreachLewatApi(Organization $org, string $judul): string
    {
        return $this->postJson('/api/v1/breach', [
            'title' => $judul,
            'severity' => 'high',
        ], $this->kunci($org))->assertStatus(201)->json('data.incident_code');
    }

    public function test_kode_breach_api_tidak_bertabrakan_antar_tenant(): void
    {
        $satu = $this->org('PT Satu');
        $dua = $this->org('PT Dua');

        $kodeSatu = $this->catatBreachLewatApi($satu, 'Insiden di tenant satu');
        $kodeDua = $this->catatBreachLewatApi($dua, 'Insiden di tenant dua');

        $this->assertNotSame(
            $kodeSatu,
            $kodeDua,
            'batasan unik incident_code bersifat global — hitungan per-org membuat keduanya sama (F-03)',
        );
        $this->assertSame(2, BreachIncident::withoutGlobalScope('org')->count());
    }

    public function test_penomoran_breach_berlanjut_lintas_tenant(): void
    {
        $satu = $this->org('PT Satu');
        $dua = $this->org('PT Dua');

        $this->catatBreachLewatApi($satu, 'Insiden pertama');
        $kodeDua = $this->catatBreachLewatApi($dua, 'Insiden kedua');

        // Bukan sekadar berbeda: penghitungnya memang global, jadi tenant kedua
        // melanjutkan urutan, tidak mengulang dari 001.
        $this->assertSame('BRC-'.date('Y').'-002', $kodeDua);
    }

    public function test_jalur_ai_tidak_lagi_memakai_angka_acak(): void
    {
        $satu = $this->org('PT Satu');
        $dua = $this->org('PT Dua');

        $kode = [];
        foreach ([$satu, $dua] as $org) {
            [$data] = (new AiAgentToolExecutor($org->id))->execute('create_ropa', [
                'processing_activity' => 'Dibuat oleh agen AI',
            ], true);
            $kode[] = $data['registration_number'];
        }

        // Deterministik dan berurutan — bukan rand(100, 999).
        $this->assertSame(['ROPA-AI-'.date('Y').'-001', 'ROPA-AI-'.date('Y').'-002'], $kode);
    }

    public function test_namespace_ai_terpisah_dari_penomoran_biasa(): void
    {
        $org = $this->org('PT Satu');

        // Nomor biasa yang sudah terpakai tidak boleh membuat jalur AI melompat,
        // dan sebaliknya — keduanya namespace yang berbeda.
        Ropa::create([
            'org_id' => $org->id,
            'registration_number' => 'ROPA-'.date('Y').'-001',
            'processing_activity' => 'Dibuat manual',
        ]);

        [$data] = (new AiAgentToolExecutor($org->id))->execute('create_ropa', [
            'processing_activity' => 'Dibuat oleh agen AI',
        ], true);

        $this->assertSame('ROPA-AI-'.date('Y').'-001', $data['registration_number']);
        $this->assertSame(2, Ropa::withoutGlobalScope('org')->count());
    }

    public function test_kode_breach_ai_ditulis_ke_kolom_yang_benar(): void
    {
        $org = $this->org('PT Satu');

        [$data] = (new AiAgentToolExecutor($org->id))->execute('create_breach', [
            'title' => 'Insiden dari agen AI',
            'severity' => 'medium',
        ], true);

        // Peta prefix di RegistrationCodeService tidak mengenal 'BRC-AI' dan
        // nilai bawaannya 'registration_number' — salah kolom untuk
        // BreachIncident. Kolomnya harus disebut eksplisit.
        $this->assertSame('BRC-AI-'.date('Y').'-001', $data['incident_code']);
        $this->assertSame(1, BreachIncident::withoutGlobalScope('org')->count());
    }
}
