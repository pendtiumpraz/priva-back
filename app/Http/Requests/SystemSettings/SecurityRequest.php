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
            'lockout_enabled' => 'required|boolean',

            'lockout_tier1_attempts' => 'required|integer|min:1|max:50',
            'lockout_tier1_seconds' => 'required|integer|min:5|max:86400',
            'lockout_tier2_attempts' => 'required|integer|min:1|max:100',
            'lockout_tier2_seconds' => 'required|integer|min:5|max:86400',
            'lockout_tier3_attempts' => 'required|integer|min:1|max:200',
            'lockout_tier3_seconds' => 'required|integer|min:5|max:604800',

            'lockout_window_minutes' => 'required|integer|min:1|max:1440',
        ];
    }
}
