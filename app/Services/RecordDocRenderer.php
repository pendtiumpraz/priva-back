<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Renders a per-record report (section schema from RecordReportBuilder) to PDF
 * (Blade + dompdf) or DOCX (PhpWord), branded with the tenant's active
 * DocumentTemplate. Companion to BrandedXlsxExporter::exportRecord() so the
 * same sections drive all three formats.
 *
 * @param  array<int,array{title:string,rows:array<int,array{label:string,value:string}>}>  $sections
 */
class RecordDocRenderer
{
    /** Return a dompdf instance for a per-record document. */
    public function pdf(Organization $org, User $user, string $title, string $kindLabel, array $sections)
    {
        $payload = $this->payload($org, $user, $title, $kindLabel, $sections);

        return Pdf::loadView('reports.generic-record', $payload)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $payload['config']['font_family'] ?? 'DejaVu Sans',
            ]);
    }

    /** Build a DOCX file and return its temp path (caller deletes after send). */
    public function docx(Organization $org, User $user, string $title, string $kindLabel, array $sections): string
    {
        $config = $this->config($org);
        $primary = $this->hex($config['primary_color'] ?? ($config['accent_color'] ?? null)) ?: '16284C';

        $word = new PhpWord;
        $word->setDefaultFontName('DejaVu Sans');
        $word->setDefaultFontSize(10);
        $section = $word->addSection(['marginTop' => 720, 'marginBottom' => 720, 'marginLeft' => 900, 'marginRight' => 900]);

        // Header
        $section->addText(htmlspecialchars($org->name ?? '-'), ['bold' => true, 'size' => 16, 'color' => $primary]);
        $section->addText(htmlspecialchars($kindLabel), ['size' => 9, 'color' => '808080', 'allCaps' => true]);
        $section->addText(htmlspecialchars($title), ['bold' => true, 'size' => 13, 'color' => '111827']);
        $section->addText('Dibuat: '.now()->locale('id')->isoFormat('D MMMM Y · HH:mm').' WIB · oleh '.htmlspecialchars($user->name ?? '-'), ['size' => 8, 'color' => '6B7280']);
        $section->addTextBreak(1);

        foreach ($sections as $sec) {
            // Section title band (full-width shaded single-cell row).
            $band = $section->addTable(['width' => 100 * 50, 'unit' => 'pct']);
            $band->addRow();
            $cell = $band->addCell(null, ['bgColor' => $primary]);
            $cell->addText(htmlspecialchars($sec['title'] ?? ''), ['bold' => true, 'size' => 11, 'color' => 'FFFFFF'], ['spaceBefore' => 40, 'spaceAfter' => 40]);

            $table = $section->addTable([
                'borderColor' => 'D0D7E2', 'borderSize' => 6, 'cellMargin' => 70,
                'width' => 100 * 50, 'unit' => 'pct',
            ]);
            foreach (($sec['rows'] ?? []) as $row) {
                $table->addRow();
                $k = $table->addCell(3200, ['bgColor' => 'F4F6FA', 'valign' => 'top']);
                $k->addText(htmlspecialchars((string) ($row['label'] ?? '')), ['bold' => true, 'size' => 10, 'color' => '374151']);
                $v = $table->addCell(6800, ['valign' => 'top']);
                $value = (string) ($row['value'] ?? '');
                if ($value === '') {
                    $value = '-';
                }
                foreach (explode("\n", $value) as $i => $line) {
                    if ($i > 0) {
                        $v->addTextBreak();
                    }
                    $v->addText(htmlspecialchars($line), ['size' => 10]);
                }
            }
            $section->addTextBreak(1);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rec_docx_').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($tmp);

        return $tmp;
    }

    private function payload(Organization $org, User $user, string $title, string $kindLabel, array $sections): array
    {
        $config = $this->config($org);
        $orgLogo = $config['logo_data_uri'] ?? ($org->logo_url ?? null);

        return [
            'org' => $org,
            'orgName' => $org->name,
            'orgWebsite' => $org->website ?? null,
            'orgLogoUrl' => $this->toDataUri($orgLogo),
            'config' => $config,
            'kindLabel' => $kindLabel,
            'title' => $title,
            'sections' => $sections,
            'generatedAt' => now()->locale('id')->isoFormat('D MMMM Y · HH:mm').' WIB',
            'generatedBy' => $user->name,
        ];
    }

    private function config(Organization $org): array
    {
        $template = DocumentTemplate::activeForOrg($org->id, 'record');
        $config = $template ? $template->mergedConfig() : DocumentTemplate::DEFAULT_CONFIG;
        $config['watermark_image'] = null;

        return $config;
    }

    private function hex(?string $hex): ?string
    {
        if (! $hex) {
            return null;
        }
        $hex = ltrim(trim($hex), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : null;
    }

    private function toDataUri(?string $urlOrPath): ?string
    {
        if (! $urlOrPath) {
            return null;
        }
        if (str_starts_with($urlOrPath, 'data:')) {
            return $urlOrPath;
        }
        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            try {
                $bytes = @file_get_contents($urlOrPath);
                if ($bytes === false) {
                    return null;
                }

                return 'data:'.$this->mimeFromUrl($urlOrPath).';base64,'.base64_encode($bytes);
            } catch (\Throwable) {
                return null;
            }
        }
        $relative = ltrim(parse_url($urlOrPath, PHP_URL_PATH) ?? $urlOrPath, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, 8);
        }
        if (Storage::disk('public')->exists($relative)) {
            return 'data:'.$this->mimeFromUrl($urlOrPath).';base64,'.base64_encode(Storage::disk('public')->get($relative));
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
