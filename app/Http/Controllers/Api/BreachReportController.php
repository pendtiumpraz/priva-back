<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreachIncident;
use App\Models\Organization;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * PDF document generator for breach incidents.
 *
 * 3 document types (Pasal 46 UU PDP compliance artifacts):
 *   - komdigi  : Surat Notifikasi ke KOMDIGI (formal, 3x24h notification)
 *   - subject  : Data Subject Notification Letter (himbauan ganti
 *                credential — tidak menyebut sebab biar anti-churn)
 *   - report   : Breach Incident Full Report (offline-ready, RACI,
 *                containment timeline, RCA, remediation)
 *
 * All documents apply basic tenant branding (org name + logo URL from
 * organization.settings). Phase D extends TenantTheme with richer
 * document-template fields.
 */
class BreachReportController extends Controller
{
    public function komdigi(Request $request, string $id)
    {
        $breach = $this->loadBreach($request, $id);
        $pdf = $this->buildPdf($request, 'reports.breach.komdigi', $breach, 'breach_komdigi');
        return $pdf->download("Surat-Notifikasi-KOMDIGI_{$breach->incident_code}.pdf");
    }

    public function subjectLetter(Request $request, string $id)
    {
        $breach = $this->loadBreach($request, $id);
        $pdf = $this->buildPdf($request, 'reports.breach.subject', $breach, 'breach_subject');
        return $pdf->download("Himbauan-Keamanan-Akun_{$breach->incident_code}.pdf");
    }

    public function fullReport(Request $request, string $id)
    {
        $breach = $this->loadBreach($request, $id);
        $pdf = $this->buildPdf($request, 'reports.breach.full-report', $breach, 'breach_report');
        return $pdf->download("Breach-Report_{$breach->incident_code}.pdf");
    }

    /**
     * Supported paper sizes for all report endpoints.
     * Accept as ?size=a4 (default) | letter | legal | a3 | a5 | folio.
     * Accept orientation as ?orientation=portrait (default) | landscape.
     */
    private const PAPER_SIZES = ['a3', 'a4', 'a5', 'letter', 'legal', 'folio'];

    private function buildPdf(Request $request, string $view, BreachIncident $breach, ?string $kind = null)
    {
        $payload = $this->buildPayload($request, $breach, $kind);
        $defaultSize = $payload['config']['page_size'] ?? 'a4';
        $defaultFont = $payload['config']['font_family'] ?? 'DejaVu Sans';

        $size = strtolower((string) $request->get('size', $defaultSize));
        if (!in_array($size, self::PAPER_SIZES, true)) {
            $size = 'a4';
        }
        $orientation = $request->get('orientation') === 'landscape' ? 'landscape' : 'portrait';

        return Pdf::loadView($view, $payload)
            ->setPaper($size, $orientation)
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $defaultFont,
            ]);
    }

    private function loadBreach(Request $request, string $id): BreachIncident
    {
        return BreachIncident::where('org_id', $request->user()->org_id)->findOrFail($id);
    }

    private function buildPayload(Request $request, BreachIncident $breach, ?string $kind = null): array
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);
        $settings = $org->settings ?? [];
        // DPO pick: anyone with role=dpo, fallback to caller
        $dpo = User::where('org_id', $org->id)->where('role', 'dpo')->first() ?? $user;

        // Pull active document template config (Phase D) — per-kind aware.
        // `$kind` is one of 'breach_report' / 'breach_komdigi' / 'breach_subject'
        // (Phase H1), falling through to map.default → legacy → system default.
        $template = \App\Models\DocumentTemplate::activeForOrg($org->id, $kind);
        $config = $template ? $template->mergedConfig() : \App\Models\DocumentTemplate::DEFAULT_CONFIG;

        // Inline asset images as data URIs so dompdf always renders them.
        $config['watermark_image'] = $this->toDataUri($config['watermark_image'] ?? null, $org);
        $config['cover_bg_image'] = $this->toDataUri($config['cover_bg_image'] ?? null, $org);
        $orgLogo = $settings['logo_url'] ?? ($org->logo_url ?? null);

        return [
            'breach' => $breach,
            'org' => $org,
            'orgName' => $org->name,
            'orgLogoUrl' => $this->toDataUri($orgLogo, $org),
            'orgAddress' => $org->address ?? null,
            'orgWebsite' => $org->website ?? null,
            'orgEmail' => $org->email ?? null,
            'orgPhone' => $org->phone ?? null,
            'dpoName' => $dpo->name,
            'dpoEmail' => $dpo->email,
            'dpoPhone' => $dpo->phone ?? null,
            'watermark' => $settings['watermark'] ?? ($config['watermark_text'] ?? null),
            'documentHeader' => $settings['document_header'] ?? ($config['header_text'] ?? null),
            'documentFooter' => $settings['document_footer'] ?? ($config['footer_text'] ?? null),
            'config' => $config,
            'today' => now()->locale('id')->isoFormat('D MMMM Y'),
            'generatedAt' => now()->locale('id')->isoFormat('D MMMM Y · HH:mm'),
            'generatedBy' => $user->name,
        ];
    }

    /**
     * Resolve an asset URL/path to a data URI for dompdf embedding.
     * Mirrors DocumentTemplateController::assetUrlToDataUri.
     */
    private function toDataUri(?string $urlOrPath, Organization $org): ?string
    {
        if (!$urlOrPath || str_starts_with($urlOrPath, 'data:')) return $urlOrPath;
        try {
            $parsed = parse_url($urlOrPath);
            $path = $parsed['path'] ?? $urlOrPath;

            if (str_contains($path, '/storage/')) {
                $rel = ltrim(preg_replace('#^.*/storage/#', '', $path), '/');
                $disk = \Illuminate\Support\Facades\Storage::disk('public');
                if ($disk->exists($rel)) {
                    return 'data:' . ($disk->mimeType($rel) ?: 'image/png') . ';base64,' . base64_encode($disk->get($rel));
                }
            }
            if (preg_match('#(tenants/[a-f0-9-]+/[^?#]+)#i', $urlOrPath, $m)) {
                $rel = $m[1];
                $disk = app(\App\Services\TenantStorageService::class)->getPublicDisk($org);
                if ($disk->exists($rel)) {
                    return 'data:' . ($disk->mimeType($rel) ?: 'image/png') . ';base64,' . base64_encode($disk->get($rel));
                }
            }
            if (preg_match('#^https?://#i', $urlOrPath)) {
                $ctx = stream_context_create(['http' => ['timeout' => 4]]);
                $bytes = @file_get_contents($urlOrPath, false, $ctx);
                if ($bytes !== false) {
                    $ext = strtolower(pathinfo(parse_url($urlOrPath, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) ?: 'png';
                    return 'data:image/' . $ext . ';base64,' . base64_encode($bytes);
                }
            }
        } catch (\Throwable $e) { /* fall through */ }
        return $urlOrPath;
    }
}
