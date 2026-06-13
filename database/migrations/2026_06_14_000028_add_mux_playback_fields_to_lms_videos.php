<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mux signed playback (M1).
 *
 * lms_videos.external_id already holds the playback id (public today). Signed
 * playback needs to know which videos require a JWT, plus the Mux asset id for
 * management/debugging. Both columns are additive and default to the existing
 * behaviour (public) so pre-M1 rows keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_videos', function (Blueprint $table) {
            // 'public' = playback id is enough; 'signed' = FE must fetch a JWT.
            $table->string('playback_policy', 16)->default('public')->after('external_id');
            // Mux asset id (distinct from the playback id in external_id). Null
            // for YouTube and for Mux rows added by pasting a bare playback id.
            $table->string('mux_asset_id')->nullable()->after('playback_policy');
        });
    }

    public function down(): void
    {
        Schema::table('lms_videos', function (Blueprint $table) {
            $table->dropColumn(['playback_policy', 'mux_asset_id']);
        });
    }
};
