<?php

namespace Database\Seeders;

use App\Models\DueDiligenceDocument;
use App\Models\DueDiligenceQuestion;
use Illuminate\Database\Seeder;

/**
 * Seed Due Diligence Center (root-only): 39 pertanyaan TDD + jawaban
 * rekomendasi, dan 14 dokumen yang diminta dengan tabel detail.
 *
 * Sumber konten: database/seeders/data/due_diligence_seed.json
 * (dibangun 1:1 dari workbook TDQ + TDD_DOK_TEMPLATES).
 *
 * Idempotent & non-destruktif: run() memakai firstOrCreate sehingga editan
 * root TIDAK tertimpa saat re-seed. reset() (force) memakai updateOrCreate
 * untuk mengembalikan ke rekomendasi default.
 */
class DueDiligenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed(false);
    }

    public function seed(bool $force = false): void
    {
        $path = database_path('seeders/data/due_diligence_seed.json');
        if (! is_file($path)) {
            $this->command?->warn("DueDiligence seed JSON tidak ditemukan: {$path}");

            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) {
            $this->command?->warn('DueDiligence seed JSON tidak valid.');

            return;
        }

        foreach ($data['questions'] ?? [] as $q) {
            $attrs = [
                'area' => $q['area'] ?? null,
                'sub_topic' => $q['sub_topic'] ?? null,
                'qtype' => $q['qtype'] ?? 'TUL',
                'question' => $q['question'] ?? '',
                'recommended_answer' => $q['recommended_answer'] ?? null,
                'evidence' => $q['evidence'] ?? null,
                'status' => $q['status'] ?? 'perlu_kerja',
                'sort_order' => $q['sort_order'] ?? 0,
            ];
            if ($force) {
                DueDiligenceQuestion::updateOrCreate(['q_no' => $q['q_no']], $attrs);
            } else {
                DueDiligenceQuestion::firstOrCreate(['q_no' => $q['q_no']], $attrs);
            }
        }

        foreach ($data['documents'] ?? [] as $d) {
            $attrs = [
                'category' => $d['category'] ?? null,
                'name' => $d['name'] ?? '',
                'request_text' => $d['request_text'] ?? null,
                'priority' => $d['priority'] ?? 'SEDANG',
                'format' => $d['format'] ?? 'table',
                'guidance' => $d['guidance'] ?? null,
                'recommendation' => $d['recommendation'] ?? null,
                'columns' => $d['columns'] ?? [],
                'rows' => $d['rows'] ?? [],
                'sort_order' => $d['sort_order'] ?? 0,
            ];
            if ($force) {
                DueDiligenceDocument::updateOrCreate(['doc_no' => $d['doc_no']], $attrs);
            } else {
                DueDiligenceDocument::firstOrCreate(['doc_no' => $d['doc_no']], $attrs);
            }
        }

        $this->command?->info('Due Diligence seeded: '.count($data['questions'] ?? []).' pertanyaan, '.count($data['documents'] ?? []).' dokumen.');
    }
}
