<?php

namespace Tests\Feature;

use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Support\KunciPencarian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemeriksaan permohonan DSR ganda.
 *
 * BUG YANG DIPERBAIKI: dua controller memeriksa duplikat dengan
 * `where('requester_email', $plaintext)` pada kolom ber-cast EncryptedString.
 * AES-256-CBC memakai IV acak — dua penyandian atas surel yang sama
 * menghasilkan sandi berbeda, jadi perbandingan itu TIDAK PERNAH cocok.
 * Pemeriksaannya mengembalikan kosong selamanya, tanpa galat, sehingga subjek
 * bisa menumpuk permohonan aktif yang masing-masing membawa tenggat 3x24 jam
 * sendiri.
 */
class DsrDuplikatSurelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private DsrApp $aplikasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->aplikasi = DsrApp::create([
            'org_id' => $this->org->id,
            'name' => 'Portal Nasabah',
            'app_code' => 'portal-'.substr(uniqid(), -6),
        ]);
    }

    private function permohonan(string $surel, string $status = 'pending_verification'): DsrRequest
    {
        return DsrRequest::create([
            'org_id' => $this->org->id,
            'app_id' => $this->aplikasi->id,
            'request_id' => 'DSR-2026-'.substr(uniqid(), -6),
            'request_type' => 'access',
            'requester_name' => 'Budi',
            'requester_email' => $surel,
            'status' => $status,
            'deadline_at' => now()->addHours(72),
        ]);
    }

    #[Test]
    public function pencarian_langsung_pada_kolom_tersandi_memang_tidak_pernah_cocok(): void
    {
        // Uji ini merekam SEBAB bug-nya. Kalau ia suatu saat merah, berarti
        // penyandiannya menjadi deterministik dan seluruh alasan kolom hash
        // gugur — bukan berarti ada yang rusak.
        $this->permohonan('budi@contoh.id');

        $this->assertNull(
            DsrRequest::where('org_id', $this->org->id)->where('requester_email', 'budi@contoh.id')->first(),
        );
    }

    #[Test]
    public function permohonan_aktif_dengan_surel_sama_ditemukan(): void
    {
        $asli = $this->permohonan('budi@contoh.id');

        $ketemu = DsrRequest::where('org_id', $this->org->id)
            ->surelAktif('budi@contoh.id')
            ->first();

        $this->assertNotNull($ketemu);
        $this->assertSame($asli->id, $ketemu->id);
    }

    #[Test]
    public function surel_dinormalkan_sebelum_dicocokkan(): void
    {
        $this->permohonan('Budi@Contoh.ID');

        $this->assertNotNull(
            DsrRequest::where('org_id', $this->org->id)->surelAktif(' budi@contoh.id ')->first(),
        );
    }

    #[Test]
    public function permohonan_yang_sudah_selesai_bukan_duplikat(): void
    {
        // Riwayat yang sah — subjek boleh mengajukan lagi setelah selesai.
        // Inilah sebabnya kolom hash TIDAK dibuat unik.
        $this->permohonan('budi@contoh.id', 'completed');

        $this->assertNull(
            DsrRequest::where('org_id', $this->org->id)->surelAktif('budi@contoh.id')->first(),
        );
    }

    #[Test]
    public function organisasi_lain_tidak_terhitung_duplikat(): void
    {
        $lain = Organization::factory()->create();
        DsrRequest::create([
            'org_id' => $lain->id,
            'request_id' => 'DSR-2026-LAIN',
            'request_type' => 'access',
            'requester_name' => 'Budi',
            'requester_email' => 'budi@contoh.id',
            'status' => 'pending_verification',
            'deadline_at' => now()->addHours(72),
        ]);

        $this->assertNull(
            DsrRequest::where('org_id', $this->org->id)->surelAktif('budi@contoh.id')->first(),
        );
    }

    #[Test]
    public function hash_diisi_otomatis_lewat_model_bukan_pemanggil(): void
    {
        // Permohonan DSR lahir dari banyak pintu — formulir publik, kunci API
        // mitra, universal CRUD, kanal surel masuk, agen AI. Kalau pengisian
        // hash ditaruh di controller, pintu yang terlupa menghasilkan baris
        // tanpa hash yang tidak akan pernah ikut terperiksa.
        $p = $this->permohonan('budi@contoh.id');

        $mentah = DB::table('dsr_requests')->where('id', $p->id)->first();
        $this->assertSame(KunciPencarian::hash('budi@contoh.id'), $mentah->requester_email_hash);
    }

    #[Test]
    public function hash_ikut_berubah_saat_surel_diperbarui(): void
    {
        $p = $this->permohonan('budi@contoh.id');
        $p->update(['requester_email' => 'budi.baru@contoh.id']);

        $this->assertNull(DsrRequest::where('org_id', $this->org->id)->surelAktif('budi@contoh.id')->first());
        $this->assertNotNull(DsrRequest::where('org_id', $this->org->id)->surelAktif('budi.baru@contoh.id')->first());
    }

    #[Test]
    public function hash_tidak_bisa_disetel_dari_luar(): void
    {
        // Kolomnya selalu diturunkan dari surel; kiriman luar diabaikan.
        $p = DsrRequest::create([
            'org_id' => $this->org->id,
            'request_id' => 'DSR-2026-'.substr(uniqid(), -6),
            'request_type' => 'access',
            'requester_name' => 'Budi',
            'requester_email' => 'budi@contoh.id',
            'requester_email_hash' => 'palsu',
            'status' => 'pending_verification',
            'deadline_at' => now()->addHours(72),
        ]);

        $mentah = DB::table('dsr_requests')->where('id', $p->id)->first();
        $this->assertSame(KunciPencarian::hash('budi@contoh.id'), $mentah->requester_email_hash);
    }

    #[Test]
    public function baris_lama_ikut_terisi_lewat_backfill(): void
    {
        // Tanpa backfill, duplikat atas data lama tetap tak terdeteksi dan
        // perbaikannya baru berlaku untuk permohonan setelah deploy.
        $p = $this->permohonan('lama@contoh.id');
        DB::table('dsr_requests')->where('id', $p->id)->update(['requester_email_hash' => null]);

        $migrasi = require database_path('migrations/2026_09_15_000006_add_requester_email_hash_to_dsr_requests.php');
        $migrasi->up();

        $this->assertSame(
            KunciPencarian::hash('lama@contoh.id'),
            DB::table('dsr_requests')->where('id', $p->id)->value('requester_email_hash'),
        );
    }
}
