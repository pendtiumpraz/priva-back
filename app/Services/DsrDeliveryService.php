<?php

namespace App\Services;

use App\Mail\DsrReportMail;
use App\Models\AuditLog;
use App\Models\DsrOutboundTarget;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pengiriman hasil permohonan subjek data ke luar sistem: lewat surel kepada
 * pemohon, dan lewat API kepada sistem klien.
 *
 * Keduanya disatukan di sini karena memakai muatan yang sama. Menyusunnya dua
 * kali di dua tempat berbeda hampir pasti melahirkan selisih — dan selisih
 * antara apa yang diterima pemohon dan apa yang tercatat di CRM adalah jenis
 * ketidaksesuaian yang paling sulit ditelusuri belakangan.
 */
class DsrDeliveryService
{
    /**
     * Kirim laporan ke pemohon beserta lampiran PDF.
     *
     * @return array{sent: bool, to: ?string, attached: bool, error: ?string}
     */
    public function emailReport(DsrRequest $dsr, ?string $bodyText = null, bool $attachPdf = true): array
    {
        $to = $dsr->requester_email;
        if (! $to) {
            return ['sent' => false, 'to' => null, 'attached' => false, 'error' => 'Pemohon tidak memiliki alamat surel.'];
        }

        $org = Organization::withoutGlobalScope('org')->find($dsr->org_id);
        $pdf = null;
        $pdfName = null;

        if ($attachPdf) {
            try {
                $pdf = $this->renderPdf($dsr, $org);
                $pdfName = 'DSR_'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $dsr->request_id).'.pdf';
            } catch (\Throwable $e) {
                // Kegagalan membuat lampiran tidak boleh membatalkan surelnya.
                // Pemohon lebih baik menerima pemberitahuan tanpa lampiran
                // daripada tidak menerima apa pun sementara tenggat berjalan.
                Log::warning('Lampiran PDF DSR gagal dibuat: '.$e->getMessage(), ['dsr_id' => $dsr->id]);
            }
        }

        $body = $bodyText ?: ($dsr->response ?: 'Permohonan Anda telah selesai kami tindak lanjuti.');

        try {
            Mail::to($to)->send(new DsrReportMail($dsr, $body, $pdf, $pdfName, $org?->name));
        } catch (\Throwable $e) {
            Log::error('Pengiriman surel laporan DSR gagal: '.$e->getMessage(), ['dsr_id' => $dsr->id]);

            return ['sent' => false, 'to' => $to, 'attached' => false, 'error' => $e->getMessage()];
        }

        AuditLog::log('dsr', $dsr->id, 'report_emailed', [
            'to' => $to,
            'attached' => $pdf !== null,
        ], 'delivery');

        return ['sent' => true, 'to' => $to, 'attached' => $pdf !== null, 'error' => null];
    }

    /**
     * Kirim permohonan ke seluruh tujuan API yang aktif untuk sebuah peristiwa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pushToTargets(DsrRequest $dsr, string $event, ?string $targetId = null): array
    {
        $query = DsrOutboundTarget::where('is_active', true);
        if ($targetId) {
            $query->where('id', $targetId);
        }

        $results = [];
        foreach ($query->get() as $target) {
            // Tujuan tanpa daftar peristiwa berarti menerima semuanya.
            $events = $target->events ?? [];
            if (! $targetId && ! empty($events) && ! in_array($event, $events, true)) {
                continue;
            }
            $results[] = $this->deliver($target, $dsr, $event);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function deliver(DsrOutboundTarget $target, DsrRequest $dsr, string $event): array
    {
        $payload = $this->buildPayload($dsr, $event, $target->payload_format);

        $headers = ['Content-Type' => 'application/json'];
        if ($target->auth_header) {
            // Header disimpan utuh dalam bentuk "Nama: nilai" supaya klien
            // bebas memakai Authorization, X-API-Key, atau nama lain tanpa
            // perlu perubahan kode di sisi kami.
            [$name, $value] = array_pad(explode(':', (string) $target->auth_header, 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if ($name !== '' && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $attempts = max(1, (int) $target->retry_count + 1);
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout((int) $target->timeout_seconds)
                    ->post($target->url, $payload);

                if ($response->successful()) {
                    $target->increment('total_deliveries');
                    $target->update(['last_delivered_at' => now()]);

                    AuditLog::log('dsr', $dsr->id, 'pushed_to_api', [
                        'target' => $target->name,
                        'event' => $event,
                        'status' => $response->status(),
                    ], 'delivery');

                    return [
                        'target' => $target->name,
                        'target_id' => $target->id,
                        'ok' => true,
                        'status' => $response->status(),
                        'attempts' => $i + 1,
                    ];
                }

                $lastError = 'HTTP '.$response->status();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $target->increment('failed_deliveries');
        Log::warning('Pengiriman DSR ke API gagal', [
            'target' => $target->name,
            'dsr_id' => $dsr->id,
            'error' => $lastError,
        ]);

        return [
            'target' => $target->name,
            'target_id' => $target->id,
            'ok' => false,
            'error' => $lastError,
            'attempts' => $attempts,
        ];
    }

    /**
     * Muatan permohonan dalam bentuk yang diminta tujuan.
     *
     * Bentuk Salesforce memakai penamaan field khas platform itu. Memaksanya
     * memakai bentuk generik berarti setiap klien harus menulis lapisan
     * penerjemah sendiri — persis pekerjaan yang seharusnya kami tanggung.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(DsrRequest $dsr, string $event, string $format = 'generic'): array
    {
        $base = [
            'request_id' => $dsr->request_id,
            'request_type' => $dsr->request_type,
            'status' => $dsr->status,
            'requester_name' => $dsr->requester_name,
            'requester_email' => $dsr->requester_email,
            'requester_phone' => $dsr->requester_phone,
            'description' => $dsr->description,
            'response' => $dsr->response,
            'deadline_at' => $dsr->deadline_at?->toIso8601String(),
            'responded_at' => $dsr->responded_at?->toIso8601String(),
            'verified_at' => $dsr->verified_at?->toIso8601String(),
        ];

        if ($format === 'salesforce') {
            return [
                'Subject' => 'DSR '.$dsr->request_id.' — '.$dsr->request_type,
                'Description' => $dsr->description,
                'Status' => $this->salesforceStatus($dsr->status),
                'Origin' => 'Privasimu Nexus',
                'SuppliedName' => $dsr->requester_name,
                'SuppliedEmail' => $dsr->requester_email,
                'SuppliedPhone' => $dsr->requester_phone,
                'Type' => $dsr->request_type,
                'Privasimu_Request_Id__c' => $dsr->request_id,
                'Privasimu_Event__c' => $event,
                'Privasimu_Deadline__c' => $dsr->deadline_at?->toIso8601String(),
            ];
        }

        return array_merge(['event' => $event, 'source' => 'privasimu-nexus'], $base);
    }

    /**
     * Status internal dipetakan ke kosakata Case bawaan Salesforce.
     *
     * Status yang tidak punya padanan sengaja jatuh ke "New" alih-alih dikirim
     * apa adanya: nilai di luar picklist akan ditolak Salesforce dan seluruh
     * pengirimannya gagal, bukan hanya field itu.
     */
    private function salesforceStatus(?string $status): string
    {
        return match ($status) {
            'completed', 'closed' => 'Closed',
            'in_progress', 'processing' => 'Working',
            'rejected' => 'Closed',
            default => 'New',
        };
    }

    private function renderPdf(DsrRequest $dsr, ?Organization $org): ?string
    {
        if (! $org) {
            return null;
        }

        $dsr->loadMissing(['app', 'assignee']);
        $sections = app(RecordReportBuilder::class)->dsr($dsr);

        return app(RecordDocRenderer::class)
            ->pdf($org, $dsr->assignee ?? new User, 'Dokumen DSR — '.$dsr->request_id, 'DSR', $sections)
            ->output();
    }
}
