<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landing page system tables — singleton settings + ordered lists.
 * NOT scoped per-tenant: this is Privasimu's own marketing site, managed
 * exclusively by `root` and `superadmin` roles. See RootOrSuperadmin middleware.
 *
 * Plan dokumen: docs/LANDING_PAGE_PLAN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Singleton-style global settings (1 row enforced by app logic)
        Schema::create('landing_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Hero
            $t->string('hero_eyebrow', 160)->nullable();
            $t->text('hero_headline')->nullable();
            $t->text('hero_subheadline')->nullable();
            $t->string('hero_image_path', 500)->nullable();
            $t->string('hero_video_url', 500)->nullable();
            $t->string('hero_cta_primary_label', 80)->nullable();
            $t->string('hero_cta_primary_url', 500)->nullable();
            $t->string('hero_cta_secondary_label', 80)->nullable();
            $t->string('hero_cta_secondary_url', 500)->nullable();
            // Branding
            $t->string('brand_logo_path', 500)->nullable();
            $t->string('brand_favicon_path', 500)->nullable();
            $t->string('brand_primary_color', 24)->default('#1a4d8c');
            $t->string('brand_accent_color', 24)->default('#f59e0b');
            // SEO
            $t->string('seo_title', 255)->nullable();
            $t->text('seo_description')->nullable();
            $t->string('seo_og_image_path', 500)->nullable();
            // Contact
            $t->string('contact_email', 200)->nullable();
            $t->string('contact_phone', 60)->nullable();
            $t->text('contact_address')->nullable();
            // Social
            $t->string('social_linkedin', 500)->nullable();
            $t->string('social_twitter', 500)->nullable();
            $t->string('social_youtube', 500)->nullable();
            $t->string('social_instagram', 500)->nullable();
            // Footer
            $t->text('footer_about')->nullable();
            $t->string('footer_copyright', 255)->nullable();
            $t->timestamps();
        });

        // 2) Feature cards (homepage + product pages)
        Schema::create('landing_features', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('section', 60)->default('capabilities'); // hero | products | capabilities | why_us | integration
            $t->string('icon_name', 80)->nullable(); // lucide-react component name
            $t->string('icon_image_path', 500)->nullable();
            $t->string('title', 220);
            $t->string('subtitle', 255)->nullable();
            $t->text('description')->nullable();
            $t->string('screenshot_path', 500)->nullable();
            $t->string('category', 80)->nullable();
            $t->string('cta_label', 80)->nullable();
            $t->string('cta_url', 500)->nullable();
            $t->integer('order_index')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
            $t->index(['section', 'order_index']);
        });

        // 3) Team members — paginated 12/page on /about
        Schema::create('landing_team_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->string('role', 160);
            $t->text('bio')->nullable();
            $t->string('photo_path', 500)->nullable();
            $t->string('linkedin_url', 500)->nullable();
            $t->string('twitter_url', 500)->nullable();
            $t->string('email', 200)->nullable();
            $t->integer('order_index')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
            $t->index('order_index');
        });

        // 4) Testimonials / endorsements — featured row spotlit on hero, others in carousel
        Schema::create('landing_testimonials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->text('quote');
            $t->string('author_name', 160);
            $t->string('author_role', 220)->nullable();
            $t->string('author_company', 160)->nullable();
            $t->string('author_photo_path', 500)->nullable();
            $t->string('company_logo_path', 500)->nullable();
            $t->tinyInteger('rating')->default(5);
            $t->integer('order_index')->default(0);
            $t->boolean('is_featured')->default(false);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
            $t->index('order_index');
        });

        // 5) Logos — partners / customers / integrations
        Schema::create('landing_logos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->string('logo_path', 500);
            $t->string('category', 40)->default('partner'); // customer | partner | integration
            $t->string('link_url', 500)->nullable();
            $t->integer('order_index')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
            $t->index(['category', 'order_index']);
        });

        // 6) Leads — submissions dari Contact Us + Request Demo form di landing
        //    (Pengganti pricing — harga TIDAK dipublish karena banyak klien gov.)
        Schema::create('landing_leads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->string('email', 200);
            $t->string('phone', 60)->nullable();
            $t->string('company', 200)->nullable();
            $t->string('job_title', 160)->nullable();
            $t->string('industry', 80)->nullable(); // gov | finance | healthcare | dll
            $t->integer('employee_count')->nullable();
            $t->enum('intent', ['contact', 'demo', 'partnership', 'other'])->default('contact');
            $t->text('message')->nullable();
            $t->string('source', 80)->default('landing'); // landing | referral | event | dll
            $t->string('utm_source', 120)->nullable();
            $t->string('utm_medium', 120)->nullable();
            $t->string('utm_campaign', 120)->nullable();
            $t->string('ip_address', 64)->nullable();
            $t->text('user_agent')->nullable();
            $t->enum('status', ['new', 'contacted', 'qualified', 'converted', 'rejected'])->default('new');
            $t->text('admin_notes')->nullable();
            $t->uuid('handled_by_user_id')->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->index('status');
            $t->index('created_at');
            $t->index('email');
        });

        // 7) Products — for /products and /products/[slug]
        Schema::create('landing_products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 120)->unique();
            $t->string('name', 160);
            $t->string('tagline', 255)->nullable();
            $t->text('description')->nullable();
            $t->string('hero_image_path', 500)->nullable();
            $t->string('icon_name', 80)->nullable();
            $t->json('features')->nullable(); // [{title, description, screenshot_path}]
            $t->json('faqs')->nullable();     // [{q, a}]
            $t->string('category', 60)->default('privacy'); // privacy | security | ai_governance | vendor_risk
            $t->integer('order_index')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
            $t->index(['category', 'order_index']);
        });

        // 8) Stats — "10M+ users" etc, displayed in stats bar
        Schema::create('landing_stats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('label', 160);
            $t->string('value', 80);
            $t->string('icon_name', 80)->nullable();
            $t->integer('order_index')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
            $t->index('order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_stats');
        Schema::dropIfExists('landing_products');
        Schema::dropIfExists('landing_leads');
        Schema::dropIfExists('landing_logos');
        Schema::dropIfExists('landing_testimonials');
        Schema::dropIfExists('landing_team_members');
        Schema::dropIfExists('landing_features');
        Schema::dropIfExists('landing_settings');
    }
};
