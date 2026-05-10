<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown ketika prompt AI melewati batas character yang configurable di
 * system_settings. Render jadi HTTP 413 Payload Too Large dengan body JSON
 * yang ramah supaya frontend bisa kasih toast informatif.
 *
 * Pakai kode HTTP 413 (bukan 422) supaya semantically clear: ini bukan
 * validation error, ini physical size limit. Banyak load balancer / WAF
 * juga punya rule khusus untuk 413 yang berbeda dari 4xx lain.
 */
class PromptTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $actualChars,
        public readonly int $maxChars,
        public readonly string $field = 'prompt',
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            '%s terlalu besar (%s karakter, batas %s).',
            ucfirst($field),
            number_format($actualChars),
            number_format($maxChars),
        ));
    }

    /**
     * Laravel exception handler akan auto-call render() kalau ada.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'prompt_too_large',
            'field' => $this->field,
            'actual_chars' => $this->actualChars,
            'max_chars' => $this->maxChars,
        ], 413);
    }
}
