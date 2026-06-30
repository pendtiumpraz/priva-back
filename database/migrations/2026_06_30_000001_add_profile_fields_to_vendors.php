<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi TPRM #1 + #6 — lengkapi profil "Pihak Ketiga" supaya vendor bisa
 * mengisi data identitas legal dari form publik (PUT /asesmen-publik/{token}/profil):
 *   - npwp        : Nomor Pokok Wajib Pajak — PII pajak, di-encrypt at-rest
 *                   (pola sama dgn contact_name/contact_email via EncryptedString).
 *   - alamat      : alamat lengkap perusahaan (free text, bukan di-encrypt).
 *   - telepon     : nomor telepon PIC/perusahaan — PII kontak, di-encrypt at-rest.
 *   - pic_jabatan : jabatan PIC (mis. "Manajer Legal") — bukan PII sensitif.
 *
 * Kolom encrypt memakai tipe `string` mengikuti contact_name/contact_email yang
 * sudah ada (ciphertext payload AES-256-CBC untuk nilai pendek muat di VARCHAR 255).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('npwp')->nullable()->after('contact_email');         // encrypted (EncryptedString)
            $table->text('alamat')->nullable()->after('npwp');                   // plain text
            $table->string('telepon')->nullable()->after('alamat');              // encrypted (EncryptedString)
            $table->string('pic_jabatan', 255)->nullable()->after('telepon');    // plain
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['npwp', 'alamat', 'telepon', 'pic_jabatan']);
        });
    }
};
