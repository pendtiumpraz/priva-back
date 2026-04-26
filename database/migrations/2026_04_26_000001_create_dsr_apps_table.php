<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSR Apps — registered klien external apps yang embed DSR widget.
 *
 * Mirror dari pattern consent_collection_points: 1 tenant punya N apps,
 * setiap app punya embed_token unik untuk public widget auth.
 *
 * Bedanya dengan consent: tiap DSR app punya default scope ke beberapa
 * Information System (data discovery), karena DSR fulfillment butuh tahu
 * di sistem mana data subject tersimpan.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dsr_apps')) return;

        try {
            Schema::create('dsr_apps', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('name', 200);
                $table->string('app_code', 32);                // for SQL Pack filename prefix
                $table->text('description')->nullable();
                $table->string('embed_token', 64)->unique();   // public widget auth
                $table->json('allowed_domains')->nullable();   // CORS whitelist for widget
                $table->json('default_information_system_ids')->nullable(); // pre-checked scope
                $table->uuid('default_assignee_user_id')->nullable();
                $table->text('webhook_url')->nullable();       // klien-side notification
                $table->json('branding')->nullable();          // {primary_color, logo_url, accent}
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['org_id', 'app_code']);
                $table->index(['org_id', 'is_active']);
                $table->index('embed_token');                  // fast public lookup
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1050 || in_array($e->getCode(), ['42P07', '42S01'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_apps');
    }
};
