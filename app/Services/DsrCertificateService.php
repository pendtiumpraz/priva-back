<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DsrRequest;
use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generate Subject Certificate (redacted, public-shareable) +
 * Internal Certificate (full audit, DPO archive) untuk DSR yang completed.
 *
 * PDF rendered via dompdf, stored via TenantStorageService (per-org disk).
 * Document rows indexed by kind (dsr.subject_certificate / dsr.internal_certificate).
 */
class DsrCertificateService
{
    public function __construct(private TenantStorageService $storage) {}

    public const REQUEST_TYPE_LABELS = [
        'access' => 'Akses Data Pribadi',
        'correction' => 'Koreksi Data',
        'rectification' => 'Koreksi Data',
        'deletion' => 'Penghapusan Data',
        'erasure' => 'Penghapusan Data',
        'portability' => 'Portabilitas Data',
        'restriction' => 'Pembatasan Pemrosesan',
        'objection' => 'Keberatan atas Pemrosesan',
        'withdraw_consent' => 'Penarikan Persetujuan',
        'info' => 'Informasi Pemrosesan',
    ];

    /**
     * Generate both certificates dan persist sebagai Document rows.
     * Returns [subject_doc_id, internal_doc_id].
     */
    public function generateBoth(DsrRequest $dsr): array
    {
        $org = Organization::findOrFail($dsr->org_id);
        $dsr->loadMissing(['app', 'scopes.informationSystem', 'executions.informationSystem']);

        $subjectDoc = $this->generateSubject($dsr, $org);
        $internalDoc = $this->generateInternal($dsr, $org);

        $dsr->update([
            'subject_certificate_doc_id' => $subjectDoc->id,
            'internal_certificate_doc_id' => $internalDoc->id,
            // Legacy field kept in sync — UI may still read this
            'completion_certificate_doc_id' => $subjectDoc->id,
        ]);

        AuditLog::create([
            'org_id' => $org->id,
            'module' => 'dsr',
            'record_id' => $dsr->id,
            'action' => 'dsr.certificates_generated',
            'details' => [
                'subject_doc_id' => $subjectDoc->id,
                'internal_doc_id' => $internalDoc->id,
            ],
        ]);

        return [$subjectDoc->id, $internalDoc->id];
    }

    public function generateSubject(DsrRequest $dsr, Organization $org): Document
    {
        $payload = $this->buildSubjectPayload($dsr, $org);
        $pdf = Pdf::loadView('reports.dsr.subject_certificate', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true]);

        $bytes = $pdf->output();
        $filename = "subject-cert-{$dsr->request_id}.pdf";
        $path = $this->storeBytes($org, $bytes, "dsr/{$dsr->id}/certificates", $filename);

        return Document::create([
            'org_id' => $org->id,
            'kind' => 'dsr.subject_certificate',
            'source_type' => 'dsr_request',
            'source_id' => $dsr->id,
            'name' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'storage_path' => $path['path'],
            'storage_driver' => $path['driver'],
            'metadata' => [
                'request_id' => $dsr->request_id,
                'request_type' => $dsr->request_type,
                'verification_stamp' => $payload['verificationStamp'],
            ],
        ]);
    }

    public function generateInternal(DsrRequest $dsr, Organization $org): Document
    {
        $payload = $this->buildInternalPayload($dsr, $org);
        $pdf = Pdf::loadView('reports.dsr.internal_certificate', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true]);

        $bytes = $pdf->output();
        $filename = "internal-cert-{$dsr->request_id}.pdf";
        $path = $this->storeBytes($org, $bytes, "dsr/{$dsr->id}/certificates", $filename);

        return Document::create([
            'org_id' => $org->id,
            'kind' => 'dsr.internal_certificate',
            'source_type' => 'dsr_request',
            'source_id' => $dsr->id,
            'name' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'storage_path' => $path['path'],
            'storage_driver' => $path['driver'],
            'metadata' => [
                'request_id' => $dsr->request_id,
                'request_type' => $dsr->request_type,
                'verification_stamp' => $payload['verificationStamp'],
                'sla_status' => $payload['slaStatus'],
            ],
        ]);
    }

    private function storeBytes(Organization $org, string $bytes, string $directory, string $filename): array
    {
        $disk = $this->storage->getDisk($org);
        $path = trim($directory, '/') . '/' . $filename;
        $disk->put($path, $bytes);

        return [
            'path' => $path,
            'driver' => $org->storage_driver ?? 'local',
        ];
    }

    private function buildSubjectPayload(DsrRequest $dsr, Organization $org): array
    {
        $scopeRows = $dsr->scopes->map(function ($scope) use ($dsr) {
            $execs = $dsr->executions->where('information_system_id', $scope->information_system_id);
            $allDone = $execs->isNotEmpty() && $execs->every(fn($e) => in_array($e->status, ['executed', 'skipped'], true));
            return [
                'system_name' => optional($scope->informationSystem)->name ?? 'Sistem tidak diketahui',
                'action_label' => $this->actionLabel($scope->request_types ?? [$dsr->request_type]),
                'status' => $allDone ? '✓ Selesai' : '✓ Diproses',
            ];
        })->values()->all();

        return [
            'org' => $org,
            'orgName' => $org->name,
            'orgEmail' => $org->email ?? null,
            'orgWebsite' => $org->website ?? null,
            'orgLogoUrl' => $org->logo_url ?? null,
            'dsr' => $dsr,
            'requestTypeLabel' => self::REQUEST_TYPE_LABELS[$dsr->request_type] ?? ucfirst($dsr->request_type),
            'maskedEmail' => $this->maskEmail($dsr->requester_email ?? ''),
            'scopeRows' => $scopeRows,
            'dpoName' => optional($dsr->assignee)->name ?? null,
            'verificationStamp' => $this->verificationStamp($dsr, 'subject'),
        ];
    }

    private function buildInternalPayload(DsrRequest $dsr, Organization $org): array
    {
        $executions = $dsr->executions->map(function ($e) {
            return [
                'system_name' => optional($e->informationSystem)->name ?? '—',
                'shard_name' => $e->shard_name,
                'request_type' => $e->request_type,
                'status' => $e->status,
                'rows_affected' => $e->rows_affected,
                'executed_by_email' => $e->executed_by_email,
                'executed_at' => optional($e->executed_at)->format('d M Y H:i'),
            ];
        })->values()->all();

        $evidenceIds = $dsr->executions->pluck('evidence_file_id')->filter()->unique()->values();
        $evidenceList = Document::whereIn('id', $evidenceIds)->get()->map(fn($d) => [
            'name' => $d->name,
            'uploaded_at' => optional($d->created_at)->format('d M Y H:i'),
            'size_kb' => $d->size_bytes ? round($d->size_bytes / 1024, 1) : 0,
        ])->all();

        $audit = AuditLog::where('module', 'dsr')->where('record_id', $dsr->id)
            ->orderBy('created_at')->limit(50)->get()
            ->map(fn($a) => [
                'ts' => optional($a->created_at)->format('d M Y H:i'),
                'actor' => $a->user_id ?: 'system',
                'action' => $a->action,
            ])->all();

        $deadline = $dsr->deadline_at;
        $closed = $dsr->closed_at ?? now();
        $slaStatus = $deadline && $closed->lte($deadline)
            ? '✓ Tepat waktu (' . $closed->diffForHumans($deadline, ['parts' => 2]) . ')'
            : '⚠ Melewati deadline';

        return [
            'org' => $org,
            'orgName' => $org->name,
            'orgWebsite' => $org->website ?? null,
            'orgLogoUrl' => $org->logo_url ?? null,
            'dsr' => $dsr,
            'requestTypeLabel' => self::REQUEST_TYPE_LABELS[$dsr->request_type] ?? ucfirst($dsr->request_type),
            'executions' => $executions,
            'evidenceList' => $evidenceList,
            'auditTrail' => $audit,
            'slaStatus' => $slaStatus,
            'dpoName' => optional($dsr->assignee)->name ?? null,
            'verificationStamp' => $this->verificationStamp($dsr, 'internal'),
        ];
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '***';
        [$local, $domain] = explode('@', $email, 2);
        $localMasked = strlen($local) <= 2 ? str_repeat('*', strlen($local)) : substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));
        return $localMasked . '@' . $domain;
    }

    private function actionLabel(array $types): string
    {
        $labels = array_unique(array_map(fn($t) => self::REQUEST_TYPE_LABELS[$t] ?? $t, $types));
        return implode(' · ', $labels);
    }

    private function verificationStamp(DsrRequest $dsr, string $variant): string
    {
        return strtoupper(substr(hash('sha256', $dsr->id . '|' . $variant . '|' . $dsr->updated_at), 0, 16));
    }
}
