<?php

namespace App\Services;

/**
 * PII Detector — Regex + Pattern Matching for Indonesian Data
 * Detects: Nama, Email, Telepon, NIK, NPWP, Kartu Kredit, Alamat, Kesehatan, dll.
 */
class PiiDetector
{
    /**
     * Analyze a column by name + type and return PII classification
     */
    public static function analyze(string $columnName, string $columnType): array
    {
        $name = strtolower($columnName);
        $type = strtolower($columnType);

        // =============================================
        // KATEGORI SPESIFIK (Pasal 4 UU PDP — risiko tinggi)
        // =============================================

        // NIK / KTP
        if (self::match($name, ['nik', 'ktp', 'id_card', 'national_id', 'identity_number', 'no_ktp', 'nomor_ktp'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'NIK/KTP (ID nasional) – data spesifik Pasal 4 UU PDP');
        }

        // NPWP
        if (self::match($name, ['npwp', 'tax_id', 'tax_number', 'nomor_pajak'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'NPWP (nomor pajak) – data spesifik');
        }

        // Data Kesehatan / Medis
        if (self::match($name, ['health', 'medical', 'diagnos', 'disease', 'sakit', 'kesehatan', 'rekam_medis', 'bpjs', 'insurance_id'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'Data kesehatan/medis – data spesifik Pasal 4 UU PDP');
        }

        // Biometrik
        if (self::match($name, ['fingerprint', 'biometric', 'retina', 'iris', 'sidik_jari', 'face_id'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'Data biometrik – data spesifik');
        }

        // Agama / Keyakinan
        if (self::match($name, ['religion', 'faith', 'agama', 'kepercayaan', 'belief'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'Agama/keyakinan – data spesifik');
        }

        // Data Keuangan / Kartu
        if (self::match($name, ['card_number', 'credit_card', 'debit_card', 'card_no', 'cvv', 'pan', 'account_number', 'bank_account', 'rekening', 'nomor_rekening'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'Nomor kartu/rekening – data keuangan spesifik');
        }

        // Gaji / Kompensasi
        if (self::match($name, ['salary', 'gaji', 'wage', 'income', 'compensation', 'tunjangan', 'bonus'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'Data keuangan karyawan – data spesifik');
        }

        // Data Hukum / Kriminal
        if (self::match($name, ['criminal', 'criminal_record', 'arrest', 'conviction', 'catatan_kriminal', 'skck'])) {
            return self::result(true, 'spesifik', 'sensitive', true, 'Catatan kriminal – data spesifik');
        }

        // =============================================
        // KATEGORI UMUM (Pasal 4 UU PDP — data pribadi biasa)
        // =============================================

        // Nama
        if (self::match($name, ['name', 'full_name', 'nama', 'nama_lengkap', 'first_name', 'last_name', 'nama_depan', 'nama_belakang', 'surname'])) {
            return self::result(true, 'umum', 'pii', false, 'Nama lengkap/sebagian – data pribadi');
        }

        // Email
        if (self::match($name, ['email', 'email_address', 'surel', 'e_mail'])) {
            return self::result(true, 'umum', 'pii', false, 'Alamat email – data pribadi');
        }

        // Nomor Telepon
        if (self::match($name, ['phone', 'mobile', 'telepon', 'hp', 'handphone', 'no_hp', 'nomor_hp', 'phone_number', 'cell', 'whatsapp'])) {
            return self::result(true, 'umum', 'pii', false, 'Nomor telepon – data pribadi');
        }

        // Tanggal Lahir
        if (self::match($name, ['birth', 'birthday', 'dob', 'date_of_birth', 'tanggal_lahir', 'tgl_lahir', 'born_at'])) {
            return self::result(true, 'umum', 'pii', false, 'Tanggal lahir – data pribadi');
        }

        // Alamat
        if (self::match($name, ['address', 'alamat', 'addr', 'street', 'jalan', 'kelurahan', 'kecamatan', 'kabupaten', 'kota', 'kodepos', 'zipcode', 'postal'])) {
            return self::result(true, 'umum', 'pii', false, 'Alamat fisik – data pribadi');
        }

        // IP Address
        if (self::match($name, ['ip_address', 'ip_addr', 'client_ip', 'remote_addr', 'ipv4', 'ipv6'])) {
            return self::result(true, 'umum', 'pii', false, 'Alamat IP – data pribadi digital');
        }

        // Lokasi / GPS
        if (self::match($name, ['latitude', 'longitude', 'location', 'coordinate', 'lat', 'lng', 'gps', 'geolocation'])) {
            return self::result(true, 'umum', 'pii', false, 'Data lokasi/GPS – data pribadi');
        }

        // Jenis Kelamin
        if (self::match($name, ['gender', 'sex', 'jenis_kelamin'])) {
            return self::result(true, 'umum', 'pii', false, 'Jenis kelamin – data pribadi');
        }

        // Username / User ID
        if (self::match($name, ['username', 'user_name', 'login', 'user_id', 'userid', 'handle'])) {
            return self::result(true, 'umum', 'pii', false, 'Username – dapat mengidentifikasi individu');
        }

        // Photo / Avatar
        if (self::match($name, ['photo', 'avatar', 'profile_image', 'foto', 'picture'])) {
            return self::result(true, 'umum', 'pii', false, 'Foto profil – data pribadi visual');
        }

        // =============================================
        // BUKAN PII
        // =============================================
        return self::result(false, null, 'internal', false, null);
    }

    private static function match(string $columnName, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($columnName, $kw)) return true;
        }
        return false;
    }

    private static function result(bool $isPii, ?string $pdpCategory, string $classification, bool $encryptionRequired, ?string $reason): array
    {
        return [
            'is_pii' => $isPii,
            'pdp_category' => $pdpCategory,
            'classification' => $classification,
            'encryption_required' => $encryptionRequired,
            'reason' => $reason,
        ];
    }
}
