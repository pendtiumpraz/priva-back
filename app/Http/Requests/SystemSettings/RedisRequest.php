<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Redis connection details. Password is encrypted at rest.
 *
 * `password` is nullable to support unprotected local Redis instances and
 * to allow the admin to clear the password by sending an empty string.
 */
class RedisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'host' => 'required|string|max:191',
            'port' => 'required|integer|min:1|max:65535',
            'password' => 'nullable|string|max:1024',
            'database' => 'required|integer|min:0|max:15',
        ];
    }
}
