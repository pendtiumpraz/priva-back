<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
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

    #[Test]
    public function menautkan_vendor_menyalin_nama_dan_kontak_dpo(): void
    {
        Sanctum::actingAs($this->user);

        $vendor = Vendor::create([
            'org_id' => $this->user->org_id,
            'name' => 'AWS Singapore Pte Ltd',
            'contact_name' => 'Jane Privacy',
            'contact_email' => 'dpo@aws.example',
        ]);

        // vendor_id valid → destination_entity & kontak DPO disalin dari vendor,
        // walau field entitas dikosongkan/di-override.
        $data = $this->postJson('/api/cross-border', [
            'vendor_id' => $vendor->id,
            'destination_country' => 'Singapura',
            'destination_entity' => 'diabaikan',
            'transfer_purpose' => 'Hosting',
            'legal_basis' => 'adequacy',
        ])->assertSuccessful()->json('data');

        $this->assertSame($vendor->id, $data['vendor_id']);
        $this->assertSame('AWS Singapore Pte Ltd', $data['destination_entity']);
        $this->assertSame('Jane Privacy', $data['recipient_dpo_name']);
        $this->assertSame('dpo@aws.example', $data['recipient_dpo_email']);
    }

    #[Test]
    public function vendor_id_lintas_org_diabaikan(): void
    {
        $otherOrg = Organization::create(['name' => 'Org Lain', 'slug' => 'olain-'.uniqid()]);
        $foreignVendor = Vendor::create(['org_id' => $otherOrg->id, 'name' => 'Vendor Luar']);

        Sanctum::actingAs($this->user);

        $data = $this->postJson('/api/cross-border', [
            'vendor_id' => $foreignVendor->id,
            'destination_country' => 'Malaysia',
            'destination_entity' => 'Entitas Manual',
            'transfer_purpose' => 'Hosting',
            'legal_basis' => 'adequacy',
        ])->assertSuccessful()->json('data');

        $this->assertNull($data['vendor_id']);
        $this->assertSame('Entitas Manual', $data['destination_entity']);
    }
}
