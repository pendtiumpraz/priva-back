<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EleganceTemplatesSeeder — Fase 1 dari 20 template PDF elegan
 * untuk modul ROPA / DPIA / GAP.
 *
 * Tiga template sudah diimplementasikan (Blade view tersedia):
 *   - Midnight Indigo (executive)
 *   - Editorial Classic (editorial)
 *   - Swiss International (minimalist)
 *
 * Tujuh belas lainnya didaftarkan sebagai "coming_soon" sehingga
 * UI dapat menampilkan kartu preview lengkap dengan badge "Segera Hadir".
 *
 * Idempoten: menggunakan updateOrInsert dengan kunci composite
 * (org_id NULL, name) sehingga aman dijalankan ulang.
 */
class EleganceTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $defaultConfig = DocumentTemplate::DEFAULT_CONFIG;
        $seeded = 0;
        $available = 0;

        foreach (self::TEMPLATES as $tpl) {
            $name = sprintf('%02d — %s', $tpl['number'], $tpl['display']);
            $isAvailable = $tpl['implemented'];
            if ($isAvailable) {
                $available++;
            }

            $config = array_merge($defaultConfig, [
                'primary_color' => $tpl['primary_color'],
                'accent_color' => $tpl['accent_color'],
            ]);

            // Cek apakah baris sudah ada (untuk pertahankan UUID lama).
            $existing = DB::table('document_templates')
                ->whereNull('org_id')
                ->where('name', $name)
                ->first();

            $id = $existing->id ?? (string) Str::uuid();

            DB::table('document_templates')->updateOrInsert(
                ['org_id' => null, 'name' => $name],
                [
                    'id' => $id,
                    'description' => $tpl['description'],
                    'preview_image' => null,
                    'blade_view' => $isAvailable ? 'reports.templates.' . $tpl['slug'] : null,
                    'engine' => $tpl['engine'],
                    'status' => $isAvailable ? 'available' : 'coming_soon',
                    'style_category' => $tpl['style_category'],
                    'config' => json_encode($config),
                    'docx_templates' => $existing->docx_templates ?? null,
                    'is_default' => false,
                    'is_system' => true,
                    'usage_count' => $existing->usage_count ?? 0,
                    'created_by' => $existing->created_by ?? null,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            $seeded++;
        }

        $comingSoon = $seeded - $available;
        $this->command?->info("Seeded {$seeded} elegance document templates ({$available} available, {$comingSoon} coming soon).");
    }

    /**
     * Sumber kebenaran 20 template — urutan, slug, engine, kategori,
     * dan accent color. Aksen warna dipilih agar mencerminkan deskripsi
     * visual (mis. Midnight Indigo → emas hangat, Swiss → merah Helvetica).
     */
    private const TEMPLATES = [
        [
            'number' => 1,
            'display' => 'Midnight Indigo',
            'slug' => 'midnight-indigo',
            'engine' => 'dompdf',
            'style_category' => 'executive',
            'implemented' => true,
            'description' => 'Navy gelap dengan aksen emas hangat dan serif klasik. Kesan eksekutif dan tegas.',
            'primary_color' => '#1a1f3a',
            'accent_color' => '#c4a054',
        ],
        [
            'number' => 2,
            'display' => 'Editorial Classic',
            'slug' => 'editorial-classic',
            'engine' => 'dompdf',
            'style_category' => 'editorial',
            'implemented' => true,
            'description' => 'Tata letak editorial bergaya majalah dengan aksen cokelat tembaga.',
            'primary_color' => '#1f1a17',
            'accent_color' => '#7a4f1d',
        ],
        [
            'number' => 3,
            'display' => 'Pure Minimal',
            'slug' => 'pure-minimal',
            'engine' => 'dompdf',
            'style_category' => 'minimalist',
            'implemented' => true,
            'description' => 'Putih bersih dengan tipografi ringan dan whitespace luas.',
            'primary_color' => '#111111',
            'accent_color' => '#3a3a3a',
        ],
        [
            'number' => 4,
            'display' => 'Lilac Modernist',
            'slug' => 'lilac-modernist',
            'engine' => 'dompdf',
            'style_category' => 'modern',
            'implemented' => true,
            'description' => 'Palet ungu lembut dengan sentuhan grid modernis.',
            'primary_color' => '#2e1a47',
            'accent_color' => '#9b7ed1',
        ],
        [
            'number' => 5,
            'display' => 'Sage Corporate',
            'slug' => 'sage-corporate',
            'engine' => 'dompdf',
            'style_category' => 'corporate',
            'implemented' => true,
            'description' => 'Hijau sage menenangkan untuk laporan korporasi formal.',
            'primary_color' => '#1f3a2e',
            'accent_color' => '#6f8f7a',
        ],
        [
            'number' => 6,
            'display' => 'Terracotta Heritage',
            'slug' => 'terracotta-heritage',
            'engine' => 'dompdf',
            'style_category' => 'heritage',
            'implemented' => true,
            'description' => 'Terakota hangat dengan nuansa warisan dan dokumen klasik.',
            'primary_color' => '#3d1e10',
            'accent_color' => '#b35a3a',
        ],
        [
            'number' => 7,
            'display' => 'Geometric Tech',
            'slug' => 'geometric-tech',
            'engine' => 'dompdf',
            'style_category' => 'technical',
            'implemented' => true,
            'description' => 'Aksen geometris dan biru sian untuk laporan teknis.',
            'primary_color' => '#0f1e2e',
            'accent_color' => '#00b4d8',
        ],
        [
            'number' => 8,
            'display' => 'Slate Architectural',
            'slug' => 'slate-architectural',
            'engine' => 'dompdf',
            'style_category' => 'technical',
            'implemented' => true,
            'description' => 'Abu-abu slate dengan struktur cetak biru arsitektural.',
            'primary_color' => '#1c2733',
            'accent_color' => '#5a6c7d',
        ],
        [
            'number' => 9,
            'display' => 'Newsprint Gazette',
            'slug' => 'newsprint-gazette',
            'engine' => 'dompdf',
            'style_category' => 'editorial',
            'implemented' => true,
            'description' => 'Estetika koran lawas dengan serif padat dan kolom rapat.',
            'primary_color' => '#1a1a1a',
            'accent_color' => '#8a7f6a',
        ],
        [
            'number' => 10,
            'display' => 'Onyx Premium',
            'slug' => 'onyx-premium',
            'engine' => 'dompdf',
            'style_category' => 'premium',
            'implemented' => true,
            'description' => 'Hitam onyx dengan finishing platinum untuk dokumen premium.',
            'primary_color' => '#0a0a0a',
            'accent_color' => '#9aa0a6',
        ],
        [
            'number' => 11,
            'display' => 'Bauhaus Primary',
            'slug' => 'bauhaus-primary',
            'engine' => 'dompdf',
            'style_category' => 'modern',
            'implemented' => true,
            'description' => 'Warna primer Bauhaus (merah, kuning, biru) pada grid modernis.',
            'primary_color' => '#1d1d1d',
            'accent_color' => '#e63946',
        ],
        [
            'number' => 12,
            'display' => 'Japandi Zen',
            'slug' => 'japandi-zen',
            'engine' => 'dompdf',
            'style_category' => 'minimalist',
            'implemented' => true,
            'description' => 'Perpaduan Jepang–Skandinavia: krem, kayu hangat, dan ruang kosong.',
            'primary_color' => '#2b2a26',
            'accent_color' => '#a68a64',
        ],
        [
            'number' => 13,
            'display' => 'Brutalist Mono',
            'slug' => 'brutalist-mono',
            'engine' => 'dompdf',
            'style_category' => 'technical',
            'implemented' => true,
            'description' => 'Mono-spaced brutalist dengan garis tegas hitam-putih.',
            'primary_color' => '#000000',
            'accent_color' => '#1a1a1a',
        ],
        [
            'number' => 14,
            'display' => 'Memphis Postmodern',
            'slug' => 'memphis-postmodern',
            'engine' => 'dompdf',
            'style_category' => 'modern',
            'implemented' => true,
            'description' => 'Pola Memphis post-modern dengan warna ceria dan bentuk geometri.',
            'primary_color' => '#1a1a40',
            'accent_color' => '#ff4f7e',
        ],
        [
            'number' => 15,
            'display' => 'Art Deco Gold',
            'slug' => 'art-deco-gold',
            'engine' => 'dompdf',
            'style_category' => 'premium',
            'implemented' => true,
            'description' => 'Art Deco simetris dengan ornamen emas mewah.',
            'primary_color' => '#1c1410',
            'accent_color' => '#d4af37',
        ],
        [
            'number' => 16,
            'display' => 'Botanical Vintage',
            'slug' => 'botanical-vintage',
            'engine' => 'dompdf',
            'style_category' => 'heritage',
            'implemented' => true,
            'description' => 'Ilustrasi botani vintage dengan hijau zaitun dan krem antik.',
            'primary_color' => '#2d3a23',
            'accent_color' => '#7a8c5a',
        ],
        [
            'number' => 17,
            'display' => 'Swiss International',
            'slug' => 'swiss-international',
            'engine' => 'dompdf',
            'style_category' => 'minimalist',
            'implemented' => true,
            'description' => 'Gaya International Swiss: grid tegas, Helvetica, aksen merah ikonik.',
            'primary_color' => '#000000',
            'accent_color' => '#e30613',
        ],
        [
            'number' => 18,
            'display' => 'Risograph Duotone',
            'slug' => 'risograph-duotone',
            'engine' => 'browsershot',
            'style_category' => 'modern',
            'implemented' => false,
            'description' => 'Efek cetak risograph duotone dengan tekstur dan tinta bertumpuk.',
            'primary_color' => '#1a2e80',
            'accent_color' => '#ff5c8a',
        ],
        [
            'number' => 19,
            'display' => 'Manuscript Vellum',
            'slug' => 'manuscript-vellum',
            'engine' => 'dompdf',
            'style_category' => 'heritage',
            'implemented' => true,
            'description' => 'Manuskrip pada kertas vellum dengan kapital iluminasi.',
            'primary_color' => '#3a2a14',
            'accent_color' => '#8a5a2a',
        ],
        [
            'number' => 20,
            'display' => 'Cyber Glass',
            'slug' => 'cyber-glass',
            'engine' => 'browsershot',
            'style_category' => 'premium',
            'implemented' => false,
            'description' => 'Glassmorphism futuristik dengan gradient neon dan blur halus.',
            'primary_color' => '#0a0e27',
            'accent_color' => '#00f0ff',
        ],
    ];
}
