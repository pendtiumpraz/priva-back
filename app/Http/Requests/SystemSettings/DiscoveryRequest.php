<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saklar platform untuk penemuan data store.
 *
 * Berlaku platform-wide dan berada di tangan superadmin, bukan tenant.
 * Alasannya bukan hierarki melainkan tanggung jawab: pemindaian jaringan
 * dijalankan oleh infrastruktur KAMI terhadap jaringan KLIEN. Kalau tenant
 * dapat menyalakannya sendiri, penyedia platform menanggung akibat dari
 * tindakan yang tidak pernah ia setujui.
 *
 * Nilai bawaan `active_scan_allowed` adalah FALSE, dan itu disengaja: di
 * hampir semua bank, pemindaian jaringan dibatasi kebijakan keamanan internal
 * dan akan memicu alarm SOC mereka sendiri.
 */
class DiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Mode bawaan bagi tenant yang belum mengatur apa pun.
            'default_mode' => 'sometimes|in:passive,active',

            // Saklar utama. Selama false, tidak ada tenant yang dapat
            // menjalankan pemindaian aktif betapapun lengkap konfigurasinya.
            'active_scan_allowed' => 'sometimes|boolean',

            // Batas jumlah host per pemindaian. Mencegah satu kekeliruan
            // menuliskan CIDR (mis. /8) berubah menjadi pemindaian belasan juta
            // alamat di jaringan klien.
            'active_scan_max_hosts' => 'sometimes|integer|min:1|max:65536',

            // Batas waktu sambungan per host, dalam milidetik.
            'active_scan_timeout_ms' => 'sometimes|integer|min:50|max:5000',
        ];
    }
}
