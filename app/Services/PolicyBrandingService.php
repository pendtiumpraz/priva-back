<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the white-label branding payload for a tenant's rendered policy
 * (PDF + HTML embed): the active DocumentTemplate config (colors/header/footer/
 * watermark) merged with org identity (name, website, logo as a dompdf-safe
 * data URI). Mirrors AssessmentPdfService::commonPayload (kept self-contained so
 * Policy Generator does not depend on that service's private helpers).
 */
class PolicyBrandingService
{
    /**
     * @return array{config:array<string,mixed>,orgName:?string,orgWebsite:?string,orgLogoUrl:?string}
     */
    public function payload(?Organization $org): array
    {
        $template = $org ? DocumentTemplate::activeForOrg($org->id, 'policy') : null;
        $config = $template ? $template->mergedConfig() : DocumentTemplate::DEFAULT_CONFIG;

        $logo = $config['logo_data_uri'] ?? ($org->logo_url ?? null);

        return [
            'config' => $config,
            'orgName' => $org?->name,
            'orgWebsite' => $org?->website,
            'orgLogoUrl' => $this->toDataUri($logo),
        ];
    }

    /** Convert a logo URL/path into a base64 data URI (dompdf cannot fetch remote assets reliably). */
    private function toDataUri(?string $urlOrPath): ?string
    {
        if (! $urlOrPath) {
            return null;
        }
        if (str_starts_with($urlOrPath, 'data:')) {
            return $urlOrPath;
        }

        try {
            if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
                $bytes = @file_get_contents($urlOrPath);

                return $bytes === false ? null : 'data:'.$this->mime($urlOrPath).';base64,'.base64_encode($bytes);
            }

            $relative = ltrim(parse_url($urlOrPath, PHP_URL_PATH) ?? $urlOrPath, '/');
            if (str_starts_with($relative, 'storage/')) {
                $relative = substr($relative, 8);
            }
            if (Storage::disk('public')->exists($relative)) {
                return 'data:'.$this->mime($urlOrPath).';base64,'.base64_encode(Storage::disk('public')->get($relative));
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function mime(string $url): string
    {
        return match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? $url, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
