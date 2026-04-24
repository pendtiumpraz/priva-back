<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RTP (Risk Treatment Plan) Phase 1 — Quick-Win embedded tracker.
 *
 * Tambah kolom `mitigation_tracking` JSON ke tabel `dpias` untuk simpan
 * daftar action items per DPIA dengan status, owner, deadline, evidence.
 *
 * Struktur per item:
 *   [
 *     {
 *       "id": "uuid",
 *       "risk_event": "Data bocor via phishing",
 *       "category": "Integritas dan Kerahasiaan",
 *       "treatment_type": "reduce|avoid|transfer|accept",
 *       "action": "MFA mandatory + phishing training quarterly",
 *       "rationale": "Phishing = top threat vector",
 *       "owner_user_id": "uuid",
 *       "priority": "critical|high|medium|low",
 *       "due_date": "2026-06-01",
 *       "status": "planned|in_progress|implemented|verified|overdue|on_hold|cancelled",
 *       "inherent_likelihood": 4,
 *       "inherent_impact": 5,
 *       "residual_likelihood": 2,
 *       "residual_impact": 3,
 *       "evidence_files": ["file_uuid_1"],
 *       "notes": "...",
 *       "started_at": "2026-04-25T10:00:00Z",
 *       "completed_at": null,
 *       "verified_at": null,
 *       "verified_by": null,
 *       "created_at": "...",
 *       "updated_at": "..."
 *     }
 *   ]
 *
 * Phase 2 akan migrasi ke polymorphic `risk_treatments` table untuk cross-source
 * (DPIA + Breach + Vendor + GAP). Quick-Win embedded dulu untuk demo cepat.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('dpias', 'mitigation_tracking')) return;

        try {
            Schema::table('dpias', function (Blueprint $table) {
                $table->json('mitigation_tracking')->nullable()->after('mitigation_measures');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            // MySQL 1060 / PG 42701 = column already exists
            if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('dpias', 'mitigation_tracking')) return;
        try {
            Schema::table('dpias', function (Blueprint $table) {
                $table->dropColumn('mitigation_tracking');
            });
        } catch (\Illuminate\Database\QueryException $e) { /* ignore */ }
    }
};
