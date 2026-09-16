<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7 — kanal telepon.
 *
 * Metode bawaan `otp_phone` diseed sebagai "Kode OTP ke telepon wali".
 * Yang dibangun bukan kode enam digit yang diketik di layar (orang di
 * depan layar bisa saja si anak yang membacakan kode dari telepon
 * orang tuanya), melainkan TAUTAN ke telepon wali lewat SMS/WhatsApp —
 * mekanisme yang sama dengan surel: wali membuka di perangkatnya sendiri,
 * MELIHAT, lalu MENYETUJUI. Labelnya disamakan dengan kenyataan.
 */
return new class extends Migration
{
    private const LAMA = 'Kode OTP ke telepon wali';

    private const BARU = 'Tautan verifikasi ke telepon wali (SMS/WhatsApp)';

    public function up(): void
    {
        DB::table('verification_methods')
            ->whereNull('org_id')
            ->where('code', 'otp_phone')
            ->where('label', self::LAMA)
            ->update(['label' => self::BARU, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('verification_methods')
            ->whereNull('org_id')
            ->where('code', 'otp_phone')
            ->where('label', self::BARU)
            ->update(['label' => self::LAMA, 'updated_at' => now()]);
    }
};
