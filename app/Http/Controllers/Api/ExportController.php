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
use App\Models\ModuleCustomSection;
use App\Models\Organization;
use App\Models\Ropa;
use App\Services\BrandedXlsxExporter;
use App\Services\WizardSchemaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * Resolve scope: which org_id should we filter custom schema by?
     * Tenant users → own org_id. Superadmin → respect ?org_id= filter (or
     * null = no extras / all orgs export which we render as "no extras").
     */
    private function resolveCustomFieldScope(Request $request): ?string
    {
        $user = $request->user();
        if ($user && $user->role !== 'superadmin') {
            return $user->org_id;
        }

        return $request->filled('org_id') ? (string) $request->org_id : null;
    }

    /**
     * Load org-global custom field schema (sections + fields) for ROPA/DPIA
     * export. Returns a flat ordered list of:
     *   ['section_label' => ..., 'field_name' => ..., 'field_label' => ...,
     *    'field_type' => ..., 'header' => '[Section] - [Field]']
     *
     * Returns [] when scope is null (no org filter possible).
     */
    private function getCustomFieldsForExport(?string $orgId, string $module): array
    {
        if (! $orgId) {
            return [];
        }

        $service = app(WizardSchemaService::class);
        $flat = $service->getCustomFieldsFlat($orgId, $module);
        if (empty($flat)) {
            return [];
        }

        // Map section_key → section_label for header prefixing.
        $sections = ModuleCustomSection::forOrg($orgId)
            ->forModule($module)
            ->active()
            ->get(['section_key', 'section_label'])
            ->pluck('section_label', 'section_key')
            ->all();

        $rows = [];
        foreach ($flat as $f) {
            $sectionLabel = $sections[$f['section_key']] ?? null;
            $header = $sectionLabel
                ? "[{$sectionLabel}] - {$f['field_label']}"
                : $f['field_label'];
            $rows[] = [
                'section_key' => $f['section_key'],
                'section_label' => $sectionLabel,
                'field_name' => $f['field_name'],
                'field_label' => $f['field_label'],
                'field_type' => $f['field_type'],
                'header' => $header,
            ];
        }

        return $rows;
    }

    /**
     * Format a custom-field value for CSV output, type-aware.
     */
    private function formatCustomValue($value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        switch ($type) {
            case 'boolean':
                return ((bool) $value) ? 'Ya' : 'Tidak';
            case 'multiselect':
            case 'tags':
                if (is_array($value)) {
                    return implode(', ', array_map('strval', $value));
                }

                return (string) $value;
            case 'date':
                if (is_string($value) && strlen($value) >= 10) {
                    return substr($value, 0, 10);
                }

                return (string) $value;
            case 'number':
                return is_numeric($value) ? (string) $value : '';
            default:
                if (is_array($value)) {
                    return $this->flattenJson($value, 500);
                }

                return (string) $value;
        }
    }

    /**
     * Collect distinct per-record extras across all records in this export.
     * Returns array of ['field_name' => ..., 'field_label' => ..., 'field_type' => ...]
     * — first encounter wins on label/type collision (rare, just for header
     * stability across rows).
     */
    private function collectPerRecordExtras($items): array
    {
        $byName = [];
        foreach ($items as $r) {
            $extras = ($r->wizard_data ?? [])['per_record_extras'] ?? [];
            if (! is_array($extras)) {
                continue;
            }
            foreach ($extras as $ex) {
                if (! is_array($ex)) {
                    continue;
                }
                $name = $ex['field_name'] ?? null;
                if (! is_string($name) || $name === '' || isset($byName[$name])) {
                    continue;
                }
                $byName[$name] = [
                    'field_name' => $name,
                    'field_label' => (string) ($ex['field_label'] ?? $name),
                    'field_type' => (string) ($ex['field_type'] ?? 'text'),
                ];
            }
        }

        return array_values($byName);
    }

    /**
     * Look up a per-record-extras value on a single record by field_name.
     * Returns null when not present on this record.
     */
    private function findExtraValue(array $wizardData, string $fieldName): ?array
    {
        $extras = $wizardData['per_record_extras'] ?? [];
        if (! is_array($extras)) {
            return null;
        }
        foreach ($extras as $ex) {
            if (is_array($ex) && ($ex['field_name'] ?? null) === $fieldName) {
                return $ex;
            }
        }

        return null;
    }

    // =============================================
    // RoPA Export — Full 7-section wizard + metadata
    // =============================================
    public function ropa(Request $request)
    {
        $items = $this->getQuery($request, Ropa::class)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        // Phase 7: org-global custom fields + per-record extras
        $orgId = $this->resolveCustomFieldScope($request);
        $customFields = $this->getCustomFieldsForExport($orgId, 'ropa');
        $perRecordExtras = $this->collectPerRecordExtras($items);

        $headers = [
            'No. Registrasi', 'Aktivitas Pemrosesan', 'Tujuan', 'Dasar Hukum',
            'Entitas', 'Divisi', 'Unit Kerja', 'Deskripsi',
            'Kategori Pemrosesan (Pengendali/Prosesor/Bersama)',
            'Nama DPO', 'Email DPO', 'Telepon DPO',
            'Jenis Pemrosesan', 'Sistem Terkait',
            // Sec 3 — Bantuan AI sub-Q
            'Bantuan AI', 'Nama Teknologi AI', 'Tujuan AI',
            // Sec 3 — Dasar Pemrosesan sub-Q
            'Dokumen Dasar — Nama', 'Dokumen Dasar — Nomor', 'Dokumen Dasar — Tanggal', 'Dokumen Dasar — Lokasi',
            'Regulasi Dasar — Nama', 'Regulasi Dasar — Nomor', 'Kondisi Pemrosesan', 'Dokumentasi LIA',
            // Sec 4 — Sumber Data
            'Sumber Data (Legacy)', 'Sumber Data (Multi)', 'Sumber Data — Detail',
            'Kategori Subjek (Array)', 'Jenis Data (Array)',
            'Jenis Data Spesifik (Dikumpulkan)', 'Jenis Data Umum (Dikumpulkan)', 'Jenis Data PII (Dikumpulkan)',
            // Sec 5 — Penggunaan/Penyimpanan
            'Pihak Pemroses', 'Kategori Pihak', 'Kategori Pihak — Lainnya', 'Pihak Ketiga (Y/N)', 'Vendor TPRM (IDs)', 'Vendor TPRM (Nama)',
            // Sec 6 — Penerima Internal
            'Penerima Internal (Y/N)', 'Internal — Divisi', 'Internal — PIC', 'Internal — Email PIC', 'Internal — Telepon PIC',
            // Sec 6 — Penerima Eksternal
            'Penerima Eksternal (Y/N)', 'Eksternal — Organisasi', 'Eksternal — Alamat', 'Eksternal — PIC', 'Eksternal — Email PIC', 'Eksternal — Telepon PIC', 'Eksternal — Sistem Informasi',
            // Sec 6 — Jenis data dikirimkan
            'Jenis Data Spesifik (Dikirimkan)', 'Jenis Data Umum (Dikirimkan)', 'Jenis Data PII (Dikirimkan)',
            // Sec 6 — Transfer LN
            'Transfer Luar Negeri (Y/N)', 'Negara Tujuan', 'Dasar Transfer LN', 'Safeguards (Catatan)',
            // Sec 7 — Retensi & Keamanan
            'Kontrol Keamanan', 'Masa Retensi', 'Retensi Due Date',
            'Ada Prosedur Pemusnahan', 'Prosedur Pemusnahan (Deskripsi)', 'Pemusnahan Terakhir', 'Berita Acara Pemusnahan',
            'Pernah Insiden', 'Insiden — Penjelasan',
            // Approval state
            'Status', 'Approval — Section Status',
            // Legacy normalized columns (kept for back-compat)
            'Kategori Data', 'Subjek Data', 'Penerima (Array)', 'Security Measures',
            'Level Risiko', 'Progress (%)', 'Dibuat', 'Diperbarui',
        ];
        // Append org-global custom field columns
        foreach ($customFields as $cf) {
            $headers[] = $cf['header'];
        }
        // Append per-record extras columns
        foreach ($perRecordExtras as $ex) {
            $headers[] = '[Per-Record] - '.$ex['field_label'];
        }

        // Pre-resolve all vendor names referenced across the export so we don't
        // fire one query per row. Walk the items, collect distinct vendor_ids
        // from wizard_data.penggunaan_penyimpanan.vendor_ids[], fetch in bulk.
        $allVendorIds = [];
        foreach ($items as $r) {
            $vIds = ($r->wizard_data ?? [])['penggunaan_penyimpanan']['vendor_ids'] ?? [];
            if (is_array($vIds)) {
                foreach ($vIds as $v) {
                    if (is_string($v) && $v !== '') {
                        $allVendorIds[$v] = true;
                    }
                }
            }
        }
        $vendorNames = [];
        if (! empty($allVendorIds) && class_exists(\App\Models\Vendor::class)) {
            try {
                $vendorNames = \App\Models\Vendor::whereIn('id', array_keys($allVendorIds))
                    ->pluck('name', 'id')->all();
            } catch (\Throwable $e) {
                \Log::warning('ExportController: vendor name lookup failed: '.$e->getMessage());
            }
        }

        $rows = $items->map(function ($r) use ($customFields, $perRecordExtras, $vendorNames) {
            $w = $r->wizard_data ?? [];
            $s1 = $w['detail_pemrosesan'] ?? [];
            $s2 = $w['dpo_team'] ?? [];
            $s3 = $w['informasi_pemrosesan'] ?? [];
            $s4 = $w['pengumpulan_data'] ?? [];
            $s5 = $w['penggunaan_penyimpanan'] ?? [];
            $s6 = $w['pengiriman_data'] ?? [];
            $s7 = $w['retensi_keamanan'] ?? [];
            $customValues = $w['custom_fields'] ?? [];

            // Sumber Data — multi: combine list + per-option detail values.
            $sumberList = $s4['sumber_data_list'] ?? [];
            $sumberDetails = [];
            if (is_array($sumberList)) {
                foreach ($sumberList as $opt) {
                    $key = 'sumber_data_detail_'.preg_replace('/[^a-z0-9]/i', '_', strtolower($opt));
                    $val = $s4[$key] ?? null;
                    if ($val) {
                        $sumberDetails[] = "{$opt}: {$val}";
                    }
                }
            }

            // Vendor TPRM lookup
            $vendorIds = $s5['vendor_ids'] ?? [];
            $vendorIdsList = is_array($vendorIds) ? array_filter($vendorIds, 'is_string') : [];
            $vendorNamesList = array_values(array_filter(array_map(fn ($id) => $vendorNames[$id] ?? null, $vendorIdsList)));

            // Section approvals — flatten as "section: status, ..."
            $approvals = $w['section_approvals'] ?? [];
            $approvalSummary = '';
            if (is_array($approvals)) {
                $parts = [];
                foreach ($approvals as $secKey => $secData) {
                    if (is_array($secData) && ! empty($secData['status'])) {
                        $parts[] = "{$secKey}:{$secData['status']}";
                    }
                }
                $approvalSummary = implode(', ', $parts);
            }

            $row = [
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
                // Sec 3 — Bantuan AI
                $s3['bantuan_ai'] ?? '',
                $s3['ai_teknologi'] ?? '',
                $s3['ai_tujuan'] ?? '',
                // Sec 3 — Dasar Pemrosesan sub-Q
                $s3['lb_dok_nama'] ?? '',
                $s3['lb_dok_nomor'] ?? '',
                $s3['lb_dok_tanggal'] ?? '',
                $s3['lb_dok_lokasi'] ?? '',
                $s3['lb_reg_nama'] ?? '',
                $s3['lb_reg_nomor'] ?? '',
                $s3['lb_kondisi'] ?? '',
                $s3['lb_lia'] ?? '',
                // Sec 4 — Sumber Data (legacy single + new multi)
                $s4['sumber_data'] ?? '',
                $this->flattenJson($sumberList),
                implode(' | ', $sumberDetails),
                $this->flattenJson($s4['kategori_subjek'] ?? []),
                $this->flattenJson($s4['jenis_data'] ?? []),
                $this->flattenJson($s4['jenis_data_spesifik'] ?? []),
                $this->flattenJson($s4['jenis_data_umum'] ?? []),
                $this->flattenJson($s4['jenis_data_pii'] ?? []),
                // Sec 5
                $s5['pihak_pemroses'] ?? '',
                $this->flattenJson($s5['kategori_pihak'] ?? []),
                $s5['kategori_pihak_lainnya'] ?? '',
                $s5['pihak_ketiga'] ?? '',
                implode(', ', $vendorIdsList),
                implode(', ', $vendorNamesList),
                // Sec 6 — Penerima Internal
                $s6['penerima_internal'] ?? '',
                $s6['penerima_internal_divisi'] ?? '',
                $s6['penerima_internal_pic'] ?? '',
                $s6['penerima_internal_email'] ?? '',
                $s6['penerima_internal_telp'] ?? '',
                // Sec 6 — Penerima Eksternal
                $s6['penerima_eksternal'] ?? '',
                $s6['penerima_eksternal_org'] ?? '',
                $s6['penerima_eksternal_alamat'] ?? '',
                $s6['penerima_eksternal_pic'] ?? '',
                $s6['penerima_eksternal_email'] ?? '',
                $s6['penerima_eksternal_telp'] ?? '',
                $s6['penerima_eksternal_sistem'] ?? '',
                // Sec 6 — Jenis data dikirimkan
                $this->flattenJson($s6['jenis_data_spesifik_kirim'] ?? []),
                $this->flattenJson($s6['jenis_data_umum_kirim'] ?? []),
                $this->flattenJson($s6['jenis_data_pii_kirim'] ?? []),
                // Sec 6 — Transfer LN
                $s6['transfer_luar'] ?? '',
                $s6['negara_tujuan'] ?? '',
                $this->flattenJson($s6['transfer_basis'] ?? []),
                $s6['safeguards'] ?? '',
                // Sec 7
                $this->flattenJson($s7['kontrol_keamanan'] ?? []),
                $r->retention_period ?? $s7['masa_retensi'] ?? '',
                $r->retention_due_date ? (string) $r->retention_due_date : '',
                $s7['ada_prosedur_pemusnahan'] ?? '',
                $s7['prosedur_pemusnahan'] ?? '',
                $s7['pemusnahan_terakhir_at'] ?? '',
                $s7['berita_acara_pemusnahan'] ?? '',
                $s7['pernah_insiden'] ?? '',
                $s7['insiden_jelaskan'] ?? '',
                // Approval state
                $r->status,
                $approvalSummary,
                // Legacy normalized
                is_array($r->data_categories) ? implode(', ', $r->data_categories) : ($r->data_categories ?? ''),
                is_array($r->data_subjects) ? implode(', ', $r->data_subjects) : ($r->data_subjects ?? ''),
                is_array($r->recipients) ? implode(', ', $r->recipients) : ($r->recipients ?? ''),
                $r->security_measures ?? '',
                strtoupper($r->risk_level),
                ($r->progress ?? 0).'%',
                $r->created_at?->format('Y-m-d H:i'),
                $r->updated_at?->format('Y-m-d H:i'),
            ];

            // Append org-global custom field values for this record
            foreach ($customFields as $cf) {
                $val = is_array($customValues) ? ($customValues[$cf['field_name']] ?? null) : null;
                $row[] = $this->formatCustomValue($val, $cf['field_type']);
            }
            // Append per-record extras values for this record
            foreach ($perRecordExtras as $ex) {
                $found = $this->findExtraValue($w, $ex['field_name']);
                $row[] = $found
                    ? $this->formatCustomValue($found['value'] ?? null, (string) ($found['field_type'] ?? 'text'))
                    : '';
            }

            return $row;
        });

        return $this->streamCsv('ropa_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // DPIA Export — with Risk Matrix detail
    // =============================================
    public function dpia(Request $request)
    {
        $items = $this->getQuery($request, Dpia::class)->whereNull('deleted_at')->with('ropa:id,registration_number,processing_activity')->orderBy('created_at', 'desc')->get();

        // Phase 7: org-global custom fields + per-record extras
        $orgId = $this->resolveCustomFieldScope($request);
        $customFields = $this->getCustomFieldsForExport($orgId, 'dpia');
        $perRecordExtras = $this->collectPerRecordExtras($items);

        $headers = [
            'No. Registrasi', 'Deskripsi', 'Level Risiko', 'Status',
            'RoPA Terkait', 'RoPA Aktivitas',
            'Likelihood (1-5)', 'Impact (1-5)', 'Risk Score',
            'Risiko #1', 'Risiko #2', 'Risiko #3',
            'Mitigasi #1', 'Mitigasi #2', 'Mitigasi #3',
            'Approver', 'Tanggal Approval', 'Dibuat', 'Diperbarui',
            // Comprehensive risk export columns (extends from wizard_data.potensi_risiko)
            'Total Risk Events', 'Risk Categories Covered', 'Risk Events (Detailed)',
        ];
        foreach ($customFields as $cf) {
            $headers[] = $cf['header'];
        }
        foreach ($perRecordExtras as $ex) {
            $headers[] = '[Per-Record] - '.$ex['field_label'];
        }

        $rows = $items->map(function ($d) use ($customFields, $perRecordExtras) {
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

            $w = $d->wizard_data ?? [];
            $customValues = $w['custom_fields'] ?? [];

            $row = [
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
            // Append org-global custom field values
            foreach ($customFields as $cf) {
                $val = is_array($customValues) ? ($customValues[$cf['field_name']] ?? null) : null;
                $row[] = $this->formatCustomValue($val, $cf['field_type']);
            }
            // Append per-record extras
            foreach ($perRecordExtras as $ex) {
                $found = $this->findExtraValue($w, $ex['field_name']);
                $row[] = $found
                    ? $this->formatCustomValue($found['value'] ?? null, (string) ($found['field_type'] ?? 'text'))
                    : '';
            }

            return $row;
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

        $totalQuestions = count(GapAssessment::getQuestionBank('uupdp'));

        $rows = $items->map(function ($g) use ($totalQuestions) {
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
                $totalQuestions,
                $summary['tata_kelola_score'] ?? '-',
                $summary['siklus_proses_score'] ?? '-',
                $g->created_at?->format('Y-m-d H:i'),
                $g->updated_at?->format('Y-m-d H:i'),
            ];
        });

        return $this->streamCsv('gap_assessment_export_'.date('Y-m-d').'.csv', $headers, $rows);
    }

    // =============================================
    // Gap Assessment XLSX Export — multi-sheet structured workbook
    // Sheets: Ringkasan | Detail Jawaban | Skor per Kategori
    // =============================================
    public function gapAssessmentXlsx(Request $request): StreamedResponse
    {
        $items = $this->getQuery($request, GapAssessment::class)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        // Question bank (single source of truth for headers, weights, recs).
        $questionBank = GapAssessment::getQuestionBank('uupdp');
        $totalQuestions = count($questionBank);

        // Distinct categories (preserving order of first appearance).
        $categories = [];
        foreach ($questionBank as $q) {
            $cat = $q['category'] ?? 'General';
            if (! in_array($cat, $categories, true)) {
                $categories[] = $cat;
            }
        }

        // Map raw answer → label.
        $answerLabel = static function ($raw): string {
            return match ($raw) {
                'yes' => 'Sudah Memenuhi',
                'partial' => 'Memenuhi Sebagian',
                'no' => 'Belum Memenuhi',
                'na' => 'Tidak Berlaku',
                null, '' => '-',
                default => (string) $raw,
            };
        };

        // Score earned per question per the GapAssessment model logic
        // (yes=full weight, partial=½, no/na=0).
        $perQuestionScore = static function ($raw, int $weight): string {
            if ($raw === 'yes') {
                return (string) $weight;
            }
            if ($raw === 'partial') {
                return (string) ($weight * 0.5);
            }
            if ($raw === 'no') {
                return '0';
            }

            return '-';
        };

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Privasimu Nexus')
            ->setTitle('GAP Assessment Export')
            ->setSubject('GAP Assessment')
            ->setDescription('Multi-sheet GAP assessment export');

        // Reusable header style: bold + light-blue fill + thin border + centered.
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '1F3864']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'B4C7E7'],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '8FAADC'],
                ],
            ],
        ];

        // ============================================================
        // SHEET 1: Ringkasan
        // ============================================================
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Ringkasan');

        $sheet1Headers = [
            'Assessment ID', 'Versi', 'Tanggal Dibuat', 'Tanggal Diperbarui',
            'Progress (%)', 'Skor Keseluruhan (%)', 'Level Kepatuhan',
            'Skor Tata Kelola', 'Skor Siklus Proses',
            'Jumlah Soal Dijawab', 'Total Soal',
        ];
        $sheet1->fromArray($sheet1Headers, null, 'A1');
        $lastCol1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($sheet1Headers));
        $sheet1->getStyle("A1:{$lastCol1}1")->applyFromArray($headerStyle);
        $sheet1->getRowDimension(1)->setRowHeight(28);
        $sheet1->freezePane('A2');

        $row = 2;
        foreach ($items as $g) {
            $answers = is_array($g->answers) ? $g->answers : [];
            $answered = collect($answers)->filter(fn ($a) => ! empty($a) && $a !== 'na')->count();
            $summary = [];
            // Compute per-category score from the question bank for the
            // Tata Kelola / Siklus Proses summary cells.
            $catTotals = [];
            foreach ($questionBank as $q) {
                $cat = $q['category'] ?? 'General';
                $catTotals[$cat] ??= ['total' => 0, 'earned' => 0];
                $a = $answers[$q['id']] ?? null;
                $w = (int) ($q['weight'] ?? 0);
                if ($a === 'na') {
                    continue;
                }
                $catTotals[$cat]['total'] += $w;
                if ($a === 'yes') {
                    $catTotals[$cat]['earned'] += $w;
                } elseif ($a === 'partial') {
                    $catTotals[$cat]['earned'] += $w * 0.5;
                }
            }
            $tataKelola = isset($catTotals['Tata Kelola']) && $catTotals['Tata Kelola']['total'] > 0
                ? round(($catTotals['Tata Kelola']['earned'] / $catTotals['Tata Kelola']['total']) * 100, 2)
                : '-';
            $siklus = isset($catTotals['Siklus Proses PDP']) && $catTotals['Siklus Proses PDP']['total'] > 0
                ? round(($catTotals['Siklus Proses PDP']['earned'] / $catTotals['Siklus Proses PDP']['total']) * 100, 2)
                : '-';

            $sheet1->fromArray([
                $g->id,
                $g->version ?? '1.0',
                $g->created_at?->format('Y-m-d H:i'),
                $g->updated_at?->format('Y-m-d H:i'),
                ($g->progress ?? 0),
                ($g->overall_score ?? $g->score ?? 0),
                $g->compliance_level ?? '-',
                $tataKelola,
                $siklus,
                $answered,
                $totalQuestions,
            ], null, "A{$row}");
            $row++;
        }

        // Bordered body rows.
        if ($row > 2) {
            $sheet1->getStyle("A2:{$lastCol1}".($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'D0D7E2'],
                    ],
                ],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            ]);
        }

        // Explicit column widths (avoid auto-calc cost on large datasets).
        $widths1 = [38, 8, 18, 18, 12, 18, 16, 16, 18, 18, 12];
        foreach ($widths1 as $i => $w) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet1->getColumnDimension($col)->setWidth($w);
        }

        // ============================================================
        // SHEET 2: Detail Jawaban
        // ============================================================
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Detail Jawaban');

        $sheet2Headers = [
            'Assessment Versi', 'Kategori', 'Kode Soal', 'Pertanyaan',
            'Jawaban', 'Bobot', 'Skor Pertanyaan', 'Rekomendasi',
        ];
        $sheet2->fromArray($sheet2Headers, null, 'A1');
        $lastCol2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($sheet2Headers));
        $sheet2->getStyle("A1:{$lastCol2}1")->applyFromArray($headerStyle);
        $sheet2->getRowDimension(1)->setRowHeight(28);
        $sheet2->freezePane('A2');

        $row = 2;
        $separatorRows = []; // Track rows that should render as a thin separator.

        foreach ($items as $idx => $g) {
            $answers = is_array($g->answers) ? $g->answers : [];
            $version = $g->version ?? '1.0';
            $assessmentLabel = $version.' ('.($g->id ?? '').')';

            foreach ($questionBank as $q) {
                $qid = $q['id'];
                $raw = $answers[$qid] ?? null;
                $weight = (int) ($q['weight'] ?? 0);
                $rec = '';
                if (in_array($raw, ['no', 'partial'], true)) {
                    $rec = (string) ($q['recommendation'] ?? '');
                }

                $sheet2->fromArray([
                    $assessmentLabel,
                    $q['category'] ?? '-',
                    $qid,
                    (string) ($q['question'] ?? ''),
                    $answerLabel($raw),
                    $weight,
                    $perQuestionScore($raw, $weight),
                    $rec,
                ], null, "A{$row}");
                $row++;
            }

            // Insert a visual separator row between assessments (except after the last one).
            if ($idx < $items->count() - 1) {
                $separatorRows[] = $row;
                $row++;
            }
        }

        if ($row > 2) {
            $bodyRange = "A2:{$lastCol2}".($row - 1);
            $sheet2->getStyle($bodyRange)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'D0D7E2'],
                    ],
                ],
                'alignment' => [
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    'wrapText' => true,
                ],
            ]);

            // Style separator rows: light grey fill, thicker bottom border.
            foreach ($separatorRows as $sr) {
                $sheet2->getStyle("A{$sr}:{$lastCol2}{$sr}")->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E7E6E6'],
                    ],
                    'borders' => [
                        'bottom' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                            'color' => ['rgb' => '8FAADC'],
                        ],
                    ],
                ]);
                $sheet2->getRowDimension($sr)->setRowHeight(8);
            }
        }

        // Column widths — wide for Pertanyaan/Rekomendasi (wrap text).
        // A:Versi B:Kategori C:Kode D:Pertanyaan E:Jawaban F:Bobot G:Skor H:Rekomendasi
        $widths2 = [22, 22, 16, 50, 18, 8, 14, 50];
        foreach ($widths2 as $i => $w) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet2->getColumnDimension($col)->setWidth($w);
        }

        // ============================================================
        // SHEET 3: Skor per Kategori (pivot)
        // ============================================================
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Skor per Kategori');

        $sheet3Headers = array_merge(
            ['Assessment ID', 'Versi', 'Tanggal Dibuat'],
            array_map(static fn ($c) => $c.' (%)', $categories)
        );
        $sheet3->fromArray($sheet3Headers, null, 'A1');
        $lastCol3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($sheet3Headers));
        $sheet3->getStyle("A1:{$lastCol3}1")->applyFromArray($headerStyle);
        $sheet3->getRowDimension(1)->setRowHeight(28);
        $sheet3->freezePane('A2');

        $row = 2;
        foreach ($items as $g) {
            $answers = is_array($g->answers) ? $g->answers : [];
            // Recompute per-category scores against the live question bank.
            $catScores = [];
            foreach ($categories as $cat) {
                $catScores[$cat] = ['total' => 0, 'earned' => 0];
            }
            foreach ($questionBank as $q) {
                $cat = $q['category'] ?? 'General';
                $a = $answers[$q['id']] ?? null;
                $w = (int) ($q['weight'] ?? 0);
                if ($a === 'na') {
                    continue;
                }
                $catScores[$cat]['total'] += $w;
                if ($a === 'yes') {
                    $catScores[$cat]['earned'] += $w;
                } elseif ($a === 'partial') {
                    $catScores[$cat]['earned'] += $w * 0.5;
                }
            }

            $rowVals = [
                $g->id,
                $g->version ?? '1.0',
                $g->created_at?->format('Y-m-d H:i'),
            ];
            foreach ($categories as $cat) {
                $t = $catScores[$cat]['total'];
                $rowVals[] = $t > 0
                    ? round(($catScores[$cat]['earned'] / $t) * 100, 2)
                    : '-';
            }
            $sheet3->fromArray($rowVals, null, "A{$row}");
            $row++;
        }

        if ($row > 2) {
            $sheet3->getStyle("A2:{$lastCol3}".($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'D0D7E2'],
                    ],
                ],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            ]);
        }

        // Column widths for sheet 3.
        $sheet3->getColumnDimension('A')->setWidth(38);
        $sheet3->getColumnDimension('B')->setWidth(8);
        $sheet3->getColumnDimension('C')->setWidth(18);
        for ($i = 4; $i <= count($sheet3Headers); $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet3->getColumnDimension($col)->setWidth(26);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'gap_assessment_export_'.date('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
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
    // Branded XLSX — shared helpers
    // =============================================

    /**
     * Resolve which Organization should brand the export. Tenant users always
     * brand from their own org; superadmin can pass ?org_id= to target a tenant.
     * Returns null only when there's truly no org context (rare — exports are
     * authenticated under auth:sanctum so this is mostly defensive).
     */
    private function resolveBrandingOrg(Request $request): ?Organization
    {
        $user = $request->user();
        $orgId = $user && $user->role !== 'superadmin'
            ? $user->org_id
            : ($request->filled('org_id') ? (string) $request->org_id : ($user->org_id ?? null));
        if (! $orgId) {
            return null;
        }

        return Organization::find($orgId);
    }

    /**
     * Collect ?filter[*] / known list filters from the request for cover-sheet
     * display. Falls back to an empty array.
     */
    private function summarizeFilters(Request $request): array
    {
        $known = ['status', 'risk_level', 'severity', 'request_type', 'org_id', 'q', 'search', 'feature_type'];
        $out = [];
        foreach ($known as $key) {
            if ($request->filled($key)) {
                $out[ucfirst(str_replace('_', ' ', $key))] = (string) $request->get($key);
            }
        }

        return $out;
    }

    /**
     * Stream a branded XLSX file. Builds via BrandedXlsxExporter to a temp
     * file, then returns a BinaryFileResponse that auto-deletes after send.
     */
    private function streamBrandedXlsx(
        Request $request,
        string $title,
        string $module,
        array $rows,
        array $columnConfig,
        ?string $explicitFilename = null,
    ): BinaryFileResponse|StreamedResponse {
        $org = $this->resolveBrandingOrg($request);
        if (! $org) {
            // Fall back to a tiny CSV with a helpful message — keep response
            // type recognisable even in the edge case.
            return $this->streamCsv("{$module}_export_".date('Y-m-d').'.csv', ['Error'], [['Org tidak ditemukan untuk branding export.']]);
        }

        $exporter = app(BrandedXlsxExporter::class);
        $filters = $this->summarizeFilters($request);
        $tmpPath = $exporter->export(
            org: $org,
            title: $title,
            module: $module,
            rows: $rows,
            columnConfig: $columnConfig,
            filters: $filters,
            user: $request->user(),
        );

        $moduleName = preg_replace('/\s+/', '', ucwords(str_replace(['-', '_'], ' ', $module)));
        $filename = $explicitFilename ?: $exporter->suggestedFilename($moduleName, $org);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ])->deleteFileAfterSend(true);
    }

    // =============================================
    // RoPA XLSX — Branded
    // =============================================
    public function ropaXlsx(Request $request)
    {
        $items = $this->getQuery($request, Ropa::class)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $columnConfig = [
            ['key' => 'registration_number', 'label' => 'No. Registrasi', 'width' => 18],
            ['key' => 'processing_activity', 'label' => 'Aktivitas Pemrosesan', 'width' => 32],
            ['key' => 'purpose', 'label' => 'Tujuan', 'width' => 32],
            ['key' => 'legal_basis', 'label' => 'Dasar Hukum', 'width' => 22],
            ['key' => 'division', 'label' => 'Divisi', 'width' => 22],
            ['key' => 'unit_kerja', 'label' => 'Unit Kerja', 'width' => 22],
            ['key' => 'dpo_name', 'label' => 'Nama DPO', 'width' => 22],
            ['key' => 'dpo_email', 'label' => 'Email DPO', 'width' => 26],
            ['key' => 'data_categories', 'label' => 'Kategori Data', 'format' => 'array', 'width' => 28],
            ['key' => 'data_subjects', 'label' => 'Subjek Data', 'format' => 'array', 'width' => 28],
            ['key' => 'transfer_luar', 'label' => 'Transfer Luar Negeri', 'format' => 'boolean', 'width' => 16],
            ['key' => 'retention_period', 'label' => 'Masa Retensi', 'width' => 18],
            ['key' => 'retention_due_date', 'label' => 'Retensi Due Date', 'format' => 'date', 'width' => 18],
            ['key' => 'risk_level', 'label' => 'Level Risiko', 'width' => 14],
            ['key' => 'progress', 'label' => 'Progress (%)', 'format' => 'number', 'width' => 12],
            ['key' => 'status', 'label' => 'Status', 'width' => 16],
            ['key' => 'created_at', 'label' => 'Dibuat', 'format' => 'datetime', 'width' => 20],
            ['key' => 'updated_at', 'label' => 'Diperbarui', 'format' => 'datetime', 'width' => 20],
        ];

        $rows = $items->map(function ($r) {
            $w = $r->wizard_data ?? [];
            $s1 = $w['detail_pemrosesan'] ?? [];
            $s2 = $w['dpo_team'] ?? [];
            $s6 = $w['pengiriman_data'] ?? [];

            return [
                'registration_number' => $r->registration_number,
                'processing_activity' => $r->processing_activity,
                'purpose' => $r->purpose,
                'legal_basis' => $r->legal_basis,
                'division' => $r->division ?? $s1['divisi'] ?? '',
                'unit_kerja' => $s1['unit_kerja'] ?? '',
                'dpo_name' => $s2['dpo_name'] ?? '',
                'dpo_email' => $s2['dpo_email'] ?? '',
                'data_categories' => is_array($r->data_categories) ? $r->data_categories : ($r->data_categories ?? ''),
                'data_subjects' => is_array($r->data_subjects) ? $r->data_subjects : ($r->data_subjects ?? ''),
                'transfer_luar' => $s6['transfer_luar'] ?? '',
                'retention_period' => $r->retention_period ?? '',
                'retention_due_date' => $r->retention_due_date ? (string) $r->retention_due_date : '',
                'risk_level' => strtoupper((string) $r->risk_level),
                'progress' => $r->progress ?? 0,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ];
        })->all();

        return $this->streamBrandedXlsx($request, 'Export Data RoPA', 'RoPA', $rows, $columnConfig);
    }

    // =============================================
    // DPIA XLSX — Branded
    // =============================================
    public function dpiaXlsx(Request $request)
    {
        $items = $this->getQuery($request, Dpia::class)
            ->whereNull('deleted_at')
            ->with('ropa:id,registration_number,processing_activity')
            ->orderBy('created_at', 'desc')
            ->get();

        $columnConfig = [
            ['key' => 'registration_number', 'label' => 'No. Registrasi', 'width' => 18],
            ['key' => 'description', 'label' => 'Deskripsi', 'width' => 38],
            ['key' => 'ropa_no', 'label' => 'RoPA Terkait', 'width' => 18],
            ['key' => 'ropa_activity', 'label' => 'Aktivitas RoPA', 'width' => 32],
            ['key' => 'risk_level', 'label' => 'Level Risiko', 'width' => 14],
            ['key' => 'likelihood', 'label' => 'Likelihood', 'format' => 'number', 'width' => 12],
            ['key' => 'impact', 'label' => 'Impact', 'format' => 'number', 'width' => 12],
            ['key' => 'risk_score', 'label' => 'Risk Score', 'format' => 'number', 'width' => 12],
            ['key' => 'status', 'label' => 'Status', 'width' => 16],
            ['key' => 'approved_at', 'label' => 'Tanggal Approval', 'format' => 'datetime', 'width' => 22],
            ['key' => 'created_at', 'label' => 'Dibuat', 'format' => 'datetime', 'width' => 20],
            ['key' => 'updated_at', 'label' => 'Diperbarui', 'format' => 'datetime', 'width' => 20],
        ];

        $rows = $items->map(function ($d) {
            $ra = $d->risk_assessment ?? [];

            return [
                'registration_number' => $d->registration_number,
                'description' => $d->description,
                'ropa_no' => $d->ropa?->registration_number ?? '-',
                'ropa_activity' => $d->ropa?->processing_activity ?? '-',
                'risk_level' => strtoupper((string) $d->risk_level),
                'likelihood' => $ra['likelihood'] ?? '',
                'impact' => $ra['impact'] ?? '',
                'risk_score' => isset($ra['likelihood'], $ra['impact']) ? ($ra['likelihood'] * $ra['impact']) : '',
                'status' => $d->status,
                'approved_at' => $d->approved_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
                'updated_at' => $d->updated_at?->toIso8601String(),
            ];
        })->all();

        return $this->streamBrandedXlsx($request, 'Export Data DPIA', 'DPIA', $rows, $columnConfig);
    }

    // =============================================
    // Breach XLSX — Branded
    // =============================================
    public function breachXlsx(Request $request)
    {
        $items = $this->getQuery($request, BreachIncident::class)
            ->whereNull('deleted_at')
            ->where('is_simulation', false)
            ->orderBy('created_at', 'desc')
            ->get();

        $columnConfig = [
            ['key' => 'incident_code', 'label' => 'Kode Insiden', 'width' => 18],
            ['key' => 'title', 'label' => 'Judul', 'width' => 32],
            ['key' => 'description', 'label' => 'Deskripsi', 'width' => 38],
            ['key' => 'severity', 'label' => 'Severity', 'width' => 14],
            ['key' => 'status', 'label' => 'Status', 'width' => 16],
            ['key' => 'source', 'label' => 'Sumber', 'width' => 18],
            ['key' => 'affected_data_types', 'label' => 'Tipe Data Terdampak', 'format' => 'array', 'width' => 28],
            ['key' => 'affected_subjects_count', 'label' => 'Jumlah Subjek', 'format' => 'number', 'width' => 14],
            ['key' => 'notification_required', 'label' => 'Wajib Notif KOMDIGI', 'format' => 'boolean', 'width' => 18],
            ['key' => 'notification_deadline', 'label' => 'Deadline Notifikasi', 'format' => 'datetime', 'width' => 22],
            ['key' => 'containment_status', 'label' => 'Containment (Selesai/Total)', 'width' => 18],
            ['key' => 'detected_at', 'label' => 'Terdeteksi', 'format' => 'datetime', 'width' => 22],
            ['key' => 'contained_at', 'label' => 'Contained', 'format' => 'datetime', 'width' => 22],
            ['key' => 'closed_at', 'label' => 'Ditutup', 'format' => 'datetime', 'width' => 22],
            ['key' => 'root_cause', 'label' => 'Root Cause', 'width' => 36],
        ];

        $rows = $items->map(function ($b) {
            $checklist = $b->containment_checklist ?? [];
            $done = collect($checklist)->filter(fn ($c) => ($c['completed'] ?? false))->count();
            $total = count($checklist);

            return [
                'incident_code' => $b->incident_code,
                'title' => $b->title,
                'description' => $b->description,
                'severity' => strtoupper((string) $b->severity),
                'status' => $b->status,
                'source' => $b->source,
                'affected_data_types' => is_array($b->affected_data_types) ? $b->affected_data_types : ($b->affected_data_types ?? ''),
                'affected_subjects_count' => $b->affected_subjects_count ?? 0,
                'notification_required' => (bool) $b->notification_required,
                'notification_deadline' => $b->notification_deadline?->toIso8601String(),
                'containment_status' => "{$done}/{$total}",
                'detected_at' => $b->detected_at?->toIso8601String(),
                'contained_at' => $b->contained_at?->toIso8601String(),
                'closed_at' => $b->closed_at?->toIso8601String(),
                'root_cause' => $b->root_cause ?? '-',
            ];
        })->all();

        return $this->streamBrandedXlsx($request, 'Export Data Insiden Pelanggaran', 'Breach', $rows, $columnConfig);
    }

    // =============================================
    // DSR XLSX — Branded
    // =============================================
    public function dsrXlsx(Request $request)
    {
        $items = $this->getQuery($request, DsrRequest::class)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $columnConfig = [
            ['key' => 'request_id', 'label' => 'Request ID', 'width' => 18],
            ['key' => 'request_type', 'label' => 'Tipe Permintaan', 'width' => 20],
            ['key' => 'requester_name', 'label' => 'Nama Pemohon', 'width' => 24],
            ['key' => 'requester_email', 'label' => 'Email Pemohon', 'width' => 28],
            ['key' => 'description', 'label' => 'Deskripsi', 'width' => 38],
            ['key' => 'status', 'label' => 'Status', 'width' => 16],
            ['key' => 'verification_status', 'label' => 'Verifikasi', 'width' => 16],
            ['key' => 'deadline_at', 'label' => 'Deadline (3x24 jam)', 'format' => 'datetime', 'width' => 22],
            ['key' => 'responded_at', 'label' => 'Direspon Pada', 'format' => 'datetime', 'width' => 22],
            ['key' => 'closed_at', 'label' => 'Ditutup Pada', 'format' => 'datetime', 'width' => 22],
            ['key' => 'created_at', 'label' => 'Dibuat', 'format' => 'datetime', 'width' => 22],
        ];

        $rows = $items->map(fn ($d) => [
            'request_id' => $d->request_id,
            'request_type' => $d->request_type,
            'requester_name' => $d->requester_name,
            'requester_email' => $d->requester_email,
            'description' => $d->description,
            'status' => $d->status,
            'verification_status' => $d->verification_status ?? 'pending',
            'deadline_at' => $d->deadline_at?->toIso8601String(),
            'responded_at' => $d->responded_at?->toIso8601String(),
            'closed_at' => $d->closed_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ])->all();

        return $this->streamBrandedXlsx($request, 'Export Permintaan Subjek Data (DSR)', 'DSR', $rows, $columnConfig);
    }

    // =============================================
    // Consent XLSX — Branded
    // =============================================
    public function consentXlsx(Request $request)
    {
        $items = $this->getQuery($request, ConsentCollectionPoint::class)
            ->whereNull('deleted_at')
            ->withCount(['items', 'records'])
            ->orderBy('created_at', 'desc')
            ->get();

        $columnConfig = [
            ['key' => 'collection_id', 'label' => 'Collection ID', 'width' => 22],
            ['key' => 'name', 'label' => 'Nama', 'width' => 30],
            ['key' => 'domain', 'label' => 'Domain', 'width' => 28],
            ['key' => 'redirect_url', 'label' => 'Redirect URL', 'width' => 30],
            ['key' => 'guardian_mode', 'label' => 'Mode Wali', 'format' => 'boolean', 'width' => 14],
            ['key' => 'items_count', 'label' => 'Jumlah Items', 'format' => 'number', 'width' => 14],
            ['key' => 'records_count', 'label' => 'Jumlah Records', 'format' => 'number', 'width' => 14],
            ['key' => 'created_at', 'label' => 'Dibuat', 'format' => 'datetime', 'width' => 22],
            ['key' => 'updated_at', 'label' => 'Diperbarui', 'format' => 'datetime', 'width' => 22],
        ];

        $rows = $items->map(function ($c) {
            $settings = $c->settings ?? [];

            return [
                'collection_id' => $c->collection_id,
                'name' => $c->name,
                'domain' => $c->domain,
                'redirect_url' => $c->redirect_url ?? '-',
                'guardian_mode' => (bool) ($settings['guardian_mode'] ?? false),
                'items_count' => $c->items_count ?? 0,
                'records_count' => $c->records_count ?? 0,
                'created_at' => $c->created_at?->toIso8601String(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ];
        })->all();

        return $this->streamBrandedXlsx($request, 'Export Titik Pengumpulan Persetujuan', 'Consent', $rows, $columnConfig);
    }

    // =============================================
    // Data Discovery XLSX — Branded
    // =============================================
    public function dataDiscoveryXlsx(Request $request)
    {
        $items = $this->getQuery($request, InformationSystem::class)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $columnConfig = [
            ['key' => 'name', 'label' => 'Nama Sistem', 'width' => 26],
            ['key' => 'source_type', 'label' => 'Tipe Sumber', 'width' => 18],
            ['key' => 'owner', 'label' => 'Owner', 'width' => 20],
            ['key' => 'scanning_status', 'label' => 'Status Scan', 'width' => 14],
            ['key' => 'scanning_progress', 'label' => 'Progress (%)', 'format' => 'number', 'width' => 12],
            ['key' => 'pdp_alert_count', 'label' => 'PDP Alerts', 'format' => 'number', 'width' => 12],
            ['key' => 'pii_alert_count', 'label' => 'PII Alerts', 'format' => 'number', 'width' => 12],
            ['key' => 'table_count', 'label' => 'Jumlah Tabel', 'format' => 'number', 'width' => 14],
            ['key' => 'column_count', 'label' => 'Total Kolom', 'format' => 'number', 'width' => 14],
            ['key' => 'pii_column_count', 'label' => 'Kolom PII', 'format' => 'number', 'width' => 14],
            ['key' => 'encryption_column_count', 'label' => 'Perlu Enkripsi', 'format' => 'number', 'width' => 14],
            ['key' => 'last_scanned_at', 'label' => 'Last Scanned', 'format' => 'datetime', 'width' => 22],
            ['key' => 'created_at', 'label' => 'Dibuat', 'format' => 'datetime', 'width' => 22],
        ];

        $rows = $items->map(function ($s) {
            $results = $s->scan_results ?? [];
            $tables = $results['tables'] ?? [];
            $allCols = collect($tables)->flatMap(fn ($t) => $t['columns'] ?? []);

            return [
                'name' => $s->name,
                'source_type' => $s->source_type,
                'owner' => $s->owner ?? '-',
                'scanning_status' => $s->scanning_status,
                'scanning_progress' => $s->scanning_progress ?? 0,
                'pdp_alert_count' => $s->pdp_alert_count ?? 0,
                'pii_alert_count' => $s->pii_alert_count ?? 0,
                'table_count' => count($tables),
                'column_count' => $allCols->count(),
                'pii_column_count' => $allCols->filter(fn ($c) => $c['pii_detected'] ?? false)->count(),
                'encryption_column_count' => $allCols->filter(fn ($c) => $c['encryption_required'] ?? false)->count(),
                'last_scanned_at' => $s->last_scanned_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ];
        })->all();

        return $this->streamBrandedXlsx($request, 'Export Data Discovery', 'DataDiscovery', $rows, $columnConfig);
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
                // Kolom asli di tabel adalah `overall_score`, bukan `score`.
                // Pakai nama kolom yang benar supaya query tidak 500.
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
