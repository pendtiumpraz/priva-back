<?php

namespace Database\Seeders;

use App\Models\Landing\LandingFeature;
use App\Models\Landing\LandingLogo;
use App\Models\Landing\LandingProduct;
use App\Models\Landing\LandingSetting;
use App\Models\Landing\LandingStat;
use App\Models\Landing\LandingTeamMember;
use App\Models\Landing\LandingTestimonial;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Idempotent — pakai firstOrCreate / updateOrCreate. Bilingual (id + en).
 *
 * Asset partner logos (49 files) + endorsement photos di-ship dalam repo
 * di database/seeders/landing-fixtures/. Saat seeder run, fixtures di-copy ke
 * storage/app/public/landing/ (yang diserve via /storage/landing/...).
 *
 * Jalankan:
 *   php artisan db:seed --class=LandingSeeder
 */
class LandingSeeder extends Seeder
{
    public function run(): void
    {
        $this->copyFixturesToStorage();
        $this->seedSettings();
        $this->seedStats();
        $this->seedFeatures();
        $this->seedProducts();
        $this->seedTeam();
        $this->seedTestimonials();
        $this->seedLogos();
    }

    /**
     * Copy asset fixtures dari repo ke storage/app/public/landing/.
     * Idempotent — skip kalau file sudah ada di destination.
     */
    private function copyFixturesToStorage(): void
    {
        $src = database_path('seeders/landing-fixtures');
        $dst = storage_path('app/public/landing');
        if (! is_dir($src)) {
            $this->command?->warn("Fixtures directory tidak ada: {$src}");

            return;
        }
        if (! is_dir($dst)) {
            File::makeDirectory($dst, 0755, true);
        }
        $copied = 0;
        foreach (['logos', 'testimonials', 'hero', 'features'] as $sub) {
            $srcSub = $src.DIRECTORY_SEPARATOR.$sub;
            $dstSub = $dst.DIRECTORY_SEPARATOR.$sub;
            if (! is_dir($srcSub)) {
                continue;
            }
            if (! is_dir($dstSub)) {
                File::makeDirectory($dstSub, 0755, true);
            }
            foreach (File::files($srcSub) as $f) {
                $target = $dstSub.DIRECTORY_SEPARATOR.$f->getFilename();
                if (! file_exists($target)) {
                    copy($f->getRealPath(), $target);
                    $copied++;
                }
            }
        }
        $this->command?->info("Copied {$copied} fixture file(s) ke storage/app/public/landing/");
    }

    private function seedSettings(): void
    {
        $existing = LandingSetting::query()->first();
        $defaults = [
            'hero_eyebrow' => '🇮🇩 Indonesia\'s First Privacy Management Tool',
            'hero_eyebrow_en' => '🇮🇩 Indonesia\'s First Privacy Management Tool',
            'hero_headline' => 'Compliance UU PDP yang akhirnya tidak rumit',
            'hero_headline_en' => 'PDP Compliance, finally without the headache',
            'hero_subheadline' => 'Privasimu adalah privacy management platform native Indonesia — multi-tenant, on-prem AI ready, white-label safe. Crafted Locally, Guarded Globally.',
            'hero_subheadline_en' => 'Privasimu is Indonesia\'s native privacy management platform — multi-tenant, on-prem AI ready, white-label safe. Crafted Locally, Guarded Globally.',
            'hero_image_path' => 'landing/features/arch_full_saas.jpg',
            'hero_cta_primary_label' => 'Request Demo',
            'hero_cta_primary_label_en' => 'Request Demo',
            'hero_cta_primary_url' => '/contact?intent=demo',
            'hero_cta_secondary_label' => 'Hubungi Tim',
            'hero_cta_secondary_label_en' => 'Contact Team',
            'hero_cta_secondary_url' => '/contact?intent=contact',
            'brand_logo_path' => 'landing/hero/privasimu_logo.png',
            'brand_primary_color' => '#1a4d8c',
            'brand_accent_color' => '#f59e0b',
            'seo_title' => 'Privasimu Nexus — Privacy Management Tool UU PDP Indonesia',
            'seo_title_en' => 'Privasimu Nexus — Privacy Management Platform for Indonesia',
            'seo_description' => 'Platform end-to-end UU PDP: ROPA, DPIA, DSR, Consent, Cross-Border, Vendor Risk, Breach. Crafted Locally, Guarded Globally.',
            'seo_description_en' => 'End-to-end PDP platform: ROPA, DPIA, DSR, Consent, Cross-Border, Vendor Risk, Breach. Crafted Locally, Guarded Globally.',
            'contact_email' => 'hello@privasimu.com',
            'contact_phone' => '+62 851-8318-2722',
            'contact_address' => 'Documenta HQ Jl. Yusuf Adiwinata No.34, Gondangdia, Menteng, Jakarta Pusat 10350',
            'contact_address_en' => 'Documenta HQ, 34 Yusuf Adiwinata St., Gondangdia, Menteng, Central Jakarta 10350, Indonesia',
            'social_linkedin' => 'https://linkedin.com/company/privasimu',
            'footer_about' => 'Privasimu adalah pelopor privacy management platform di Indonesia. Crafted Locally, Guarded Globally.',
            'footer_about_en' => 'Privasimu is Indonesia\'s pioneering privacy management platform. Crafted Locally, Guarded Globally.',
            'footer_copyright' => '© '.date('Y').' PT Privasimu. All rights reserved.',
            'footer_copyright_en' => '© '.date('Y').' PT Privasimu. All rights reserved.',
        ];
        if ($existing) {
            foreach ($defaults as $k => $v) {
                if (empty($existing->{$k})) {
                    $existing->{$k} = $v;
                }
            }
            $existing->save();
        } else {
            LandingSetting::create($defaults);
        }
    }

    private function seedStats(): void
    {
        $rows = [
            ['label' => 'Active DPOs', 'label_en' => 'Active DPOs', 'value' => '1,200+', 'icon_name' => 'Users'],
            ['label' => 'Modul PDP', 'label_en' => 'PDP Modules', 'value' => '9', 'icon_name' => 'LayoutGrid'],
            ['label' => 'Compliance Rate', 'label_en' => 'Compliance Rate', 'value' => '99.9%', 'icon_name' => 'ShieldCheck'],
            ['label' => 'Tahun di PDP', 'label_en' => 'Years in PDP', 'value' => '4+', 'icon_name' => 'Award'],
        ];
        foreach ($rows as $i => $row) {
            LandingStat::updateOrCreate(
                ['label' => $row['label']],
                array_merge($row, ['order_index' => $i, 'is_published' => true])
            );
        }
    }

    private function seedFeatures(): void
    {
        $rows = [
            ['title' => 'Gap Assessment', 'title_en' => 'Gap Assessment', 'icon_name' => 'ClipboardCheck',
                'description' => 'Smart PDP compliance check — fast, reliable, and regulation-ready. Expert-built questionnaire dengan instant compliance score.',
                'description_en' => 'Smart PDP compliance check — fast, reliable, and regulation-ready. Expert-built questionnaire with instant compliance score.',
                'category' => 'Privacy', 'category_en' => 'Privacy', 'cta_url' => '/products/gap-assessment'],
            ['title' => 'Record of Processing Activity (RoPA)', 'title_en' => 'Record of Processing Activity (RoPA)', 'icon_name' => 'Database',
                'description' => 'Mudah kelola data processing activities. Auto-detect expired data, fix retention policy, assign Maker & Approver. Pasal 33 UU PDP.',
                'description_en' => 'Easily manage data processing activities. Auto-detect expired data, fix retention policy, assign Maker & Approver. Article 33 PDP Law.',
                'category' => 'Privacy', 'category_en' => 'Privacy', 'cta_url' => '/products/ropa'],
            ['title' => 'Data Protection Impact Assessment (DPIA)', 'title_en' => 'Data Protection Impact Assessment (DPIA)', 'icon_name' => 'Shield',
                'description' => 'Comprehensive Risk Assessment dengan Probability×Impact Matrix (1-5) dan 22 Risk Libraries. Reduce manual errors.',
                'description_en' => 'Comprehensive Risk Assessment with Probability×Impact Matrix (1-5) and 22 Risk Libraries. Reduce manual errors.',
                'category' => 'Privacy', 'category_en' => 'Privacy', 'cta_url' => '/products/dpia'],
            ['title' => 'Data Discovery & Mapping', 'title_en' => 'Data Discovery & Mapping', 'icon_name' => 'Search',
                'description' => 'AI-powered scan data lake/storage, uncover hidden sensitive data (Data Umum, Spesifik, PII), map flow & access.',
                'description_en' => 'AI-powered scan of data lakes/storage, uncover hidden sensitive data (General, Specific, PII), map flow & access.',
                'category' => 'Security', 'category_en' => 'Security', 'cta_url' => '/products/data-discovery'],
            ['title' => 'Consent & Cookies Management', 'title_en' => 'Consent & Cookies Management', 'icon_name' => 'CookieIcon',
                'description' => 'Identify, capture, monitor consent across Web/App/Call Center. Honour revocation & preferences. Pasal 20 UU PDP.',
                'description_en' => 'Identify, capture, monitor consent across Web/App/Call Center. Honour revocation & preferences. Article 20 PDP Law.',
                'category' => 'Privacy', 'category_en' => 'Privacy', 'cta_url' => '/products/consent'],
            ['title' => 'Data Subject Access Request (DSAR)', 'title_en' => 'Data Subject Access Request (DSAR)', 'icon_name' => 'UserCheck',
                'description' => 'Kelola access, correction, deletion, opt-out requests. Track Handler→Reviewer→Approver workflow + audit trail.',
                'description_en' => 'Manage access, correction, deletion, opt-out requests. Track Handler→Reviewer→Approver workflow + audit trail.',
                'category' => 'Privacy', 'category_en' => 'Privacy', 'cta_url' => '/products/dsr'],
            ['title' => 'Third Party Risk Management (TPRM)', 'title_en' => 'Third Party Risk Management (TPRM)', 'icon_name' => 'Building2',
                'description' => 'Periodic third-party risk assessments, multi-user collaboration, auto risk scoring. Seamless ROPA integration.',
                'description_en' => 'Periodic third-party risk assessments, multi-user collaboration, auto risk scoring. Seamless ROPA integration.',
                'category' => 'Vendor Risk', 'category_en' => 'Vendor Risk', 'cta_url' => '/products/tprm'],
            ['title' => 'Data Breach Management', 'title_en' => 'Data Breach Management', 'icon_name' => 'AlertTriangle',
                'description' => 'Stay ahead of the 72-hour clock. Log activities, RACI Matrix, Digital War Room, Security Drill Test.',
                'description_en' => 'Stay ahead of the 72-hour clock. Log activities, RACI Matrix, Digital War Room, Security Drill Test.',
                'category' => 'Security', 'category_en' => 'Security', 'cta_url' => '/products/breach'],
            ['title' => 'Cross Border Data Transfer', 'title_en' => 'Cross Border Data Transfer', 'icon_name' => 'Globe',
                'description' => 'Adequacy Decision & Safeguards mapping. Registrasi transfer lintas batas otomatis. Pasal 56 UU PDP.',
                'description_en' => 'Adequacy Decision & Safeguards mapping. Automated cross-border transfer registry. Article 56 PDP Law.',
                'category' => 'Privacy', 'category_en' => 'Privacy', 'cta_url' => '/products/cross-border'],
            ['title' => 'Priva — DPO AI Agent', 'title_en' => 'Priva — DPO AI Agent', 'icon_name' => 'Bot',
                'description' => 'Agentic AI Privacy Compliance Orchestrator. Convert regulasi → internal controls. Auto-generate ROPA & run DPIA dalam jam, bukan minggu.',
                'description_en' => 'Agentic AI Privacy Compliance Orchestrator. Convert regulations → internal controls. Auto-generate ROPA & run DPIA in hours, not weeks.',
                'category' => 'AI Governance', 'category_en' => 'AI Governance', 'cta_url' => '/products/priva-ai'],
        ];
        foreach ($rows as $i => $row) {
            LandingFeature::updateOrCreate(
                ['title' => $row['title']],
                array_merge($row, [
                    'section' => 'capabilities',
                    'order_index' => $i,
                    'is_published' => true,
                    'cta_label' => 'Pelajari',
                    'cta_label_en' => 'Learn More',
                ])
            );
        }
    }

    private function seedProducts(): void
    {
        $rows = [
            ['slug' => 'gap-assessment', 'name' => 'Gap Assessment', 'name_en' => 'Gap Assessment',
                'tagline' => 'Smart PDP compliance check, regulation-ready.', 'tagline_en' => 'Smart PDP compliance check, regulation-ready.',
                'category' => 'privacy', 'icon_name' => 'ClipboardCheck'],
            ['slug' => 'ropa', 'name' => 'Record of Processing Activity', 'name_en' => 'Record of Processing Activity',
                'tagline' => 'Kelola data processing dengan confidence Pasal 33.', 'tagline_en' => 'Manage data processing with Article 33 confidence.',
                'category' => 'privacy', 'icon_name' => 'Database'],
            ['slug' => 'dpia', 'name' => 'Data Protection Impact Assessment', 'name_en' => 'Data Protection Impact Assessment',
                'tagline' => 'Probability × Impact, 22 risk libraries.', 'tagline_en' => 'Probability × Impact, 22 risk libraries.',
                'category' => 'privacy', 'icon_name' => 'Shield'],
            ['slug' => 'dsr', 'name' => 'Data Subject Access Request', 'name_en' => 'Data Subject Access Request',
                'tagline' => 'End-to-end DSAR workflow + embed widget.', 'tagline_en' => 'End-to-end DSAR workflow + embed widget.',
                'category' => 'privacy', 'icon_name' => 'UserCheck'],
            ['slug' => 'consent', 'name' => 'Consent & Cookies Management', 'name_en' => 'Consent & Cookies Management',
                'tagline' => '6 connection methods, 2-layer modal, B2B API.', 'tagline_en' => '6 connection methods, 2-layer modal, B2B API.',
                'category' => 'privacy', 'icon_name' => 'CookieIcon'],
            ['slug' => 'cross-border', 'name' => 'Cross-Border Data Transfer', 'name_en' => 'Cross-Border Data Transfer',
                'tagline' => 'TIA Pasal 56 dengan rubric deterministik.', 'tagline_en' => 'Article 56 TIA with deterministic rubric.',
                'category' => 'privacy', 'icon_name' => 'Globe'],
            ['slug' => 'tprm', 'name' => 'Third-Party Risk Management', 'name_en' => 'Third-Party Risk Management',
                'tagline' => 'Vendor risk scoring + DPA tracking + AI assess.', 'tagline_en' => 'Vendor risk scoring + DPA tracking + AI assess.',
                'category' => 'vendor_risk', 'icon_name' => 'Building2'],
            ['slug' => 'breach', 'name' => 'Data Breach Management', 'name_en' => 'Data Breach Management',
                'tagline' => '72-jam timer + RACI + War Room + Drill.', 'tagline_en' => '72-hour timer + RACI + War Room + Drill.',
                'category' => 'security', 'icon_name' => 'AlertTriangle'],
            ['slug' => 'data-discovery', 'name' => 'Data Discovery & Mapping', 'name_en' => 'Data Discovery & Mapping',
                'tagline' => 'AI-powered sensitive data scanner.', 'tagline_en' => 'AI-powered sensitive data scanner.',
                'category' => 'security', 'icon_name' => 'Search'],
            ['slug' => 'priva-ai', 'name' => 'Priva — DPO AI Agent', 'name_en' => 'Priva — DPO AI Agent',
                'tagline' => 'Agentic AI Privacy Compliance Orchestrator.', 'tagline_en' => 'Agentic AI Privacy Compliance Orchestrator.',
                'category' => 'ai_governance', 'icon_name' => 'Bot'],
        ];
        foreach ($rows as $i => $row) {
            LandingProduct::updateOrCreate(
                ['slug' => $row['slug']],
                array_merge($row, [
                    'order_index' => $i,
                    'is_published' => true,
                ])
            );
        }
    }

    private function seedTeam(): void
    {
        $rows = [
            ['name' => 'Tim Founder', 'role' => 'Co-Founder & CEO', 'role_en' => 'Co-Founder & CEO',
                'bio' => 'Privacy advocate dengan latar belakang kebijakan publik. CIPP/E, CIPM, FIP certified.',
                'bio_en' => 'Privacy advocate with public policy background. CIPP/E, CIPM, FIP certified.'],
            ['name' => 'Tim Tech Lead', 'role' => 'CTO & Architect', 'role_en' => 'CTO & Architect',
                'bio' => 'Membangun infrastruktur multi-tenant + on-prem AI deployment.',
                'bio_en' => 'Builds multi-tenant infrastructure + on-prem AI deployment.'],
            ['name' => 'Tim Compliance', 'role' => 'Head of Compliance', 'role_en' => 'Head of Compliance',
                'bio' => 'Hands-on PDP consultant lintas industri. Policy contributor SKKNI, RPP PDP.',
                'bio_en' => 'Hands-on PDP consultant across industries. Policy contributor SKKNI, RPP PDP.'],
            ['name' => 'Tim Engineer', 'role' => 'Lead Engineer', 'role_en' => 'Lead Engineer',
                'bio' => 'Full-stack engineer dengan fokus security + scale.',
                'bio_en' => 'Full-stack engineer focused on security + scale.'],
            ['name' => 'Tim DPO Advisor', 'role' => 'DPO Advisor', 'role_en' => 'DPO Advisor',
                'bio' => 'Expert hands-on DPO services untuk klien enterprise + government.',
                'bio_en' => 'Expert hands-on DPO services for enterprise + government clients.'],
            ['name' => 'Tim Customer Success', 'role' => 'Customer Success Lead', 'role_en' => 'Customer Success Lead',
                'bio' => 'Implementation specialist + ongoing client support.',
                'bio_en' => 'Implementation specialist + ongoing client support.'],
        ];
        foreach ($rows as $i => $row) {
            LandingTeamMember::updateOrCreate(
                ['name' => $row['name']],
                array_merge($row, [
                    'order_index' => $i,
                    'is_published' => true,
                ])
            );
        }
    }

    private function seedTestimonials(): void
    {
        $rows = [
            [
                'quote' => 'Penerapan kepatuhan terhadap Pelindungan Data Pribadi merupakan proses yang kompleks. Dalam konteks tersebut, Privacy Management Tool PRIVASIMU sebagai inovasi karya anak bangsa hadir sebagai solusi yang relevan dan efektif dalam mendukung pemenuhan kepatuhan.',
                'quote_en' => 'Implementing Personal Data Protection compliance is a complex process. In that context, the PRIVASIMU Privacy Management Tool — an innovation by Indonesian talent — emerges as a relevant and effective solution to support compliance fulfillment.',
                'author_name' => 'Prof. Dr. Sinta Dewi Rosadi, S.H., LL.M.',
                'author_role' => 'Guru Besar FH Universitas Padjadjaran, Ketua Dep. Hukum TIK-KI',
                'author_role_en' => 'Professor of Law, Universitas Padjadjaran · Chair of Dept. of ICT-IK Law',
                'author_photo_path' => 'landing/testimonials/sinta_dewi_rosadi.png',
                'is_featured' => true,
                'order_index' => 0,
            ],
            [
                'quote' => 'Di era AI saat ini dan masa depan, data menjadi bagian yang tidak terpisahkan dari berbagai bidang kehidupan manusia. Kepatuhan dan pelaporan terhadap regulasi privacy data menjadi sangat penting dan itu harus ditunjang dengan tools yang user friendly dan patuh terhadap regulasi privacy data. Privacy Management Tool PRIVASIMU hadir sebagai solusi yang tepat untuk berbagai kebutuhan pelaporan privacy data yang bisa dioperasikan oleh pengguna yang tidak memiliki latar belakang Teknologi Informasi yang mendalam.',
                'quote_en' => 'In the present and future AI era, data is inseparable from every aspect of human life. Compliance and reporting against privacy regulations is critical and must be supported by user-friendly tools that respect privacy data regulations. The PRIVASIMU Privacy Management Tool delivers the right solution for diverse privacy reporting needs and can be operated by users without deep IT background.',
                'author_name' => 'Prof. Dr. Ir. Ford Lumban Gaol, S.Si., M.Kom.',
                'author_role' => 'Professor of Computer Science — Binus University · Chair of IEEE Computer Science Indonesia · President of IAI ASEAN Region · Visiting Professor Sam Houston State University, Texas USA',
                'author_role_en' => 'Professor of Computer Science — Binus University · Chair of IEEE Computer Science Indonesia · President of IAI ASEAN Region · Visiting Professor Sam Houston State University, Texas USA',
                'author_photo_path' => 'landing/testimonials/ford_lumban_gaol.png',
                'is_featured' => true,
                'order_index' => 1,
            ],
        ];
        foreach ($rows as $row) {
            LandingTestimonial::updateOrCreate(
                ['author_name' => $row['author_name']],
                array_merge($row, ['is_published' => true, 'rating' => 5])
            );
        }
    }

    private function seedLogos(): void
    {
        $logoDir = storage_path('app/public/landing/logos');
        if (! is_dir($logoDir)) {
            $this->command?->warn("Logo directory tidak ada: {$logoDir} — skip seed logos");

            return;
        }

        $files = glob($logoDir.'/partner_*.png') ?: [];
        sort($files);
        $i = 0;
        foreach ($files as $absPath) {
            $filename = basename($absPath);
            $relPath = "landing/logos/{$filename}";
            $displayName = sprintf('Partner #%02d', $i + 1);
            LandingLogo::updateOrCreate(
                ['logo_path' => $relPath],
                [
                    'name' => $displayName,
                    'category' => 'partner',
                    'order_index' => $i,
                    'is_published' => true,
                ]
            );
            $i++;
        }
        $this->command?->info("Seeded {$i} partner logos dari PPTX Company Profile 2026.");
    }
}
