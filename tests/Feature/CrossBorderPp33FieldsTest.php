<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Transfer lintas negara — kelengkapan field PP 33/2026 Pasal 162 & 169(2):
 * lingkup sektor, tempat penyimpanan, transfer lanjutan, dokumentasi akuntabilitas.
 */
class CrossBorderPp33FieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create(['org_id' => $org->id, 'name' => 'Admin', 'permissions' => ['*']]);
        $this->user = User::create([
            'org_id' => $org->id, 'name' => 'Admin', 'email' => 'admin'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'admin', 'tenant_role_id' => $role->id,
        ]);
    }

    #[Test]
    public function transfer_menyimpan_field_pasal_162_169(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/cross-border', [
            'destination_country' => 'Singapura',
            'destination_entity' => 'AWS SG',
            'transfer_purpose' => 'Hosting basis data',
            'legal_basis' => 'adequacy',
            'transfer_sector' => 'Jasa keuangan',
            'storage_location' => 'AWS ap-southeast-1',
            'onward_transfer_allowed' => true,
            'onward_transfer_detail' => 'Dapat diteruskan ke region US dengan SCC.',
            'accountability_doc' => 'SCC-2026-001 tersimpan di DMS.',
        ])->assertSuccessful()->json();

        $data = $created['data'] ?? $created;
        $this->assertSame('Jasa keuangan', $data['transfer_sector']);
        $this->assertSame('AWS ap-southeast-1', $data['storage_location']);
        $this->assertTrue((bool) $data['onward_transfer_allowed']);
        $this->assertNotEmpty($data['accountability_doc']);
    }
}
