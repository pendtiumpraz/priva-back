<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\PolicyElementValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kebijakan Masa Retensi sebagai dokumen (PP 33/2026 Pasal 75(2)) +
 * elemen jangka waktu pemrosesan pada notice (Pasal 62).
 */
class RetentionPolicyPasal75Test extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function policy_validator_punya_elemen_jangka_waktu_pemrosesan(): void
    {
        $keys = collect(PolicyElementValidator::ELEMENTS)->pluck('key')->all();
        $this->assertContains('jangka_waktu_pemrosesan', $keys);
    }

    #[Test]
    public function retention_policy_menyimpan_isi_dokumen_pasal_75(): void
    {
        $org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create(['org_id' => $org->id, 'name' => 'Admin', 'permissions' => ['ropa:read', 'ropa:write']]);
        $user = User::create([
            'org_id' => $org->id, 'name' => 'Admin', 'email' => 'admin'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'admin', 'tenant_role_id' => $role->id,
        ]);

        Sanctum::actingAs($user);

        $data = $this->postJson('/api/retention-policies', [
            'name' => 'Kebijakan Retensi Karyawan',
            'duration_value' => 5,
            'duration_unit' => 'year',
            'subjects_covered' => 'Karyawan aktif & mantan karyawan',
            'data_components' => 'Data identitas, payroll, kontrak',
            'archival_provision' => 'Arsip inaktif 2 tahun sebelum pemusnahan',
            'deidentification_note' => 'Deidentifikasi untuk statistik SDM',
            'destruction_electronic' => 'Secure wipe (NIST 800-88)',
            'destruction_nonelectronic' => 'Cross-cut shredding',
        ])->assertStatus(201)->json('data');

        $this->assertSame('Karyawan aktif & mantan karyawan', $data['subjects_covered']);
        $this->assertSame('Secure wipe (NIST 800-88)', $data['destruction_electronic']);
        $this->assertSame('Cross-cut shredding', $data['destruction_nonelectronic']);
    }
}
