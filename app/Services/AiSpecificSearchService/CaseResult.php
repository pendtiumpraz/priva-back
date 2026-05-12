<?php

namespace App\Services\AiSpecificSearchService;

/**
 * Hasil generate SQL untuk satu kasus pencarian natural-language.
 *
 * Dikembalikan oleh App\Services\AiSpecificSearchService::generateSqls()
 * sebagai array of CaseResult (di-serialize via toArray()).
 */
class CaseResult
{
    /**
     * @param  string  $case_id          ID kasus yang dikirim caller.
     * @param  string  $query_text       Teks asli kasus dari user.
     * @param  string|null  $generated_sql  SQL SELECT yang sudah divalidasi; null jika gagal.
     * @param  string|null  $error          Pesan error (Bahasa Indonesia formal); null jika sukses.
     */
    public function __construct(
        public string $case_id,
        public string $query_text,
        public ?string $generated_sql = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array{case_id: string, query_text: string, generated_sql: string|null, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->case_id,
            'query_text' => $this->query_text,
            'generated_sql' => $this->generated_sql,
            'error' => $this->error,
        ];
    }

    public static function error(string $caseId, string $queryText, string $message): self
    {
        return new self(
            case_id: $caseId,
            query_text: $queryText,
            generated_sql: null,
            error: $message,
        );
    }

    public static function ok(string $caseId, string $queryText, string $sql): self
    {
        return new self(
            case_id: $caseId,
            query_text: $queryText,
            generated_sql: $sql,
            error: null,
        );
    }
}
