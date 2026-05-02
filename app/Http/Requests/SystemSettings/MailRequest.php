<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SMTP transport credentials. `smtp_password` encrypted at rest.
 *
 * All fields nullable — Mail section is optional (transactional email is
 * only required for breach notifications + invites). Sending it all-null
 * is a valid "clear configuration" operation.
 */
class MailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'smtp_host' => 'nullable|string|max:191',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_username' => 'nullable|string|max:191',
            'smtp_password' => 'nullable|string|max:1024',
        ];
    }
}
