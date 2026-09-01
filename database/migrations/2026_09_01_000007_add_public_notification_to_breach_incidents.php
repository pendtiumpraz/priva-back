<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi kepada masyarakat atas Kegagalan Pelindungan Data Pribadi —
 * PP 33/2026 Pasal 115. Wajib bila kegagalan (a) mengganggu pelayanan publik
 * dan/atau (b) berdampak serius terhadap kepentingan masyarakat; disampaikan
 * secara umum melalui media elektronik dan/atau nonelektronik.
 *
 * Sebelumnya modul breach hanya menotifikasi KOMDIGI/Lembaga + Subjek Data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->boolean('public_notification_required')->default(false)->after('notified_subjects_at');
            $table->json('public_notification_grounds')->nullable()->after('public_notification_required');
            $table->timestamp('notified_public_at')->nullable()->after('public_notification_grounds');
        });
    }

    public function down(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->dropColumn(['public_notification_required', 'public_notification_grounds', 'notified_public_at']);
        });
    }
};
