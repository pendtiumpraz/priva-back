<?php

namespace App\Http\Controllers\Api\Root;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DueDiligenceDocument;
use App\Models\DueDiligenceQuestion;
use Database\Seeders\DueDiligenceSeeder;
use Illuminate\Http\Request;

/**
 * Due Diligence Center — root-only.
 *
 * Kuesioner TDD (39 pertanyaan) + 14 dokumen yang diminta, dengan jawaban
 * rekomendasi & tabel detail yang BISA DIEDIT. Frontend men-download tiap
 * dokumen sebagai PDF ter-style. Route digate `role.root_only` (lihat
 * routes/api.php) — guard di sini sebagai lapis kedua.
 */
class DueDiligenceController extends Controller
{
    public function index(Request $request)
    {
        $this->requireRoot($request);

        return response()->json([
            'questions' => DueDiligenceQuestion::orderBy('sort_order')->orderBy('q_no')->get(),
            'documents' => DueDiligenceDocument::orderBy('sort_order')->orderBy('doc_no')->get(),
        ]);
    }

    public function updateQuestion(Request $request, string $id)
    {
        $this->requireRoot($request);
        $row = DueDiligenceQuestion::findOrFail($id);

        $data = $request->validate([
            'recommended_answer' => 'nullable|string',
            'evidence' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:siap,perlu_kerja,landmine',
            'internal_note' => 'nullable|string',
        ]);

        $row->fill($data)->save();
        $this->audit('dd_question_updated', $row->id, ['q_no' => $row->q_no]);

        return response()->json(['message' => 'Jawaban diperbarui', 'data' => $row]);
    }

    public function updateDocument(Request $request, string $id)
    {
        $this->requireRoot($request);
        $row = DueDiligenceDocument::findOrFail($id);

        $data = $request->validate([
            'doc_status' => 'nullable|string|in:draft,disiapkan,terkirim',
            'received_date' => 'nullable|date',
            'recommendation' => 'nullable|string',
            'guidance' => 'nullable|string',
            'columns' => 'nullable|array',
            'columns.*' => 'string|max:200',
            'rows' => 'nullable|array',
            'rows.*' => 'array',
            'rows.*.*' => 'nullable|string',
        ]);

        $row->fill($data)->save();
        $this->audit('dd_document_updated', $row->id, ['doc_no' => $row->doc_no]);

        return response()->json(['message' => 'Dokumen diperbarui', 'data' => $row]);
    }

    /**
     * Reset seluruh isi ke rekomendasi default (re-seed). Hanya root.
     * Aman dipanggil ulang: seeder idempotent (updateOrCreate by nomor).
     */
    public function reset(Request $request)
    {
        $this->requireRoot($request);
        (new DueDiligenceSeeder)->seed(true); // force: kembalikan rekomendasi default
        $this->audit('dd_reset_to_defaults', null, []);

        return response()->json([
            'message' => 'Direset ke rekomendasi default',
            'questions' => DueDiligenceQuestion::orderBy('sort_order')->orderBy('q_no')->get(),
            'documents' => DueDiligenceDocument::orderBy('sort_order')->orderBy('doc_no')->get(),
        ]);
    }

    private function requireRoot(Request $request): void
    {
        if (($request->user()->role ?? null) !== 'root') {
            abort(403, 'Hanya role root yang dapat mengakses Due Diligence Center.');
        }
    }

    private function audit(string $action, ?string $id, array $data): void
    {
        try {
            AuditLog::log('due_diligence', $id, $action, $data, 'due_diligence');
        } catch (\Throwable $e) {
            \Log::warning('Audit log failed: '.$e->getMessage());
        }
    }
}
