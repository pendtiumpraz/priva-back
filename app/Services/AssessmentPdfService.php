<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\LiaAssessment;
use App\Models\MaturityAssessment;
use App\Models\Organization;
use App\Models\TiaAssessment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Render LIA / TIA / Maturity assessments to PDF for board / regulator review.
 *
 * Each builder returns a streamable Pdf instance — the controller decides
 * whether to download() or stream(). All three honor the tenant's active
 * DocumentTemplate (font, colors, watermark, header/footer) so the
 * exported PDF matches the rest of the tenant's branded output.
 */
class AssessmentPdfService
{
    public function lia(LiaAssessment $lia, User $user)
    {
        $org = Organization::findOrFail($lia->org_id);
        $payload = $this->commonPayload($org, $user, 'lia');
        $payload['lia'] = $lia->load(['ropa:id,registration_number,processing_activity', 'maker:id,name', 'checker:id,name', 'approver:id,name']);
        $payload['verdict'] = $lia->overallVerdict();
        $payload['verdictLabel'] = $payload['verdict'] === LiaAssessment::VERDICT_PASS
            ? 'LULUS UJI LEGITIMATE INTEREST'
            : ($payload['verdict'] === LiaAssessment::VERDICT_FAIL ? 'TIDAK LULUS' : 'BELUM DIPUTUSKAN');

        return Pdf::loadView('reports.assessments.lia', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $payload['config']['font_family'] ?? 'DejaVu Sans',
            ]);
    }

    public function tia(TiaAssessment $tia, User $user)
    {
        $org = Organization::findOrFail($tia->org_id);
        $payload = $this->commonPayload($org, $user, 'tia');
        $payload['tia'] = $tia->load(['ropa:id,registration_number,processing_activity', 'crossBorder:id,destination_country,destination_entity', 'vendor:id,name', 'maker:id,name', 'checker:id,name', 'approver:id,name']);
        $payload['overallRisk'] = $tia->computeOverallRisk();
        $payload['riskLevel'] = $tia->riskLevel();

        return Pdf::loadView('reports.assessments.tia', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $payload['config']['font_family'] ?? 'DejaVu Sans',
            ]);
    }

    public function maturity(MaturityAssessment $assessment, User $user)
    {
        $org = Organization::findOrFail($assessment->org_id);
        $payload = $this->commonPayload($org, $user, 'maturity');
        $payload['assessment'] = $assessment->load(['responses', 'submitter:id,name']);
        $payload['levelLabel'] = $assessment->levelLabel();

        // Group responses by domain for neat per-domain section
        $byDomain = $assessment->responses->groupBy('domain');
        $payload['responsesByDomain'] = $byDomain->map(function ($items) {
            return $items->sortBy('question_code')->values();
        });

        return Pdf::loadView('reports.assessments.maturity', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $payload['config']['font_family'] ?? 'DejaVu Sans',
            ]);
    }

    private function commonPayload(Organization $org, User $user, string $kind): array
    {
        $template = DocumentTemplate::activeForOrg($org->id, $kind);
        $config = $template ? $template->mergedConfig() : DocumentTemplate::DEFAULT_CONFIG;

        $orgLogo = $config['logo_data_uri'] ?? ($org->logo_url ?? null);
        $config['watermark_image'] = $this->toDataUri($config['watermark_image'] ?? null);
        $config['cover_bg_image'] = $this->toDataUri($config['cover_bg_image'] ?? null);

        return [
            'org' => $org,
            'orgName' => $org->name,
            'orgWebsite' => $org->website ?? null,
            'orgEmail' => $org->email ?? null,
            'orgLogoUrl' => $this->toDataUri($orgLogo),
            'config' => $config,
            'kindLabel' => match ($kind) {
                'lia' => 'Legitimate Interest Assessment',
                'tia' => 'Transfer Impact Assessment',
                'maturity' => 'Maturity Assessment UU PDP',
                default => 'Assessment',
            },
            'generatedAt' => now()->locale('id')->isoFormat('D MMMM Y · HH:mm') . ' WIB',
            'generatedBy' => $user->name,
        ];
    }

    private function toDataUri(?string $urlOrPath): ?string
    {
        if (!$urlOrPath) return null;
        if (str_starts_with($urlOrPath, 'data:')) return $urlOrPath;

        // public URL → fetch via http only if remote; for local public/ we read disk
        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            try {
                $bytes = @file_get_contents($urlOrPath);
                if ($bytes === false) return null;
                $mime = $this->mimeFromUrl($urlOrPath);
                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            } catch (\Throwable) {
                return null;
            }
        }

        // local relative path under storage/public
        $relative = ltrim(parse_url($urlOrPath, PHP_URL_PATH) ?? $urlOrPath, '/');
        if (str_starts_with($relative, 'storage/')) $relative = substr($relative, 8);
        if (Storage::disk('public')->exists($relative)) {
            $bytes = Storage::disk('public')->get($relative);
            $mime = $this->mimeFromUrl($urlOrPath);
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
        return null;
    }

    private function mimeFromUrl(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? $url, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
