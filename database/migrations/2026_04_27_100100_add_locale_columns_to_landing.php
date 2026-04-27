<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual landing — id (default) + en. Pakai pendekatan companion columns
 * (`*_en`) bukan JSON i18n supaya:
 *  - query SQL biasa tetap kerja (search, filter)
 *  - admin UI gampang: tab ID / EN
 *  - rollback per-language gampang (drop column)
 *
 * Locale resolve di public API: `?locale=en` → kalau *_en ada → return *_en;
 * else fallback ke ID. Field tanpa _en companion (URL, paths) selalu sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_settings', function (Blueprint $t) {
            $t->string('hero_eyebrow_en', 160)->nullable()->after('hero_eyebrow');
            $t->text('hero_headline_en')->nullable()->after('hero_headline');
            $t->text('hero_subheadline_en')->nullable()->after('hero_subheadline');
            $t->string('hero_cta_primary_label_en', 80)->nullable()->after('hero_cta_primary_label');
            $t->string('hero_cta_secondary_label_en', 80)->nullable()->after('hero_cta_secondary_label');
            $t->string('seo_title_en', 255)->nullable()->after('seo_title');
            $t->text('seo_description_en')->nullable()->after('seo_description');
            $t->text('contact_address_en')->nullable()->after('contact_address');
            $t->text('footer_about_en')->nullable()->after('footer_about');
            $t->string('footer_copyright_en', 255)->nullable()->after('footer_copyright');
        });

        Schema::table('landing_features', function (Blueprint $t) {
            $t->string('title_en', 220)->nullable()->after('title');
            $t->string('subtitle_en', 255)->nullable()->after('subtitle');
            $t->text('description_en')->nullable()->after('description');
            $t->string('category_en', 80)->nullable()->after('category');
            $t->string('cta_label_en', 80)->nullable()->after('cta_label');
        });

        Schema::table('landing_team_members', function (Blueprint $t) {
            $t->string('role_en', 160)->nullable()->after('role');
            $t->text('bio_en')->nullable()->after('bio');
        });

        Schema::table('landing_testimonials', function (Blueprint $t) {
            $t->text('quote_en')->nullable()->after('quote');
            $t->string('author_role_en', 220)->nullable()->after('author_role');
        });

        Schema::table('landing_products', function (Blueprint $t) {
            $t->string('name_en', 160)->nullable()->after('name');
            $t->string('tagline_en', 255)->nullable()->after('tagline');
            $t->text('description_en')->nullable()->after('description');
            // features + faqs are JSON; bilingual handled inside JSON shape: [{title, title_en, ...}]
        });

        Schema::table('landing_stats', function (Blueprint $t) {
            $t->string('label_en', 160)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('landing_settings', function (Blueprint $t) {
            $t->dropColumn(['hero_eyebrow_en', 'hero_headline_en', 'hero_subheadline_en',
                'hero_cta_primary_label_en', 'hero_cta_secondary_label_en',
                'seo_title_en', 'seo_description_en', 'contact_address_en',
                'footer_about_en', 'footer_copyright_en']);
        });
        Schema::table('landing_features', function (Blueprint $t) {
            $t->dropColumn(['title_en', 'subtitle_en', 'description_en', 'category_en', 'cta_label_en']);
        });
        Schema::table('landing_team_members', function (Blueprint $t) {
            $t->dropColumn(['role_en', 'bio_en']);
        });
        Schema::table('landing_testimonials', function (Blueprint $t) {
            $t->dropColumn(['quote_en', 'author_role_en']);
        });
        Schema::table('landing_products', function (Blueprint $t) {
            $t->dropColumn(['name_en', 'tagline_en', 'description_en']);
        });
        Schema::table('landing_stats', function (Blueprint $t) {
            $t->dropColumn(['label_en']);
        });
    }
};
