<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Ropa;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scanner masa retensi RoPA (PP 33/2026 Pasal 80).
 *
 * Yang menentukan: RoPA yang retensinya terlampaui/menjelang jatuh tempo
 * memicu notifikasi, sedangkan draft dan retensi tak-hingga tidak, dan
 * anti-spam mencegah reminder ganda pada hari yang sama.
 */
class RopaRetentionScanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        // Penerima fallback 'role:dpo,admin' butuh minimal satu DPO di org ini.
        User::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'email' => 'dpo'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
        ]);
    }

    private function ropa(array $attrs): Ropa
    {
        // Ropa::saving() menghitung ulang retention_due_date dari retensi_rows,
        // jadi nilai eksplisit harus di-set lewat saveQuietly (bypass event).
        $due = $attrs['retention_due_date'] ?? null;
        unset($attrs['retention_due_date']);

        $ropa = Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'processing_activity' => 'Pemrosesan data karyawan',
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -3),
            'status' => 'in_progress',
        ], $attrs));

        if ($due !== null) {
            $ropa->retention_due_date = $due;
            $ropa->saveQuietly();
        }

        return $ropa->refresh();
    }

    private function alerts(string $recordId): int
    {
        return SecurityAlert::withoutGlobalScope('org')
            ->where('record_id', $recordId)
            ->where('type', 'like', 'ropa.retention%')
            ->count();
    }

    #[Test]
    public function retensi_terlampaui_memicu_notifikasi_overdue(): void
    {
        $ropa = $this->ropa(['retention_due_date' => now()->subDays(5)]);

        $this->artisan('notifications:scan-ropa-retention')->assertSuccessful();

        $alert = SecurityAlert::withoutGlobalScope('org')->where('record_id', $ropa->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('ropa.retention_overdue', $alert->type);
    }

    #[Test]
    public function retensi_menjelang_jatuh_tempo_memicu_notifikasi_due(): void
    {
        $ropa = $this->ropa(['retention_due_date' => now()->addDays(10)]);

        $this->artisan('notifications:scan-ropa-retention')->assertSuccessful();

        $alert = SecurityAlert::withoutGlobalScope('org')->where('record_id', $ropa->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('ropa.retention_due', $alert->type);
    }

    #[Test]
    public function retensi_masih_jauh_tidak_memicu(): void
    {
        $ropa = $this->ropa(['retention_due_date' => now()->addDays(120)]);

        $this->artisan('notifications:scan-ropa-retention')->assertSuccessful();

        $this->assertSame(0, $this->alerts($ropa->id));
    }

    #[Test]
    public function draft_dan_tanpa_retensi_dilewati(): void
    {
        $draft = $this->ropa(['retention_due_date' => now()->subDays(5), 'status' => 'draft']);
        $noRetention = $this->ropa(['retention_due_date' => null]);

        $this->artisan('notifications:scan-ropa-retention')->assertSuccessful();

        $this->assertSame(0, $this->alerts($draft->id));
        $this->assertSame(0, $this->alerts($noRetention->id));
    }

    #[Test]
    public function anti_spam_mencegah_reminder_ganda(): void
    {
        $ropa = $this->ropa(['retention_due_date' => now()->subDays(5)]);

        $this->artisan('notifications:scan-ropa-retention')->assertSuccessful();
        $this->artisan('notifications:scan-ropa-retention')->assertSuccessful();

        // Hanya satu notifikasi meski scan dua kali dalam < 20 jam.
        $this->assertSame(1, $this->alerts($ropa->id));
    }
}
