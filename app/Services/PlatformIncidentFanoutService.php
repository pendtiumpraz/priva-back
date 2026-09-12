<?php

namespace App\Services;

use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\PlatformIncident;
use App\Services\TenantDb\TenantDatabaseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyebarkan insiden PLATFORM menjadi catatan insiden di sisi tiap tenant
 * (UU PDP Pasal 46 — pemberitahuan paling lambat 3x24 jam).
 *
 * Terhadap tenant kami berkedudukan sebagai Prosesor. Satu insiden di sisi kami
 * menjadi kewajiban pemberitahuan bagi SETIAP Pengendali yang datanya kami
 * proses — dan kewajiban itu milik mereka, bukan milik kami. Karena itu yang
 * dibuat di sini adalah baris breach MILIK TENANT (`source = 'platform'`),
 * bukan sekadar pengumuman: begitu masuk, jam 3x24 mereka mulai berjalan di
 * modul yang memang mereka pakai untuk mengurusnya.
 *
 * Dua hal yang mudah salah dan sengaja ditangani di sini:
 *
 *  1. BYODB. Tenant berstatus `isolated` menyimpan breach_incidents di database
 *     TERSENDIRI. `CurrentOrgContext::runAs()` hanya mengganti lingkup org,
 *     BUKAN koneksi. Tanpa peralihan koneksi, barisnya akan mendarat di
 *     database platform dengan org_id tenant — tidak terlihat oleh tenant, dan
 *     menjadi campuran lintas tenant di database bersama. Karena itu koneksi
 *     dialihkan eksplisit, lalu DIPULIHKAN di `finally`.
 *
 *  2. Notifikasi dikirim DI LUAR peralihan koneksi. NotificationService
 *     menyelesaikan penerimanya lewat model User, dan User adalah data
 *     landlord yang belum dipatri ke koneksi landlord. Kalau dikirim di dalam
 *     peralihan, pencarian penerima dilakukan di database tenant dan tidak
 *     menemukan siapa pun — penyebaran akan "berhasil" tanpa ada yang diberi
 *     tahu. Itu kegagalan yang paling berbahaya karena tidak bersuara.
 */
class PlatformIncidentFanoutService
{
    public function __construct(
        private CurrentOrgContext $orgContext,
        private TenantDatabaseService $dbService,
        private RegistrationCodeService $codes,
    ) {}

    /**
     * @return array<int, array<string, mixed>> hasil per tenant, termasuk yang gagal
     */
    public function sebarkan(PlatformIncident $incident): array
    {
        $hasil = [];
        foreach ($this->tenantSasaran($incident) as $org) {
            $hasil[] = $this->catatDiTenant($incident, $org);
        }

        return $hasil;
    }

    /**
     * Tenant yang berhak tahu.
     *
     * `archived` dan `transferred` dilewati — tenant itu sudah tidak berjalan.
     * `frozen` TETAP ikut: pembekuan hanya memblokir login, datanya masih ada
     * dan kewajiban pemberitahuannya tidak ikut beku.
     *
     * `whereNotIn` polos aman di sini karena `organizations.lifecycle_status`
     * NOT NULL dengan default 'active' — diperiksa di migrasinya, bukan
     * diasumsikan. (Seandainya kolomnya nullable, `NULL NOT IN (...)` bernilai
     * NULL di SQL dan setiap baris kosong akan terbuang tanpa satu pun galat.)
     *
     * CATATAN TERBUKA: skema tidak punya penanda "organisasi milik platform" —
     * `org_level` adalah hierarki tenant (holding/sub_holding/subsidiary),
     * bukan pembeda platform vs tenant. Jadi bila baris organisasi milik
     * platform sendiri ada, ia ikut tersebar. Itu dibiarkan apa adanya dengan
     * sadar: menebak mana "organisasi kami" dari nama atau dari org pengguna
     * root akan meleset pada sebagian pemasangan, dan diam-diam melewatkan
     * tenant sungguhan jauh lebih berbahaya daripada satu catatan berlebih.
     *
     * @return Collection<int, Organization>
     */
    private function tenantSasaran(PlatformIncident $incident)
    {
        $q = Organization::query()
            ->whereNotIn('lifecycle_status', PlatformIncident::LIFECYCLE_EXCLUDED);

        if ($incident->affected_scope === PlatformIncident::SCOPE_SELECTED) {
            $q->whereIn('id', $incident->affected_org_ids ?: ['-']);
        }

        return $q->get();
    }

    /** @return array<string, mixed> */
    private function catatDiTenant(PlatformIncident $incident, Organization $org): array
    {
        $dasar = ['org_id' => $org->id, 'org_name' => $org->name];

        try {
            $breachId = $this->tulisDiDatabaseTenant($incident, $org);

            // Di LUAR peralihan koneksi — lihat catatan kelas di atas.
            NotificationService::dispatch(
                kind: 'alert',
                severity: $incident->severity,
                module: 'breach',
                type: 'platform.incident',
                recipient: 'role:dpo,admin',
                orgId: $org->id,
                title: 'Insiden pada platform: '.$incident->title,
                body: 'Terjadi insiden pada platform Privasimu yang berpotensi memengaruhi Data Pribadi '
                    .'yang Anda kelola. Sebuah catatan insiden telah dibuat otomatis di modul Breach '
                    .'organisasi Anda ('.$incident->incident_code.'). Sesuai UU PDP Pasal 46, '
                    .'pemberitahuan kepada Subjek Data dan Lembaga paling lambat 3x24 jam menjadi '
                    .'kewajiban Anda sebagai Pengendali. Segera tinjau catatan tersebut.',
                actionUrl: '/breach',
                metadata: [
                    'record_id' => $breachId,
                    'platform_incident_code' => $incident->incident_code,
                ],
            );

            return $dasar + ['status' => 'ok', 'breach_id' => $breachId];
        } catch (\Throwable $e) {
            // Satu tenant gagal tidak boleh menghentikan sisanya, dan tidak
            // boleh hilang diam-diam: hasilnya disimpan di baris insiden.
            \Log::warning("Penyebaran insiden platform gagal untuk org {$org->id}: ".$e->getMessage());

            return $dasar + ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function tulisDiDatabaseTenant(PlatformIncident $incident, Organization $org): string
    {
        $sebelumnya = DB::getDefaultConnection();
        $koneksi = $this->dbService->getConnection($org);
        if ($koneksi !== $sebelumnya) {
            DB::setDefaultConnection($koneksi);
        }

        try {
            return $this->orgContext->runAs($org->id, function () use ($incident, $org) {
                // Nomor dihasilkan DI DALAM koneksi tenant: untuk tenant
                // terisolasi, penghitungnya harus melihat baris di database
                // tenant itu sendiri — di situlah batasan uniknya berlaku.
                $regen = fn () => $this->codes->nextGlobal('BRC', BreachIncident::class);

                $breach = $this->codes->createWithRetry(new BreachIncident, [
                    'org_id' => $org->id,
                    'incident_code' => $regen(),
                    'title' => $incident->title,
                    'description' => 'Insiden berasal dari platform Privasimu ('.$incident->incident_code.'). '
                        .($incident->description ?: ''),
                    'severity' => $incident->severity,
                    'source' => 'platform',
                    'status' => 'detected',
                    'detected_at' => now(),
                    'notification_required' => in_array($incident->severity, ['high', 'critical'], true),
                    // Jam 3x24 Pengendali berjalan sejak mereka DIBERI TAHU,
                    // bukan sejak insidennya terjadi di sisi kami.
                    'notification_deadline' => in_array($incident->severity, ['high', 'critical'], true)
                        ? now()->addHours(72)
                        : null,
                    // created_by SENGAJA null: kolom itu foreign key ke users,
                    // sedangkan pelakunya pengguna root yang tidak ada di
                    // database tenant terisolasi. Pelakunya dicatat di
                    // timeline_log yang tidak terikat foreign key.
                    'created_by' => null,
                    'timeline_log' => [[
                        'at' => now()->toIso8601String(),
                        'actor' => 'platform',
                        'event' => 'Dibuat otomatis dari insiden platform '.$incident->incident_code,
                    ]],
                ], 'incident_code', $regen);

                return (string) $breach->id;
            });
        } finally {
            DB::setDefaultConnection($sebelumnya);
        }
    }
}
