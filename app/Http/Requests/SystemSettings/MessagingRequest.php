<?php

namespace App\Http\Requests\SystemSettings;

use App\Services\Pesan\KanalPesan;
use App\Support\NomorTelepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kanal pesan singkat (SMS / WhatsApp) — gateway HTTP generik platform.
 * `sms_http_headers` dan `sms_http_secret` terenkripsi saat disimpan;
 * "***" atau kosong berarti "biarkan yang tersimpan".
 *
 * Seksi ini opsional: driver `off` adalah keadaan sah — kanal telepon
 * tidak ditawarkan widget, dan kontak telepon ditolak terbuka.
 */
class MessagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $jsonObjek = function (string $attr, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '' || $value === '***') {
                return;
            }
            $d = json_decode((string) $value, true);
            if (! is_array($d)) {
                $fail('Harus berupa JSON objek, mis. {"Authorization": "Bearer {secret}"}.');
            }
        };

        return [
            'sms_driver' => ['nullable', Rule::in(KanalPesan::DRIVERS)],
            'sms_http_url' => 'required_if:sms_driver,http|nullable|url|max:500',
            'sms_http_method' => ['nullable', Rule::in(['GET', 'POST', 'get', 'post'])],
            'sms_http_headers' => ['nullable', 'string', 'max:4000', $jsonObjek],
            'sms_http_secret' => 'nullable|string|max:2000',
            'sms_http_body' => ['nullable', 'string', 'max:4000', $jsonObjek],
            'sms_http_success_path' => 'nullable|string|max:200',
            'sms_http_success_equals' => 'nullable|string|max:200',
            'sms_to_format' => ['nullable', Rule::in(NomorTelepon::BENTUK)],
            'sms_sender_name' => 'nullable|string|max:40',
        ];
    }
}
