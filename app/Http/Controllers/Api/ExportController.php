<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiResult;
use App\Models\BreachIncident;
use App\Models\BreachSimulation;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentRecord;
use App\Models\Dpia;
use App\Models\DpiaRiskEventTemplate;
use App\Models\DsrRequest;
use App\Models\GapAssessment;
use App\Models\InformationSystem;
use App\Models\Ropa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
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
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
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

    private function flattenJson($data, int $maxLen = 200): string
    {
        if (is_null($data)) {
            return '';
        }
        if (is_array($data)) {
            return mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, $maxLen);
        }

        return (string) $data;
    }

    // =============================================
    // ROPA Export — Full 7-section wizard + metadata
    // =============================================
    public function ropa(Request $request)
    {
        $items = $this->getQuery($request, Ropa::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = [
            'No. Registrasi', 'Aktivitas Pemrosesan', 'Tujuan', 'Dasar Hukum',
            'Entitas', 'Divisi', 'Unit Kerja', 'Deskripsi',
            'Kategori Pemrosesan (Pengendali/Prosesor)',
            'Nama DPO', 'Email DPO', 'Telepon DPO',
            'Jenis Pemrosesan', 'Sistem Terkait',
            'Sumber Data', 'Kategori Subjek (Array)', 'Jenis Data (Array)',
            'Jenis Data Spesifik', 'Jenis Data Umum', 'Jenis Data PII',
            'Pihak Pemroses', 'Kategori Pihak', 'Pihak Ketiga',
            'Penerima Data', 'Transfer Luar Negeri', 'Negara Tujuan', 'Safeguards',
            'Kontrol Keamanan', 'Masa Retensi', 'Prosedur Pemusnahan',
            'Kategori Data', 'Subjek Data', 'Penerima (Array)', 'Security Measures',
            'Level Risiko', 'Status', 'Progress (%)', 'Dibuat', 'Diperbarui',
        ];

        $rows = $items->map(function ($r) {
            $w = $r->wizard_data ?? [];
            $s1 = $w['detail_pemrosesan'] ?? [];
            $s2 = $w['dpo_team'] ?? [];
            $s3 = $w['informasi_pemrosesan'] ?? [];
            $s4 = $w['pengumpulan_data'] ?? [];
            $s5 = $w['penggunaan_penyimpanan'] ?? [];
            $s6 = $w['pengiriman_data'] ?? [];
            $s7 = $w['retensi_keamanan'] ?? [];

            return [
                $r->registration_number,
                $r->processing_activity,
                $r->purpose,
                $r->legal_basis,
                $s1['entitas'] ?? '',
                $r->division ?? $s1['divisi'] ?? '',
                $s1['unit_kerja'] ?? '',
                $r->description ?? $s1['deskripsi'] ?? '',
                $s2['kategori_pemrosesan'] ?? '',
                $s2['dpo_name'] ?? '',
                $s2['dpo_email'] ?? '',
                $s2['dpo_phone'] ?? '',
                $this->flattenJson($s3['jenis_pemrosesan'] ?? []),
                $this->flattenJson($s3['sistem_terkait'] ?? []),
                $s4['sumber_data'] ?? '',
                $this->flattenJson($s4['kategori_subjek'] ?? []),
                $this->flattenJson($s4['jenis_data'] ?? []),
                $this->flattenJson($s4['jenis_data_spesifik'] ?? []),
                $this->flattenJson($s4['jenis_data_umum'] ?? []),
                $this->flattenJson($s4['jenis_data_pii'] ?? []),
                $s5['pihak_pemroses'] ?? '',
                $this->flattenJson($s5['kategori_pihak'] ?? []),
                $s5['pihak_ketiga'] ?? '',
                $s6['penerima_data'] ?? '',
                $s6['transfer_luar'] ?? '',
                $s6['negara_tujuan'] ?? '',
                $s6['safeguards'] ?? '',
                $this->flattenJson($s7['kontrol_keamanan'] ?? []),
                $r->retention_period ?? $s7['masa_retensi'] ?? '',
                $s7['prosedur_pemusnahan'] ?? '',
                is_array($r->data_categories) ? implode(', ', $r->data_categories) : ($r->data_categories ?? ''),
                is_array($r->data_subjects) ? implode(', ', $r->data_subjects) : ($r->data_subjects ?? ''),
                is_array($r->recipients) ? implode(', ', $r->recipients) : ($r->recipients ?? ''),
                $r->security_measures ?? '',
                strtoupper($r->risk_level),
                $r->status,
                ($r->progress ?? 0).'%',
                $r->created_at?->format('Y-m-d H:i'),
                $r->updated_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('ropa_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // DPIA Export — with Risk Matrix detail
    // =============================================
    public function dpia(Request $request)
    {
        $items = $this->getQuery($request, Dpia::class)->whereNull('deleted_at')->with('ropa:id,registration_number,processing_activity')->orderBy('created_at', 'desc')->get();

        $headers = [
            'No. Registrasi', 'Deskripsi', 'Level Risiko', 'Status',
            'ROPA Terkait', 'ROPA Aktivitas',
            'Likelihood (1-5)', 'Impact (1-5)', 'Risk Score',
            'Risiko #1', 'Risiko #2', 'Risiko #3',
            'Mitigasi #1', 'Mitigasi #2', 'Mitigasi #3',
            'Approver', 'Tanggal Approval', 'Dibuat', 'Diperbarui',
            // Comprehensive risk export columns (extends from wizard_data.potensi_risiko)
            'Total Risk Events', 'Risk Categories Covered', 'Risk Events (Detailed)',
        ];

        $rows = $items->map(function ($d) {
            $ra = $d->risk_assessment ?? [];
            $risks = $ra['risks'] ?? [];
            $mitigations = $d->mitigation_measures ?? [];

            // Aggregate wizard_data.potensi_risiko[*].risk_events[]
            $potensi = ($d->wizard_data ?? [])['potensi_risiko'] ?? [];
            $totalEvents = 0;
            $categoriesCovered = [];
            $detailedLines = [];
            foreach ($potensi as $categoryName => $categoryData) {
                $events = $categoryData['risk_events'] ?? [];
                if (! is_array($events) || empty($events)) {
                    continue;
                }
                $categoriesCovered[] = (string) $categoryName;
                foreach ($events as $ev) {
                    $totalEvents++;
                    $dampak = $ev['dampak'] ?? null;
                    $probab = $ev['probabilitas'] ?? null;
                    $score = (is_numeric($dampak) && is_numeric($probab)) ? ((int) $dampak * (int) $probab) : null;
                    $line = '['.$categoryName.'] '
                        .($ev['risk_event'] ?? '-')
                        .' — Dampak:'.($dampak ?? '-')
                        .' Probab:'.($probab ?? '-')
                        .' Score:'.($score ?? '-')
                        .' Penanganan:'.($ev['penanganan'] ?? '-');
                    if (! empty($ev['notes'])) {
                        $line .= '; Notes: '.$ev['notes'];
                    }
                    $detailedLines[] = $line;
                }
            }
            $detailed = implode("\n", $detailedLines);
            // Truncate to 5000 chars (CSV-friendly cell size)
            if (mb_strlen($detailed) > 5000) {
                $detailed = mb_substr($detailed, 0, 4990)."\n[...]";
            }

            return [
                $d->registration_number,
                $d->description,
                strtoupper($d->risk_level),
                $d->status,
                $d->ropa?->registration_number ?? '-',
                $d->ropa?->processing_activity ?? '-',
                $ra['likelihood'] ?? '-',
                $ra['impact'] ?? '-',
                isset($ra['likelihood'], $ra['impact']) ? ($ra['likelihood'] * $ra['impact']) : '-',
                isset($risks[0]) ? ($risks[0]['category'] ?? '').': '.($risks[0]['description'] ?? '') : '',
                isset($risks[1]) ? ($risks[1]['category'] ?? '').': '.($risks[1]['description'] ?? '') : '',
                isset($risks[2]) ? ($risks[2]['category'] ?? '').': '.($risks[2]['description'] ?? '') : '',
                isset($mitigations[0]) ? ($mitigations[0]['measure'] ?? '').' ['.($mitigations[0]['priority'] ?? '').']' : '',
                isset($mitigations[1]) ? ($mitigations[1]['measure'] ?? '').' ['.($mitigations[1]['priority'] ?? '').']' : '',
                isset($mitigations[2]) ? ($mitigations[2]['measure'] ?? '').' ['.($mitigations[2]['priority'] ?? '').']' : '',
                $d->approver_id ?? '-',
                $d->approved_at?->format('Y-m-d H:i') ?? '-',
                $d->created_at?->format('Y-m-d H:i'),
                $d->updated_at?->format('Y-m-d H:i'),
                $totalEvents,
                implode(', ', $categoriesCovered),
                $detailed,
            ];
        });

        return $this->streamCsv('dpia_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // DPIA Risks Export — denormalized 1 row per risk event
    // =============================================
    public function dpiaRisks(Request $request)
    {
        $items = $this->getQuery($request, Dpia::class)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        // Resolve org for template scope (superadmin can pass ?org_id=)
        $orgId = $request->user()->role === 'superadmin'
            ? ($request->filled('org_id') ? $request->org_id : null)
            : $request->user()->org_id;

        // Single query: load template library once (system + org-scoped custom).
        // Indexed by risk_event text for O(1) match lookup. NO per-DPIA query.
        $templateQuery = DpiaRiskEventTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->whereNull('org_id');
                if ($orgId) {
                    $q->orWhere('org_id', $orgId);
                }
            });
        $templates = $templateQuery->get(['risk_event'])->keyBy('risk_event');

        $headers = [
            'DPIA No. Registrasi', 'DPIA Description', 'DPIA Risk Level', 'DPIA Status',
            'Category Label', 'Category Answer', 'Category Description',
            'Risk Event', 'Dampak (1-5)', 'Probabilitas (1-5)', 'Risk Score (Dampak × Probabilitas)',
            'Penanganan', 'Notes', 'Match Template', 'Created', 'Updated',
        ];

        $rows = [];
        foreach ($items as $d) {
            $potensi = ($d->wizard_data ?? [])['potensi_risiko'] ?? [];
            if (! is_array($potensi) || empty($potensi)) {
                continue;
            }

            foreach ($potensi as $categoryLabel => $categoryData) {
                $answer = $categoryData['answer'] ?? '';
                $description = $categoryData['description'] ?? '';
                $events = $categoryData['risk_events'] ?? [];
                if (! is_array($events) || empty($events)) {
                    continue;
                }

                foreach ($events as $ev) {
                    $eventText = (string) ($ev['risk_event'] ?? '');
                    $dampak = $ev['dampak'] ?? null;
                    $probab = $ev['probabilitas'] ?? null;
                    $score = (is_numeric($dampak) && is_numeric($probab))
                        ? ((int) $dampak * (int) $probab)
                        : '';
                    $matchTpl = ($eventText !== '' && $templates->has($eventText)) ? 'Yes' : 'No';

                    $rows[] = [
                        $d->registration_number,
                        $d->description,
                        strtoupper($d->risk_level ?? ''),
                        $d->status,
                        (string) $categoryLabel,
                        $answer,
                        $description,
                        $eventText,
                        $dampak ?? '',
                        $probab ?? '',
                        $score,
                        $ev['penanganan'] ?? '',
                        $ev['notes'] ?? '',
                        $matchTpl,
                        $d->created_at?->format('Y-m-d H:i') ?? '',
                        $d->updated_at?->format('Y-m-d H:i') ?? '',
                    ];
                }
            }
        }

        return $this->streamCsv('dpia_risks_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Breach Export — with containment + timeline
    // =============================================
    public function breach(Request $request)
    {
        $items = $this->getQuery($request, BreachIncident::class)->whereNull('deleted_at')->where('is_simulation', false)->orderBy('created_at', 'desc')->get();

        $headers = [
            'Kode Insiden', 'Judul', 'Deskripsi', 'Severity', 'Status', 'Sumber',
            'Tipe Data Terdampak', 'Jumlah Subjek Terdampak',
            'Wajib Notifikasi KOMDIGI', 'Deadline Notifikasi',
            'Root Cause', 'Rencana Remediasi',
            'Containment Checklist (Selesai/Total)',
            'Terdeteksi', 'Assessed', 'Contained', 'Ditutup',
            'Notif KOMDIGI', 'Notif Subjek',
            'Incident Commander', 'DPO',
        ];

        $rows = $items->map(function ($b) {
            $checklist = $b->containment_checklist ?? [];
            $done = collect($checklist)->filter(fn ($c) => ($c['completed'] ?? false))->count();
            $total = count($checklist);

            return [
                $b->incident_code,
                $b->title,
                $b->description,
                strtoupper($b->severity),
                $b->status,
                $b->source,
                is_array($b->affected_data_types) ? implode(', ', $b->affected_data_types) : '',
                $b->affected_subjects_count ?? 0,
                $b->notification_required ? 'Ya' : 'Tidak',
                $b->notification_deadline?->format('Y-m-d H:i') ?? '-',
                $b->root_cause ?? '-',
                $b->remediation_plan ?? '-',
                "{$done}/{$total}",
                $b->detected_at?->format('Y-m-d H:i') ?? '-',
                $b->assessed_at?->format('Y-m-d H:i') ?? '-',
                $b->contained_at?->format('Y-m-d H:i') ?? '-',
                $b->closed_at?->format('Y-m-d H:i') ?? '-',
                $b->notified_komdigi_at?->format('Y-m-d H:i') ?? '-',
                $b->notified_subjects_at?->format('Y-m-d H:i') ?? '-',
                $b->incident_commander ?? '-',
                $b->dpo_id ?? '-',
            ];
        });

        return $this->streamCsv('breach_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // DSR Export — with verification + response
    // =============================================
    public function dsr(Request $request)
    {
        $items = $this->getQuery($request, DsrRequest::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = [
            'Request ID', 'Tipe Permintaan', 'Nama Pemohon', 'Email Pemohon', 'Telepon Pemohon',
            'Deskripsi', 'Status', 'Verifikasi',
            'Deadline (3x24 jam)', 'Sisa Waktu (jam)',
            'Respons', 'Alasan Penolakan',
            'Direspon Pada', 'Ditutup Pada', 'Assigned To',
            'Dibuat', 'Diperbarui',
        ];

        $rows = $items->map(function ($d) {
            $deadline = $d->deadline_at ? $d->deadline_at : null;
            $hoursLeft = $deadline ? max(0, round($deadline->diffInHours(now(), false))) : '-';

            return [
                $d->request_id,
                $d->request_type,
                $d->requester_name,
                $d->requester_email,
                $d->requester_phone ?? '-',
                $d->description,
                $d->status,
                $d->verification_status ?? 'pending',
                $deadline?->format('Y-m-d H:i') ?? '-',
                $hoursLeft,
                mb_substr($d->response ?? '', 0, 300),
                $d->rejection_reason ?? '-',
                $d->responded_at?->format('Y-m-d H:i') ?? '-',
                $d->closed_at?->format('Y-m-d H:i') ?? '-',
                $d->assigned_to ?? '-',
                $d->created_at?->format('Y-m-d H:i'),
                $d->updated_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('dsr_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Consent Export — with items + records stats + guardian
    // =============================================
    public function consent(Request $request)
    {
        $items = $this->getQuery($request, ConsentCollectionPoint::class)
            ->whereNull('deleted_at')
            ->withCount(['items', 'records'])
            ->orderBy('created_at', 'desc')
            ->get();

        $headers = [
            'Collection ID', 'Nama', 'Domain', 'Redirect URL',
            'Guardian/Wali Mode', 'Label Wali', 'Opsi Hubungan Wali',
            'Jumlah Items', 'Jumlah Records',
            'Dibuat', 'Diperbarui',
        ];

        $rows = $items->map(function ($c) {
            $settings = $c->settings ?? [];

            return [
                $c->collection_id,
                $c->name,
                $c->domain,
                $c->redirect_url ?? '-',
                ($settings['guardian_mode'] ?? false) ? 'Ya' : 'Tidak',
                $settings['guardian_label'] ?? '-',
                $settings['guardian_relation_options'] ?? '-',
                $c->items_count ?? 0,
                $c->records_count ?? 0,
                $c->created_at?->format('Y-m-d H:i'),
                $c->updated_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('consent_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Consent Records Export (individual consents)
    // =============================================
    public function consentRecords(Request $request)
    {
        $orgId = $request->user()->role === 'superadmin' ? null : $request->user()->org_id;

        $query = ConsentRecord::query()
            ->join('consent_collection_points', 'consent_records.collection_point_id', '=', 'consent_collection_points.id')
            ->join('consent_items', 'consent_records.consent_item_id', '=', 'consent_items.id');

        if ($orgId) {
            $query->where('consent_collection_points.org_id', $orgId);
        }

        $records = $query->select(
            'consent_records.*',
            'consent_collection_points.name as cp_name',
            'consent_items.title as item_title'
        )->orderBy('consent_records.created_at', 'desc')->limit(5000)->get();

        $headers = [
            'Collection Point', 'Consent Item', 'Subject ID', 'Subject Name',
            'Channel', 'Granted', 'IP Address',
            'Granted At', 'Revoked At', 'Revoke Reason',
        ];

        $rows = $records->map(fn ($r) => [
            $r->cp_name, $r->item_title, $r->subject_identifier, $r->subject_name ?? '-',
            $r->channel, $r->is_granted ? 'Ya' : 'Tidak', $r->ip_address ?? '-',
            $r->granted_at?->format('Y-m-d H:i') ?? '-',
            $r->revoked_at?->format('Y-m-d H:i') ?? '-',
            $r->revoke_reason ?? '-',
        ]);

        return $this->streamCsv('consent_records_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Gap Assessment Export — with per-question answers
    // =============================================
    public function gapAssessment(Request $request)
    {
        $items = $this->getQuery($request, GapAssessment::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = [
            'Assessment ID', 'Versi', 'Skor Keseluruhan (%)', 'Level Kepatuhan',
            'Progress (%)', 'Jumlah Pertanyaan Dijawab', 'Total Pertanyaan',
            'Skor Tata Kelola', 'Skor Siklus Proses',
            'Dibuat', 'Diperbarui',
        ];

        $rows = $items->map(function ($g) {
            $answers = $g->answers ?? [];
            $answered = collect($answers)->filter(fn ($a) => ! empty($a))->count();
            $summary = $g->summary ?? [];

            return [
                $g->id,
                $g->version ?? '1.0',
                ($g->score ?? $g->overall_score ?? 0).'%',
                $g->compliance_level ?? '-',
                ($g->progress ?? 0).'%',
                $answered,
                count($answers) ?: 33,
                $summary['tata_kelola_score'] ?? '-',
                $summary['siklus_proses_score'] ?? '-',
                $g->created_at?->format('Y-m-d H:i'),
                $g->updated_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('gap_assessment_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Data Discovery Export — with scan results
    // =============================================
    public function dataDiscovery(Request $request)
    {
        $items = $this->getQuery($request, InformationSystem::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $headers = [
            'Nama Sistem', 'Tipe Sumber', 'Owner', 'Status Scan', 'Progress Scan',
            'PDP Alert', 'PII Alert',
            'Jumlah Tabel', 'Total Kolom', 'Kolom PII', 'Kolom Perlu Enkripsi',
            'Last Scanned', 'Scan Engine', 'Dibuat',
        ];

        $rows = $items->map(function ($s) {
            $results = $s->scan_results ?? [];
            $tables = $results['tables'] ?? [];
            $allCols = collect($tables)->flatMap(fn ($t) => $t['columns'] ?? []);
            $piiCols = $allCols->filter(fn ($c) => $c['pii_detected'] ?? false)->count();
            $encCols = $allCols->filter(fn ($c) => $c['encryption_required'] ?? false)->count();

            return [
                $s->name,
                $s->source_type,
                $s->owner ?? '-',
                $s->scanning_status,
                ($s->scanning_progress ?? 0).'%',
                $s->pdp_alert_count ?? 0,
                $s->pii_alert_count ?? 0,
                count($tables),
                $allCols->count(),
                $piiCols,
                $encCols,
                $s->last_scanned_at?->format('Y-m-d H:i') ?? '-',
                $results['engine_version'] ?? '-',
                $s->created_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('data_discovery_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Data Discovery — Column-Level Detail Export
    // =============================================
    public function dataDiscoveryColumns(Request $request)
    {
        $items = $this->getQuery($request, InformationSystem::class)->whereNull('deleted_at')->get();

        $headers = [
            'Sistem', 'Tipe Sumber', 'Tabel/Collection', 'Row Count', 'Size (MB)',
            'Kolom', 'Tipe Data', 'PII Detected', 'Klasifikasi',
            'Kategori PDP', 'Perlu Enkripsi', 'Retensi (hari)', 'Manual Override',
        ];

        $rows = [];
        foreach ($items as $s) {
            $tables = ($s->scan_results ?? [])['tables'] ?? [];
            foreach ($tables as $table) {
                foreach ($table['columns'] ?? [] as $col) {
                    $rows[] = [
                        $s->name,
                        $s->source_type,
                        $table['name'],
                        $table['row_count'] ?? 0,
                        $table['size_mb'] ?? 0,
                        $col['name'],
                        $col['type'] ?? '-',
                        ($col['pii_detected'] ?? false) ? 'Ya' : 'Tidak',
                        strtoupper($col['classification'] ?? 'internal'),
                        $col['pdp_category'] ?? '-',
                        ($col['encryption_required'] ?? false) ? 'Ya' : 'Tidak',
                        $col['retention_days'] ?? '-',
                        ($col['manually_classified'] ?? false) ? 'Ya' : 'Tidak',
                    ];
                }
            }
        }

        return $this->streamCsv('data_discovery_columns_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Simulation / Fire Drill Export
    // =============================================
    public function simulation(Request $request)
    {
        $items = $this->getQuery($request, BreachSimulation::class)->orderBy('created_at', 'desc')->get();

        $headers = [
            'ID Simulasi', 'Tipe Skenario', 'Judul', 'Deskripsi',
            'Timer Mode', 'Status',
            'Skor Keseluruhan', 'Jumlah Peserta',
            'Mulai', 'Selesai', 'Durasi (menit)',
            'Temuan', 'Rekomendasi',
            'Dibuat',
        ];

        $rows = $items->map(function ($s) {
            $participants = $s->participants ?? [];
            $startedAt = $s->started_at;
            $endedAt = $s->ended_at;
            $duration = ($startedAt && $endedAt) ? round($startedAt->diffInMinutes($endedAt)) : '-';

            return [
                $s->id,
                $s->scenario_type,
                $s->scenario_title,
                mb_substr($s->scenario_description ?? '', 0, 200),
                $s->timer_mode,
                $s->status,
                $s->overall_score ?? '-',
                is_array($participants) ? count($participants) : 0,
                $startedAt?->format('Y-m-d H:i') ?? '-',
                $endedAt?->format('Y-m-d H:i') ?? '-',
                $duration,
                $this->flattenJson($s->findings),
                mb_substr($s->recommendations ?? '', 0, 300),
                $s->created_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('simulation_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // AI Results Export
    // =============================================
    public function aiResults(Request $request)
    {
        $query = $this->getQuery($request, AiResult::class)->orderBy('created_at', 'desc');
        if ($request->has('feature_type') && $request->feature_type !== 'all') {
            $query->where('feature_type', $request->feature_type);
        }
        $items = $query->limit(500)->get();

        $headers = ['ID', 'Tipe Fitur', 'Record ID', 'Record Type', 'Input Data (ringkas)', 'Result Summary', 'Credits Used', 'Dibuat'];

        $rows = $items->map(function ($ai) {
            $input = is_array($ai->input_data) ? $ai->input_data : json_decode($ai->input_data ?? '{}', true);
            $result = is_array($ai->result_data) ? $ai->result_data : json_decode($ai->result_data ?? '{}', true);

            $summary = $result['summary'] ?? $result['title'] ?? '';
            if (empty($summary) && isset($result['sections'])) {
                $summary = count($result['sections']).' sections generated';
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
                $ai->credits_used ?? 0,
                $ai->created_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('ai_results_export_'.date('Y-m-d').'.csv', $headers, $rows);
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
    // ALL MODULE SUMMARY (JSON Report)
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
        $discoveryQuery = $this->getQuery($request, InformationSystem::class);

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
            'consent' => ['total_points' => (clone $consentQuery)->whereNull('deleted_at')->count()],
            'data_discovery' => [
                'total_systems' => (clone $discoveryQuery)->whereNull('deleted_at')->count(),
                'scanned' => (clone $discoveryQuery)->whereNull('deleted_at')->where('scanning_status', 'done')->count(),
                'total_pdp_alerts' => (clone $discoveryQuery)->whereNull('deleted_at')->sum('pdp_alert_count'),
                'total_pii_alerts' => (clone $discoveryQuery)->whereNull('deleted_at')->sum('pii_alert_count'),
            ],
            'gap_assessment' => [
                'latest_score' => (clone $gapQuery)->whereNull('deleted_at')->latest()->value('score') ?? 0,
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
