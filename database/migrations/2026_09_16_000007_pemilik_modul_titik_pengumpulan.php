<?php

use App\Models\ConsentCollectionPoint;
use App\Support\ModulSubjek;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10 — tiap titik pengumpulan milik TEPAT SATU modul.
 *
 *   NULL                    modul Consent umum (dewasa)
 *   consent_guardian        Consent Wali — dibuat dari /consent-guardian
 *   consent_accessibility   Consent Aksesibilitas — dibuat dari /consent-accessibility
 *
 * Satu tabel tetap (widget, embed, item, webhook, /v1/consent tidak difork);
 * yang dipisah hanya siapa yang mengelolanya. Titik yang sudah menyalakan
 * `settings.guardian_mode` — selama ini satu-satunya cara membuat titik anak —
 * dipindahkan ke Consent Wali. Dilakukan lewat PHP, bukan kueri JSON, supaya
 * sama di SQLite, Postgres, dan MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consent_collection_points', 'owner_module')) {
            Schema::table('consent_collection_points', function (Blueprint $t) {
                $t->string('owner_module', 32)->nullable()->index('ccp_owner_module_idx');
            });
        }

        ConsentCollectionPoint::withoutGlobalScopes()
            ->withTrashed()
            ->whereNull('owner_module')
            ->chunkById(200, function ($titik) {
                foreach ($titik as $cp) {
                    if (($cp->settings['guardian_mode'] ?? false) === true) {
                        DB::table('consent_collection_points')
                            ->where('id', $cp->id)
                            ->update(['owner_module' => ModulSubjek::GUARDIAN]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('consent_collection_points', 'owner_module')) {
            Schema::table('consent_collection_points', function (Blueprint $t) {
                $t->dropIndex('ccp_owner_module_idx');
                $t->dropColumn('owner_module');
            });
        }
    }
};
