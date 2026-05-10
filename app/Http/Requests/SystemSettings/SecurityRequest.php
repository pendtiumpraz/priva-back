<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Security knobs — login lockout per akun.
 *
 * Berlaku platform-wide (bukan per tenant). State counter/locked_until ada di
 * kolom users; threshold & durasi-nya yang disimpan di system_settings dan
 * divalidasi di sini.
 *
 * Contract:
 *   - tier1 < tier2 < tier3 (jumlah attempts naik per tier)
 *   - tier1 lock < tier2 lock < tier3 lock (durasi lock naik per tier)
 * Tidak di-enforce di sini supaya admin bebas eksperimen, tapi UI kasih hint.
 */
class SecurityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Login lockout — semua optional di payload (partial save). Boundaries
            // di-tegakkan kalau dikirim. Kalau kosong, frontend gak boleh strip
            // mereka — tapi `sometimes` lebih aman daripada `required`.
            'lockout_enabled' => 'sometimes|boolean',
            'lockout_tier1_attempts' => 'sometimes|integer|min:1|max:50',
            'lockout_tier1_seconds' => 'sometimes|integer|min:5|max:86400',
            'lockout_tier2_attempts' => 'sometimes|integer|min:1|max:100',
            'lockout_tier2_seconds' => 'sometimes|integer|min:5|max:86400',
            'lockout_tier3_attempts' => 'sometimes|integer|min:1|max:200',
            'lockout_tier3_seconds' => 'sometimes|integer|min:5|max:604800',
            'lockout_window_minutes' => 'sometimes|integer|min:1|max:1440',

            // Password policy — min length 8 (jangan di bawah ini, OWASP min),
            // max 128 (sane upper bound; bcrypt accepts up to 72 char anyway,
            // tapi user input bisa lebih panjang).
            'password_min_length' => 'sometimes|integer|min:8|max:128',
            'password_require_uppercase' => 'sometimes|boolean',
            'password_require_lowercase' => 'sometimes|boolean',
            'password_require_digit' => 'sometimes|boolean',
            'password_require_symbol' => 'sometimes|boolean',
            'password_block_common' => 'sometimes|boolean',
            'password_block_email_match' => 'sometimes|boolean',

            // Response headers — string fields jelas terbatas (frame_options
            // hanya 2 nilai valid, sisanya free-form karena banyak varian).
            'headers_enabled' => 'sometimes|boolean',
            'headers_hsts_enabled' => 'sometimes|boolean',
            'headers_hsts_max_age' => 'sometimes|integer|min:0|max:63072000', // max 2 tahun
            'headers_frame_options' => 'sometimes|string|in:DENY,SAMEORIGIN',
            'headers_referrer_policy' => 'sometimes|string|max:255',
            'headers_permissions_policy' => 'sometimes|string|max:1024',

            // CORS allowlist — array of origin strings. Setiap entry harus
            // valid URL (http/https + host). Array kosong = tolak semua
            // cross-origin (extreme tapi valid).
            'cors_allowed_origins' => 'sometimes|array|max:50',
            'cors_allowed_origins.*' => ['string', 'max:255', 'regex:#^https?://[^/]+$#i'],
            'cors_allow_credentials' => 'sometimes|boolean',
            'cors_max_age_seconds' => 'sometimes|integer|min:0|max:86400', // max 24 jam

            // Token expiry — min 60 menit (1 jam) supaya tidak terlalu agresif
            // logout user, max 525600 (1 tahun) supaya tetap ada hard expiry.
            // 0 = never expire, sengaja tidak diperbolehkan dari UI (defeats
            // the purpose). Admin yang mau disable bisa edit DB langsung.
            'token_lifetime_minutes' => 'sometimes|integer|min:60|max:525600',
            // Threshold 1-99 (0 = no refresh, 100 = refresh setelah expired,
            // dua-duanya ngaco).
            'token_refresh_threshold_pct' => 'sometimes|integer|min:1|max:99',

            // AI prompt size — char count.
            // prompt: min 1000 (sangat ketat — chat singkat aja), max 200000
            //   (200K char ≈ 50K token, di atas itu sebagian besar provider
            //   tolak by their own context window).
            // message: min 100 (1 kalimat), max sama dengan prompt.
            // attachment: min 500, max sama dengan prompt.
            'ai_max_prompt_chars' => 'sometimes|integer|min:1000|max:200000',
            'ai_max_message_chars' => 'sometimes|integer|min:100|max:200000',
            'ai_max_attachment_chars' => 'sometimes|integer|min:500|max:200000',

            // 2FA toggles — semua boolean.
            '2fa_enabled' => 'sometimes|boolean',
            '2fa_required_for_root' => 'sometimes|boolean',
            '2fa_required_for_superadmin' => 'sometimes|boolean',
            '2fa_required_for_admin' => 'sometimes|boolean',
            '2fa_required_for_dpo' => 'sometimes|boolean',
        ];
    }
}
