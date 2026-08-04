<?php

namespace Tests\Feature;

use App\Mail\DsrReportMail;
use App\Models\DsrInboundChannel;
use App\Models\DsrOutboundTarget;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kanal keluar dan masuk permohonan subjek data.
 *
 * Sifat yang paling menentukan dan diuji paling ketat: permohonan yang masuk
 * lewat surel TIDAK boleh dianggap terverifikasi. Alamat pengirim bukan bukti
 * kepemilikan identitas, dan meloloskannya akan menjadikan kanal ini jalan
 * pintas terhadap seluruh mekanisme verifikasi yang sudah ada.
 */
class DsrChannelTest extends TestCase
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
            'permissions' => ['dsr:read', 'dsr:write'],
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

    private function makeDsr(array $overrides = []): DsrRequest
    {
        return DsrRequest::create(array_merge([
            'org_id' => $this->org->id,
            'request_id' => 'DSR-2026-'.substr(uniqid(), -3),
            'request_type' => 'access',
            'requester_name' => 'Budi Santoso',
            'requester_email' => 'budi@contoh.id',
            'description' => 'Mohon salinan data saya.',
            'status' => 'completed',
            'response' => 'Data telah dikirimkan.',
            'deadline_at' => now()->addHours(72),
        ], $overrides));
    }

    // ==================== Surel keluar ====================

    #[Test]
    public function laporan_dikirim_ke_pemohon_lewat_surel(): void
    {
        Mail::fake();
        $dsr = $this->makeDsr();
        Sanctum::actingAs($this->user);

        $this->postJson("/api/dsr/{$dsr->id}/send-report", [
            'body' => 'Permohonan Anda telah selesai.',
            'attach_pdf' => false,
        ])->assertOk()->assertJsonPath('data.sent', true);

        Mail::assertSent(DsrReportMail::class, function (DsrReportMail $mail) use ($dsr) {
            return $mail->hasTo('budi@contoh.id') && $mail->dsr->id === $dsr->id;
        });
    }

    #[Test]
    public function pemohon_tanpa_alamat_surel_dilaporkan_gagal_bukan_diam_diam(): void
    {
        Mail::fake();
        // Kolomnya NOT NULL di skema, jadi bentuk kosong yang benar-benar dapat
        // muncul adalah string kosong — mis. dari impor lama atau dari kanal
        // yang tidak mewajibkan alamat.
        $dsr = $this->makeDsr(['requester_email' => '']);
        Sanctum::actingAs($this->user);

        $this->postJson("/api/dsr/{$dsr->id}/send-report", ['attach_pdf' => false])
            ->assertStatus(422)
            ->assertJsonPath('data.sent', false);

        Mail::assertNothingSent();
    }

    // ==================== API keluar ====================

    #[Test]
    public function permohonan_dikirim_ke_tujuan_api(): void
    {
        Http::fake(['https://crm.contoh.id/*' => Http::response(['id' => '500xx'], 201)]);

        $target = DsrOutboundTarget::create([
            'org_id' => $this->org->id,
            'name' => 'CRM Internal',
            'url' => 'https://crm.contoh.id/services/data/cases',
            'payload_format' => 'generic',
            'events' => ['dsr.completed'],
        ]);

        $dsr = $this->makeDsr();
        Sanctum::actingAs($this->user);

        $res = $this->postJson("/api/dsr/{$dsr->id}/push", ['event' => 'dsr.completed'])->assertOk();

        $this->assertTrue($res->json('data.0.ok'));
        Http::assertSent(fn ($req) => $req['request_id'] === $dsr->request_id && $req['event'] === 'dsr.completed');

        $this->assertSame(1, $target->fresh()->total_deliveries);
    }

    #[Test]
    public function muatan_salesforce_memakai_penamaan_field_platform_itu(): void
    {
        $dsr = $this->makeDsr(['status' => 'completed']);
        Sanctum::actingAs($this->user);

        $payload = $this->getJson("/api/dsr/{$dsr->id}/payload-preview?format=salesforce")
            ->assertOk()->json('data');

        $this->assertArrayHasKey('SuppliedEmail', $payload);
        $this->assertArrayHasKey('Privasimu_Request_Id__c', $payload);
        $this->assertSame('budi@contoh.id', $payload['SuppliedEmail']);
        // Status internal dipetakan ke picklist Salesforce; mengirim nilai di
        // luar picklist akan menggagalkan seluruh permintaan, bukan satu field.
        $this->assertSame('Closed', $payload['Status']);
    }

    #[Test]
    public function status_tanpa_padanan_jatuh_ke_new_bukan_dikirim_apa_adanya(): void
    {
        $dsr = $this->makeDsr(['status' => 'status_aneh_yang_tidak_ada']);
        Sanctum::actingAs($this->user);

        $payload = $this->getJson("/api/dsr/{$dsr->id}/payload-preview?format=salesforce")
            ->assertOk()->json('data');

        $this->assertSame('New', $payload['Status']);
    }

    #[Test]
    public function pengiriman_gagal_dicoba_ulang_lalu_dilaporkan(): void
    {
        Http::fake(['https://crm.contoh.id/*' => Http::response('gagal', 500)]);

        $target = DsrOutboundTarget::create([
            'org_id' => $this->org->id,
            'name' => 'CRM Bermasalah',
            'url' => 'https://crm.contoh.id/cases',
            'payload_format' => 'generic',
            'retry_count' => 2,
        ]);

        $dsr = $this->makeDsr();
        Sanctum::actingAs($this->user);

        $res = $this->postJson("/api/dsr/{$dsr->id}/push")->assertOk();

        $this->assertFalse($res->json('data.0.ok'));
        $this->assertSame(3, $res->json('data.0.attempts'), 'retry_count 2 berarti 3 percobaan.');
        $this->assertSame(1, $target->fresh()->failed_deliveries);
    }

    #[Test]
    public function tujuan_hanya_menerima_peristiwa_yang_didaftarkannya(): void
    {
        Http::fake();

        DsrOutboundTarget::create([
            'org_id' => $this->org->id,
            'name' => 'Hanya Selesai',
            'url' => 'https://crm.contoh.id/cases',
            'events' => ['dsr.completed'],
        ]);

        $dsr = $this->makeDsr();
        Sanctum::actingAs($this->user);

        $this->postJson("/api/dsr/{$dsr->id}/push", ['event' => 'dsr.created'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    // ==================== Surel masuk ====================

    #[Test]
    public function permohonan_dari_surel_dibuat_tetapi_belum_terverifikasi(): void
    {
        Sanctum::actingAs($this->user);

        $channel = $this->postJson('/api/dsr-channels/inbound', [
            'name' => 'Kotak Surat DPO',
            'type' => 'webhook',
        ])->assertStatus(201)->json('data');

        $token = DsrInboundChannel::withoutGlobalScope('org')->find($channel['id'])->inbound_token;

        $this->postJson("/api/public/dsr/inbound/{$token}", [
            'from' => 'Siti Aminah <siti@contoh.id>',
            'subject' => 'Permohonan penghapusan data pribadi',
            'text' => 'Mohon hapus seluruh data saya dari sistem.',
        ])->assertOk()->assertJsonPath('received', true);

        $dsr = DsrRequest::withoutGlobalScope('org')->latest()->first();

        $this->assertSame('siti@contoh.id', $dsr->requester_email);
        $this->assertSame('Siti Aminah', $dsr->requester_name);
        $this->assertSame('deletion', $dsr->request_type, 'Jenis permohonan ditebak dari isi pesan.');

        // Inti pengujian: surel bukan bukti identitas.
        $this->assertSame('pending_verification', $dsr->status);
        $this->assertSame('pending', $dsr->verification_status);
        $this->assertNull($dsr->verified_at);
        $this->assertNotNull($dsr->verification_token);

        // Tenggat dihitung sejak pesan diterima, bukan sejak petugas membukanya.
        $this->assertNotNull($dsr->deadline_at);
        $this->assertTrue($dsr->deadline_at->between(now()->addHours(71), now()->addHours(73)));
    }

    #[Test]
    public function penghapusan_diprioritaskan_di_atas_akses_saat_keduanya_disebut(): void
    {
        Sanctum::actingAs($this->user);
        $channel = $this->postJson('/api/dsr-channels/inbound', ['name' => 'K', 'type' => 'webhook'])
            ->assertStatus(201)->json('data');

        $res = $this->postJson("/api/dsr-channels/inbound/{$channel['id']}/test", [
            'from' => 'andi@contoh.id',
            'subject' => 'Akses dan hapus data',
            'body' => 'Saya ingin mengakses lalu menghapus data saya.',
        ])->assertStatus(201);

        $dsr = DsrRequest::withoutGlobalScope('org')->find($res->json('data.dsr_id'));
        $this->assertSame('deletion', $dsr->request_type);
    }

    #[Test]
    public function pesan_tidak_sah_ditolak_dengan_status_200_agar_tidak_dikirim_ulang(): void
    {
        Sanctum::actingAs($this->user);
        $channel = $this->postJson('/api/dsr-channels/inbound', ['name' => 'K', 'type' => 'webhook'])
            ->assertStatus(201)->json('data');
        $token = DsrInboundChannel::withoutGlobalScope('org')->find($channel['id'])->inbound_token;

        // Penyedia surel mengirim ulang berkali-kali pada respons galat, dan
        // pesan yang memang tidak sah tidak akan pernah menjadi sah.
        $this->postJson("/api/public/dsr/inbound/{$token}", [
            'from' => 'bukan-alamat-surel',
            'subject' => 'Halo',
        ])->assertOk()->assertJsonPath('received', false);

        $this->assertSame(0, DsrRequest::withoutGlobalScope('org')->count());
        $this->assertSame(1, DsrInboundChannel::withoutGlobalScope('org')->find($channel['id'])->total_rejected);
    }

    #[Test]
    public function token_kanal_yang_salah_atau_nonaktif_ditolak(): void
    {
        $this->postJson('/api/public/dsr/inbound/token-yang-tidak-ada', [
            'from' => 'x@contoh.id',
            'subject' => 'Halo',
        ])->assertStatus(404);

        $this->assertSame(0, DsrRequest::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function tanpa_izin_dsr_kanal_ditolak(): void
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'permissions' => ['ropa:read'],
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
        $this->getJson('/api/dsr-channels/targets')->assertStatus(403);
        $this->postJson('/api/dsr-channels/inbound', ['name' => 'X', 'type' => 'webhook'])->assertStatus(403);
    }
}
