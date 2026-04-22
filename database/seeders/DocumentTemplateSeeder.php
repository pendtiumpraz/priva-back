<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Seed 10 preset document templates that tenants can pick from.
 * Each preset focuses on a distinct visual tone so customers can match
 * their brand: Corporate Classic, Government Formal, Modern Minimal,
 * Dark Professional, Draft Watermark, Rounded Soft, Compact Tight,
 * Academic Paper, Startup Vibrant, Legal Formal.
 *
 * First preset has is_default=true — used as fallback when a tenant
 * hasn't picked one yet.
 */
class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $seeded = 0;
        foreach (self::PRESETS as $idx => $preset) {
            $name = $preset['name'];
            DocumentTemplate::updateOrCreate(
                ['org_id' => null, 'name' => $name, 'is_system' => true],
                [
                    'description' => $preset['description'],
                    'preview_image' => null,
                    'config' => $preset['config'],
                    'is_default' => $idx === 0,
                    'is_system' => true,
                ]
            );
            $seeded++;
        }
        $this->command?->info("Seeded {$seeded} document templates.");
    }

    private const PRESETS = [
        [
            'name' => 'Corporate Classic',
            'description' => 'Profesional default. Header biru navy + footer rapi. Cocok untuk korporasi umum.',
            'config' => [
                'primary_color' => '#0f172a',
                'accent_color' => '#1e40af',
                'header_bg' => '#f8fafc',
                'header_border_bottom' => true,
                'table_style' => 'clean',
                'watermark_enabled' => false,
            ],
        ],
        [
            'name' => 'Government Formal',
            'description' => 'Surat dinas pemerintah — header dengan garuda/logo besar, margin lebar, Times-like font.',
            'config' => [
                'primary_color' => '#000000',
                'accent_color' => '#7c2d12',
                'font_family' => 'DejaVu Serif',
                'font_size_body' => 12,
                'header_bg' => '#ffffff',
                'header_border_bottom' => true,
                'page_margin_top' => 110,
                'page_margin_left' => 60,
                'page_margin_right' => 60,
                'table_style' => 'bordered',
                'watermark_enabled' => false,
                'signature_block_format' => 'dpo_plus_director',
            ],
        ],
        [
            'name' => 'Modern Minimal',
            'description' => 'Bersih, whitespace generous, accent hijau. Cocok untuk startup/agency.',
            'config' => [
                'primary_color' => '#052e16',
                'accent_color' => '#16a34a',
                'header_bg' => null,
                'header_border_bottom' => false,
                'table_style' => 'minimal',
                'watermark_enabled' => false,
                'page_margin_top' => 90,
                'page_margin_bottom' => 70,
            ],
        ],
        [
            'name' => 'Dark Professional',
            'description' => 'Header gelap kontras, accent kuning — bold & premium.',
            'config' => [
                'primary_color' => '#0f172a',
                'accent_color' => '#f59e0b',
                'header_bg' => '#0f172a',
                'header_border_bottom' => false,
                'table_style' => 'striped',
                'watermark_enabled' => false,
            ],
        ],
        [
            'name' => 'Draft Watermark',
            'description' => 'Stamp "DRAFT" besar di belakang konten. Untuk dokumen internal yang belum final.',
            'config' => [
                'primary_color' => '#1e293b',
                'accent_color' => '#dc2626',
                'table_style' => 'clean',
                'watermark_enabled' => true,
                'watermark_text' => 'DRAFT',
                'watermark_opacity' => 0.09,
                'watermark_rotate' => -30,
            ],
        ],
        [
            'name' => 'Rounded Soft',
            'description' => 'Table rounded corners, soft shadows, accent ungu. Kesan friendly tapi tetap profesional.',
            'config' => [
                'primary_color' => '#1e1b4b',
                'accent_color' => '#8b5cf6',
                'header_border_bottom' => true,
                'table_style' => 'rounded',
                'watermark_enabled' => false,
            ],
        ],
        [
            'name' => 'Compact Tight',
            'description' => 'Margin sempit + font 10pt — padat info per halaman. Hemat kertas untuk laporan panjang.',
            'config' => [
                'primary_color' => '#1e293b',
                'accent_color' => '#0ea5e9',
                'font_size_body' => 10,
                'page_margin_top' => 70,
                'page_margin_bottom' => 55,
                'page_margin_left' => 40,
                'page_margin_right' => 40,
                'table_style' => 'striped',
            ],
        ],
        [
            'name' => 'Academic Paper',
            'description' => 'Serif font, margin seperti jurnal akademik. Cocok untuk report penelitian/compliance studies.',
            'config' => [
                'primary_color' => '#111827',
                'accent_color' => '#374151',
                'font_family' => 'DejaVu Serif',
                'font_size_body' => 11,
                'page_margin_top' => 120,
                'page_margin_bottom' => 90,
                'page_margin_left' => 70,
                'page_margin_right' => 70,
                'table_style' => 'bordered',
                'header_border_bottom' => false,
            ],
        ],
        [
            'name' => 'Startup Vibrant',
            'description' => 'Warna pink/magenta bold, gradient header. Untuk company yang muda & branded.',
            'config' => [
                'primary_color' => '#881337',
                'accent_color' => '#ec4899',
                'header_bg' => '#fdf2f8',
                'header_border_bottom' => true,
                'table_style' => 'rounded',
                'cover_enabled' => true,
                'cover_bg_color' => '#ec4899',
                'cover_title_color' => '#ffffff',
            ],
        ],
        [
            'name' => 'Legal Formal',
            'description' => 'Numbered sections, formal paragraph style, no color accents. Untuk legal opinion / kontrak.',
            'config' => [
                'primary_color' => '#0c0a09',
                'accent_color' => '#44403c',
                'font_family' => 'DejaVu Serif',
                'font_size_body' => 11,
                'header_bg' => null,
                'header_border_bottom' => true,
                'footer_show_page_num' => true,
                'footer_show_website' => false,
                'table_style' => 'bordered',
                'signature_block_format' => 'dpo_plus_director',
                'watermark_enabled' => false,
            ],
        ],
    ];
}
