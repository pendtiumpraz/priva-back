<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TABEL LAMA — TIDAK ADA YANG MENULIS KE SINI.
 *
 * Penangkapan consent yang sebenarnya masuk ke `consent_logs`, lewat
 * ConsentLogController::capture dan ConsentApiV1Controller::capture. Tabel ini
 * pada praktiknya kosong; ConsentCollectionPoint::logs() dan
 * ConsentStateResolver sudah lama menyebutkannya.
 *
 * Alasan kedua, yang lebih menentukan: `subject_identifier` di sini tersandi
 * dengan IV acak, sehingga `where('subject_identifier', $x)` TIDAK AKAN PERNAH
 * cocok. Apa pun yang dibangun di atas tabel ini akan diam-diam menjawab "belum
 * pernah" untuk semua orang.
 *
 * Kalau hendak menambahkan kolom untuk fitur baru, TABEL INI BUKAN TEMPATNYA —
 * itu persis kekeliruan yang diperbaiki migrasi 2026_09_15_000008.
 */
class ConsentRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'consent_item_id', 'collection_point_id', 'subject_identifier',
        'subject_name', 'channel', 'is_granted', 'ip_address', 'user_agent',
        'proof', 'granted_at', 'revoked_at', 'revoke_reason', 'recorded_by',
    ];

    protected $casts = [
        'is_granted' => 'boolean', 'granted_at' => 'datetime', 'revoked_at' => 'datetime',
        // PII Encryption — AES-256-CBC
        'subject_identifier' => EncryptedString::class,
        'subject_name' => EncryptedString::class,
        'ip_address' => EncryptedString::class,
    ];
}
