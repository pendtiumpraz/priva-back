<?php

namespace App\Services\AiDocumentAnalyzer;

/**
 * Hasil analisis dokumen terhadap pertanyaan kepatuhan PDP.
 *
 * Dikembalikan oleh App\Services\AiDocumentAnalyzer::analyze().
 */
class AnalysisResult
{
    /**
     * @param  string  $status  'comply' | 'partial' | 'non_comply' | 'unsure'
     * @param  string  $analysis  Penjelasan dalam Bahasa Indonesia formal (maks. 200 kata).
     * @param  array<int, array{page: int|null, text: string}>  $cited_passages
     * @param  float  $confidence  Tingkat kepercayaan 0..1.
     * @param  int  $tokens_used  Jumlah token yang dihabiskan provider AI (0 jika cache hit).
     * @param  string|null  $error  null jika sukses; string error message jika gagal/unsupported.
     */
    public function __construct(
        public string $status = 'unsure',
        public string $analysis = '',
        public array $cited_passages = [],
        public float $confidence = 0.0,
        public int $tokens_used = 0,
        public ?string $error = null,
    ) {}

    /**
     * Set of valid status values.
     *
     * @return array<int, string>
     */
    public static function validStatuses(): array
    {
        return ['comply', 'partial', 'non_comply', 'unsure'];
    }

    /**
     * Normalize an arbitrary string to a valid status. Falls back to 'unsure'.
     */
    public static function normalizeStatus(?string $raw): string
    {
        $raw = strtolower(trim((string) $raw));
        $raw = str_replace([' ', '-'], '_', $raw);

        return in_array($raw, self::validStatuses(), true) ? $raw : 'unsure';
    }

    /**
     * Build an error/unsupported result.
     */
    public static function error(string $message, string $status = 'unsure'): self
    {
        return new self(
            status: $status,
            analysis: '',
            cited_passages: [],
            confidence: 0.0,
            tokens_used: 0,
            error: $message,
        );
    }

    /**
     * Convert to array (e.g. for JSON response / caching).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'analysis' => $this->analysis,
            'cited_passages' => $this->cited_passages,
            'confidence' => $this->confidence,
            'tokens_used' => $this->tokens_used,
            'error' => $this->error,
        ];
    }

    /**
     * Rehydrate from cached array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: self::normalizeStatus($data['status'] ?? 'unsure'),
            analysis: (string) ($data['analysis'] ?? ''),
            cited_passages: is_array($data['cited_passages'] ?? null) ? $data['cited_passages'] : [],
            confidence: (float) ($data['confidence'] ?? 0.0),
            tokens_used: (int) ($data['tokens_used'] ?? 0),
            error: isset($data['error']) ? ($data['error'] === null ? null : (string) $data['error']) : null,
        );
    }
}
