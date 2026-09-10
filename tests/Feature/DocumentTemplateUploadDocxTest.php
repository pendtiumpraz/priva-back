<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\TenantTheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/document-templates/{id}/upload-docx — auto-assign setelah upload.
 *
 * Regresi: `TenantTheme::firstOrCreate(['org_id' => ...])` dipanggil tanpa
 * atribut default, padahal `tenant_themes.name` dan `tenant_themes.palette`
 * NOT NULL tanpa DB default. Untuk tenant yang belum punya baris tema, INSERT
 * gagal, exception ditelan try/catch, dan endpoint diam-diam mengembalikan
 * `auto_assigned: false` — fitur auto-assign tidak pernah jalan.
 */
class DocumentTemplateUploadDocxTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private DocumentTemplate $tpl;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->org = Organization::create([
            'name' => 'Org Docx',
            'slug' => 'org-docx-'.Str::random(6),
        ]);

        $this->admin = User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'admin',
        ]);

        $this->tpl = DocumentTemplate::create([
            'org_id' => $this->org->id,
            'name' => 'Template Tenant',
            'config' => DocumentTemplate::DEFAULT_CONFIG,
        ]);
    }

    private function fakeDocx(): UploadedFile
    {
        return UploadedFile::fake()->create(
            'ropa-template.docx',
            12,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
    }

    public function test_upload_docx_auto_assigns_for_tenant_without_existing_theme_row(): void
    {
        $this->assertSame(0, TenantTheme::where('org_id', $this->org->id)->count());

        Sanctum::actingAs($this->admin);

        $res = $this->postJson("/api/document-templates/{$this->tpl->id}/upload-docx", [
            'file' => $this->fakeDocx(),
            'kind' => 'ropa',
        ]);

        $res->assertOk()->assertJsonPath('auto_assigned', true);

        $theme = TenantTheme::where('org_id', $this->org->id)->first();
        $this->assertNotNull($theme, 'A tenant_themes row should have been created.');
        $this->assertSame('Default', $theme->name);
        $this->assertSame(TenantTheme::defaultPalette(), $theme->palette);
        $this->assertSame($this->tpl->id, $theme->active_template_map['ropa'] ?? null);
    }

    public function test_upload_docx_kind_gap_maps_to_gap_report_key(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson("/api/document-templates/{$this->tpl->id}/upload-docx", [
            'file' => UploadedFile::fake()->create(
                'gap-template.docx',
                12,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ),
            'kind' => 'gap',
        ]);

        $res->assertOk()->assertJsonPath('auto_assigned', true);

        $theme = TenantTheme::where('org_id', $this->org->id)->first();
        $this->assertSame($this->tpl->id, $theme->active_template_map['gap_report'] ?? null);
    }

    public function test_upload_docx_preserves_existing_theme_and_other_kind_bindings(): void
    {
        $existing = TenantTheme::create([
            'org_id' => $this->org->id,
            'name' => 'Tema Tenant',
            'palette' => TenantTheme::defaultPalette(),
            'active_template_map' => ['dpia' => 'keep-me'],
        ]);

        Sanctum::actingAs($this->admin);

        $res = $this->postJson("/api/document-templates/{$this->tpl->id}/upload-docx", [
            'file' => $this->fakeDocx(),
            'kind' => 'ropa',
        ]);

        $res->assertOk()->assertJsonPath('auto_assigned', true);

        $this->assertSame(1, TenantTheme::where('org_id', $this->org->id)->count());
        $existing->refresh();
        $this->assertSame('Tema Tenant', $existing->name);
        $this->assertSame('keep-me', $existing->active_template_map['dpia'] ?? null);
        $this->assertSame($this->tpl->id, $existing->active_template_map['ropa'] ?? null);
    }
}
