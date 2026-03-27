<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Ropa, Dpia, DsrRequest, ConsentCollectionPoint, BreachIncident, InformationSystem, AiResult, GapAssessment};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    // =============================================
    // GENERIC CSV EXPORT
    // =============================================
    private function getQuery(Request $request, string $modelClass)
    {
        $query = $modelClass::query();
        if ($request->user()->role !== 'superadmin') {
            $query->where('org_id', $request->user()->org_id);
        } elseif ($request->filled('org_id')) {
            $query->where('org_id', $request->org_id);
        }
        return $query;
    }

    private function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            // BOM for UTF-8 Excel compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // =============================================
    // ROPA Export
    // =============================================
    public function ropa(Request $request)
    {
        $items = $this->getQuery($request, Ropa::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = ['No. Registrasi', 'Aktivitas Pemrosesan', 'Tujuan', 'Dasar Hukum', 'Divisi', 'Kategori Data', 'Subjek Data', 'Penerima', 'Retensi', 'Level Risiko', 'Status', 'Progress', 'Dibuat', 'Diperbarui'];

        $rows = $items->map(fn($r) => [
            $r->registration_number,
            $r->processing_activity,
            $r->purpose,
            $r->legal_basis,
            $r->division,
            is_array($r->data_categories) ? implode(', ', $r->data_categories) : ($r->data_categories ?? ''),
            is_array($r->data_subjects) ? implode(', ', $r->data_subjects) : ($r->data_subjects ?? ''),
            is_array($r->recipients) ? implode(', ', $r->recipients) : ($r->recipients ?? ''),
            $r->retention_period,
            $r->risk_level,
            $r->status,
            ($r->progress ?? 0) . '%',
            $r->created_at?->format('Y-m-d H:i'),
            $r->updated_at?->format('Y-m-d H:i'),
        ]);

        return $this->streamCsv('ropa_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // DPIA Export
    // =============================================
    public function dpia(Request $request)
    {
        $items = $this->getQuery($request, Dpia::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = ['No. Registrasi', 'Deskripsi', 'Level Risiko', 'Status', 'ROPA ID', 'Progress', 'Approver', 'Tanggal Approval', 'Dibuat', 'Diperbarui'];

        $rows = $items->map(fn($d) => [
            $d->registration_number,
            $d->description,
            $d->risk_level,
            $d->status,
            $d->ropa_id ?? '-',
            ($d->progress ?? 0) . '%',
            $d->approver_id ?? '-',
            $d->approved_at?->format('Y-m-d H:i') ?? '-',
            $d->created_at?->format('Y-m-d H:i'),
            $d->updated_at?->format('Y-m-d H:i'),
        ]);

        return $this->streamCsv('dpia_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // Breach Export
    // =============================================
    public function breach(Request $request)
    {
        $items = $this->getQuery($request, BreachIncident::class)->whereNull('deleted_at')->where('is_simulation', false)->orderBy('created_at', 'desc')->get();

        $headers = ['Kode Insiden', 'Judul', 'Deskripsi', 'Severity', 'Status', 'Sumber', 'Jumlah Terdampak', 'Wajib Notifikasi', 'Root Cause', 'Rencana Remediasi', 'Terdeteksi', 'Assessed', 'Contained', 'Ditutup', 'Notif KOMDIGI', 'Notif Subjek', 'Deadline Notifikasi'];

        $rows = $items->map(fn($b) => [
            $b->incident_code,
            $b->title,
            $b->description,
            strtoupper($b->severity),
            $b->status,
            $b->source,
            $b->affected_subjects_count ?? 0,
            $b->notification_required ? 'Ya' : 'Tidak',
            $b->root_cause ?? '-',
            $b->remediation_plan ?? '-',
            $b->detected_at?->format('Y-m-d H:i') ?? '-',
            $b->assessed_at?->format('Y-m-d H:i') ?? '-',
            $b->contained_at?->format('Y-m-d H:i') ?? '-',
            $b->closed_at?->format('Y-m-d H:i') ?? '-',
            $b->notified_komdigi_at?->format('Y-m-d H:i') ?? '-',
            $b->notified_subjects_at?->format('Y-m-d H:i') ?? '-',
            $b->notification_deadline?->format('Y-m-d H:i') ?? '-',
        ]);

        return $this->streamCsv('breach_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // DSR Export
    // =============================================
    public function dsr(Request $request)
    {
        $items = $this->getQuery($request, DsrRequest::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = ['Request ID', 'Tipe', 'Nama Pemohon', 'Email', 'Deskripsi', 'Status', 'Deadline', 'Dibuat'];

        $rows = $items->map(fn($d) => [
            $d->request_id,
            $d->request_type,
            $d->requester_name,
            $d->requester_email,
            $d->description,
            $d->status,
            $d->deadline_at?->format('Y-m-d') ?? '-',
            $d->created_at?->format('Y-m-d H:i'),
        ]);

        return $this->streamCsv('dsr_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // Consent Export
    // =============================================
    public function consent(Request $request)
    {
        $items = $this->getQuery($request, ConsentCollectionPoint::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = ['Collection ID', 'Nama', 'Domain', 'Redirect URL', 'Jumlah Items', 'Jumlah Records', 'Dibuat'];

        $rows = $items->map(fn($c) => [
            $c->collection_id,
            $c->name,
            $c->domain,
            $c->redirect_url ?? '-',
            $c->items_count ?? 0,
            $c->records_count ?? 0,
            $c->created_at?->format('Y-m-d H:i'),
        ]);

        return $this->streamCsv('consent_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // Gap Assessment Export
    // =============================================
    public function gapAssessment(Request $request)
    {
        $items = $this->getQuery($request, GapAssessment::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = ['Assessment ID', 'Skor Keseluruhan', 'Level Kepatuhan', 'Status', 'Dibuat', 'Diperbarui'];

        $rows = $items->map(fn($g) => [
            $g->assessment_id ?? $g->id,
            ($g->overall_score ?? 0) . '%',
            $g->compliance_level ?? '-',
            $g->status ?? '-',
            $g->created_at?->format('Y-m-d H:i'),
            $g->updated_at?->format('Y-m-d H:i'),
        ]);

        return $this->streamCsv('gap_assessment_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // Data Discovery Export
    // =============================================
    public function dataDiscovery(Request $request)
    {
        $items = $this->getQuery($request, InformationSystem::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = ['Nama Sistem', 'Tipe Sumber', 'Owner', 'Status Scan', 'Progress Scan', 'PDP Alert', 'PII Alert', 'Last Scanned', 'Dibuat'];

        $rows = $items->map(fn($s) => [
            $s->name,
            $s->source_type,
            $s->owner,
            $s->scanning_status,
            ($s->scanning_progress ?? 0) . '%',
            $s->pdp_alert ?? 0,
            $s->pii_alert ?? 0,
            $s->last_scanned?->format('Y-m-d H:i') ?? '-',
            $s->created_at?->format('Y-m-d H:i'),
        ]);

        return $this->streamCsv('data_discovery_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // AI Results Export (all or by feature type)
    // =============================================
    public function aiResults(Request $request)
    {
        $query = $this->getQuery($request, AiResult::class)->orderBy('created_at', 'desc');

        if ($request->has('feature_type') && $request->feature_type !== 'all') {
            $query->where('feature_type', $request->feature_type);
        }

        $items = $query->limit(500)->get();

        $headers = ['ID', 'Tipe Fitur', 'Record ID', 'Record Type', 'Input Data (ringkas)', 'Result Summary', 'Dibuat'];

        $rows = $items->map(function ($ai) {
            $input = is_array($ai->input_data) ? $ai->input_data : json_decode($ai->input_data ?? '{}', true);
            $result = is_array($ai->result_data) ? $ai->result_data : json_decode($ai->result_data ?? '{}', true);

            // Extract summary from result
            $summary = $result['summary'] ?? $result['title'] ?? $result['judul'] ?? '';
            if (empty($summary) && isset($result['sections'])) {
                $summary = count($result['sections']) . ' sections';
            }
            if (empty($summary)) {
                $summary = mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, 200);
            }

            return [
                $ai->id,
                $ai->feature_type,
                $ai->record_id ?? '-',
                $ai->record_type ?? '-',
                mb_substr(json_encode($input, JSON_UNESCAPED_UNICODE), 0, 200),
                $summary,
                $ai->created_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('ai_results_export_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // =============================================
    // SINGLE AI Result — Full JSON export
    // =============================================
    public function aiResultSingle(Request $request, string $id)
    {
        $result = $this->getQuery($request, AiResult::class)->findOrFail($id);

        $data = [
            'id' => $result->id,
            'feature_type' => $result->feature_type,
            'record_id' => $result->record_id,
            'record_type' => $result->record_type,
            'input_data' => $result->input_data,
            'result_data' => $result->result_data,
            'created_at' => $result->created_at?->toISOString(),
        ];

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, "ai_result_{$result->feature_type}_{$id}.json", [
            'Content-Type' => 'application/json',
        ]);
    }

    // =============================================
    // ALL MODULE SUMMARY (PDF-ready HTML)
    // =============================================
    public function complianceReport(Request $request)
    {
        $ropaQuery = $this->getQuery($request, Ropa::class);
        $dpiaQuery = $this->getQuery($request, Dpia::class);
        $breachQuery = $this->getQuery($request, BreachIncident::class);
        $dsrQuery = $this->getQuery($request, DsrRequest::class);
        $consentQuery = $this->getQuery($request, ConsentCollectionPoint::class);
        $gapQuery = $this->getQuery($request, GapAssessment::class);
        $aiQuery = $this->getQuery($request, AiResult::class);

        $data = [
            'generated_at' => now()->toISOString(),
            'ropa' => [
                'total' => (clone $ropaQuery)->whereNull('deleted_at')->count(),
                'by_status' => (clone $ropaQuery)->whereNull('deleted_at')->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
                'by_risk' => (clone $ropaQuery)->whereNull('deleted_at')->select('risk_level', DB::raw('count(*) as count'))->groupBy('risk_level')->pluck('count', 'risk_level'),
            ],
            'dpia' => [
                'total' => (clone $dpiaQuery)->whereNull('deleted_at')->count(),
                'by_status' => (clone $dpiaQuery)->whereNull('deleted_at')->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
                'approved' => (clone $dpiaQuery)->whereNull('deleted_at')->where('status', 'approved')->count(),
            ],
            'breach' => [
                'total' => (clone $breachQuery)->whereNull('deleted_at')->where('is_simulation', false)->count(),
                'active' => (clone $breachQuery)->whereNull('deleted_at')->where('is_simulation', false)->whereNotIn('status', ['closed'])->count(),
                'by_severity' => (clone $breachQuery)->whereNull('deleted_at')->where('is_simulation', false)->select('severity', DB::raw('count(*) as count'))->groupBy('severity')->pluck('count', 'severity'),
            ],
            'dsr' => [
                'total' => (clone $dsrQuery)->whereNull('deleted_at')->count(),
                'by_status' => (clone $dsrQuery)->whereNull('deleted_at')->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
                'by_type' => (clone $dsrQuery)->whereNull('deleted_at')->select('request_type', DB::raw('count(*) as count'))->groupBy('request_type')->pluck('count', 'request_type'),
            ],
            'consent' => [
                'total_points' => (clone $consentQuery)->whereNull('deleted_at')->count(),
            ],
            'gap_assessment' => [
                'latest_score' => (clone $gapQuery)->whereNull('deleted_at')->latest()->value('overall_score') ?? 0,
                'latest_level' => (clone $gapQuery)->whereNull('deleted_at')->latest()->value('compliance_level') ?? '-',
            ],
            'ai_analysis' => [
                'total_runs' => (clone $aiQuery)->count(),
                'by_feature' => (clone $aiQuery)->select('feature_type', DB::raw('count(*) as count'))->groupBy('feature_type')->pluck('count', 'feature_type'),
            ],
        ];

        return response()->json(['data' => $data]);
    }
}
