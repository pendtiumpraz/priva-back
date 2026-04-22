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
        $pdf = $this->buildPdf($request, 'reports.breach.komdigi', $breach);
        return $pdf->download("Surat-Notifikasi-KOMDIGI_{$breach->incident_code}.pdf");
    }

    public function subjectLetter(Request $request, string $id)
    {
        $breach = $this->loadBreach($request, $id);
        $pdf = $this->buildPdf($request, 'reports.breach.subject', $breach);
        return $pdf->download("Himbauan-Keamanan-Akun_{$breach->incident_code}.pdf");
    }

    public function fullReport(Request $request, string $id)
    {
        $breach = $this->loadBreach($request, $id);
        $pdf = $this->buildPdf($request, 'reports.breach.full-report', $breach);
        return $pdf->download("Breach-Report_{$breach->incident_code}.pdf");
    }

    /**
     * Supported paper sizes for all report endpoints.
     * Accept as ?size=a4 (default) | letter | legal | a3 | a5 | folio.
     * Accept orientation as ?orientation=portrait (default) | landscape.
     */
    private const PAPER_SIZES = ['a3', 'a4', 'a5', 'letter', 'legal', 'folio'];

    private function buildPdf(Request $request, string $view, BreachIncident $breach)
    {
        $size = strtolower((string) $request->get('size', 'a4'));
        if (!in_array($size, self::PAPER_SIZES, true)) {
            $size = 'a4';
        }
        $orientation = $request->get('orientation') === 'landscape' ? 'landscape' : 'portrait';

        return Pdf::loadView($view, $this->buildPayload($request, $breach))
            ->setPaper($size, $orientation)
            ->setOption(['isHtml5ParserEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
    }

    private function loadBreach(Request $request, string $id): BreachIncident
    {
        return BreachIncident::where('org_id', $request->user()->org_id)->findOrFail($id);
    }

    private function buildPayload(Request $request, BreachIncident $breach): array
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);
        $settings = $org->settings ?? [];
        // DPO pick: anyone with role=dpo, fallback to caller
        $dpo = User::where('org_id', $org->id)->where('role', 'dpo')->first() ?? $user;

        return [
            'breach' => $breach,
            'org' => $org,
            'orgName' => $org->name,
            'orgLogoUrl' => $settings['logo_url'] ?? ($org->logo_url ?? null),
            'orgAddress' => $org->address ?? null,
            'orgWebsite' => $org->website ?? null,
            'orgEmail' => $org->email ?? null,
            'orgPhone' => $org->phone ?? null,
            'dpoName' => $dpo->name,
            'dpoEmail' => $dpo->email,
            'dpoPhone' => $dpo->phone ?? null,
            'watermark' => $settings['watermark'] ?? null,
            'documentHeader' => $settings['document_header'] ?? null,
            'documentFooter' => $settings['document_footer'] ?? null,
            'today' => now()->locale('id')->isoFormat('D MMMM Y'),
            'generatedAt' => now()->locale('id')->isoFormat('D MMMM Y · HH:mm'),
            'generatedBy' => $user->name,
        ];
    }
}
