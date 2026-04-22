<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Seed "Nexus Canonical" as a system DocumentTemplate with two uploaded
 * .docx templates attached — the exact ROPA / DPIA exports from the live
 * Nexus product, including cover page, header, footer with logo, watermark,
 * rounded alternating-row tables, and the 5×5 DPIA risk matrix.
 *
 * The source files ship in `resources/document-templates/` and get copied
 * into `storage/app/public/system-templates/` (Laravel's public disk) so
 * the TenantStorageService can resolve them from any tenant context.
 *
 * Idempotent:
 *   - If the DocumentTemplate row already exists, we update its
 *     docx_templates map + config instead of duplicating.
 *   - If the storage files already exist, we overwrite with the latest
 *     source copies (so tenants see template updates after `db:seed`).
 */
class NexusCanonicalDocxSeeder extends Seeder
{
    public function run(): void
    {
        // Use the `local` disk (storage/app/) because TenantStorageService
        // resolves system-template paths through getLocalPathForProcessing,
        // which defaults to the local disk for orgs without custom storage.
        // If we wrote to `public` here, every tenant render would 404.
        $disk = Storage::disk('local');

        $files = [
            'ropa' => [
                'source' => resource_path('document-templates/ROPA_DATA_EXPORT.docx'),
                'dest'   => 'system-templates/nexus_canonical_ropa.docx',
                'label'  => 'ROPA_DATA_EXPORT.docx',
            ],
            'dpia' => [
                'source' => resource_path('document-templates/DPIA_DATA_EXPORT.docx'),
                'dest'   => 'system-templates/nexus_canonical_dpia.docx',
                'label'  => 'DPIA_DATA_EXPORT.docx',
            ],
        ];

        $docxMap = [];
        foreach ($files as $kind => $f) {
            if (!is_file($f['source'])) {
                $this->command?->warn("Source missing: {$f['source']} — skipping {$kind}.");
                continue;
            }
            $disk->put($f['dest'], file_get_contents($f['source']));
            $docxMap[$kind] = [
                'path'        => $f['dest'],
                'name'        => $f['label'],
                'uploaded_at' => now()->toIso8601String(),
                'driver'      => 'local',
            ];
        }

        if (empty($docxMap)) {
            $this->command?->warn('No canonical DOCX files found — seeder skipped.');
            return;
        }

        DocumentTemplate::updateOrCreate(
            ['org_id' => null, 'name' => 'Nexus Canonical', 'is_system' => true],
            [
                'description' => 'Template resmi Nexus — cover page, header/footer berlogo, watermark, tabel alternating dengan border rounded. Mengisi 74 placeholder ROPA + 27 placeholder DPIA + 5×5 risk matrix via PhpWord cloneRow/cloneBlock.',
                'preview_image' => null,
                'config' => [
                    'primary_color' => '#16284C',
                    'accent_color' => '#16284C',
                    'font_family' => 'Poppins',
                    'font_size_body' => 10,
                    'header_enabled' => true,
                    'header_show_logo' => true,
                    'header_show_org_name' => true,
                    'header_bg' => '#F4F2FE',
                    'header_border_bottom' => true,
                    'footer_enabled' => true,
                    'footer_show_page_num' => true,
                    'footer_show_website' => true,
                    'watermark_enabled' => false,
                    'table_style' => 'rounded',
                    'cover_enabled' => true,
                    'cover_bg_color' => '#16284C',
                    'cover_title_color' => '#FFFFFF',
                    'signature_block_enabled' => true,
                    'signature_block_format' => 'dpo_single',
                    'page_size' => 'a4',
                    'page_margin_top' => 56,
                    'page_margin_bottom' => 56,
                    'page_margin_left' => 56,
                    'page_margin_right' => 56,
                ],
                'docx_templates' => $docxMap,
                'is_default'  => true,
                'is_system'   => true,
            ]
        );

        // Downgrade any other preset from is_default so Nexus Canonical wins.
        DocumentTemplate::whereNull('org_id')
            ->where('is_system', true)
            ->where('name', '!=', 'Nexus Canonical')
            ->update(['is_default' => false]);

        $this->command?->info('Nexus Canonical system DocumentTemplate seeded with ROPA + DPIA canonical DOCX.');
    }
}
