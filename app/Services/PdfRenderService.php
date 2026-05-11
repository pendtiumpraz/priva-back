<?php

namespace App\Services;

use Illuminate\Support\Facades\View;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

/**
 * Satu pintu render PDF dari Blade view, mendukung dua engine:
 *
 *   - dompdf      → 18 template PDF "elegant" yang sudah produktif.
 *   - browsershot → 2 template yang butuh CSS modern (mix-blend-mode,
 *                   backdrop-filter, gradient text-clip, filter blur).
 *
 * Pemilihan engine ditentukan oleh kolom `engine` pada DocumentTemplate;
 * caller (mis. DocumentTemplateController::preview) cukup meneruskannya
 * lewat $options['engine'].
 */
class PdfRenderService
{
    /**
     * @param  string  $view  Nama Blade view (mis. "reports.templates.cyber-glass").
     * @param  array<string,mixed>  $payload  Variabel yang diteruskan ke Blade.
     * @param  array<string,mixed>  $options  engine|page_size|orientation|font_family.
     * @return string Binary PDF.
     */
    public function render(string $view, array $payload, array $options = []): string
    {
        $engine = $options['engine'] ?? 'dompdf';
        $pageSize = $options['page_size'] ?? 'A4';
        $orientation = $options['orientation'] ?? 'portrait';

        if ($engine === 'browsershot') {
            return $this->renderViaBrowsershot($view, $payload, $pageSize, $orientation);
        }

        return $this->renderViaDompdf($view, $payload, $pageSize, $orientation, $options);
    }

    private function renderViaDompdf(string $view, array $payload, string $pageSize, string $orientation, array $options): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $payload)
            ->setPaper(strtolower($pageSize), $orientation)
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $options['font_family'] ?? 'DejaVu Sans',
            ])
            ->output();
    }

    private function renderViaBrowsershot(string $view, array $payload, string $pageSize, string $orientation): string
    {
        $html = View::make($view, $payload)->render();

        $chromePath = $this->resolveChromePath();
        $nodeBinary = $this->resolveNodeBinary();

        $shot = Browsershot::html($html)
            ->setChromePath($chromePath)
            ->format($pageSize)
            ->margins(0, 0, 0, 0)
            ->showBackground()
            ->emulateMedia('print')
            ->waitUntilNetworkIdle()
            ->timeout((int) config('browsershot.timeout', 60));

        if ($nodeBinary) {
            $shot->setNodeBinary($nodeBinary);
        }

        if ($orientation === 'landscape') {
            $shot->landscape();
        }

        // Sandbox Chromium tidak tersedia di banyak container — flag ini
        // wajib disetel true di Docker/CI agar headless Chrome dapat berjalan.
        if (config('browsershot.no_sandbox', false)) {
            $shot->noSandbox();
        }

        return $shot->pdf();
    }

    private function resolveChromePath(): string
    {
        $configured = config('browsershot.chrome_path');
        if ($configured && @is_file($configured)) {
            return $configured;
        }

        $candidates = $this->chromeCandidates();
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            'Chrome/Chromium tidak ditemukan. Atur `BROWSERSHOT_CHROME_PATH` di file .env.'
        );
    }

    private function resolveNodeBinary(): ?string
    {
        $configured = config('browsershot.node_path');
        if ($configured && @is_file($configured)) {
            return $configured;
        }

        // Cari di PATH OS (`where`/`which`).
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'where node' : 'which node';
        $found = @shell_exec($cmd);
        if (is_string($found)) {
            $first = trim(strtok($found, "\n"));
            if ($first !== '' && @is_file($first)) {
                return $first;
            }
        }

        $fallbacks = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:/Program Files/nodejs/node.exe',
                'C:/Program Files (x86)/nodejs/node.exe',
            ]
            : [
                '/usr/bin/node',
                '/usr/local/bin/node',
            ];

        foreach ($fallbacks as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function chromeCandidates(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $user = getenv('USERNAME') ?: getenv('USER') ?: '';
            $localAppData = getenv('LOCALAPPDATA') ?: ($user ? "C:/Users/{$user}/AppData/Local" : '');

            return array_filter([
                $localAppData ? $localAppData.'/Google/Chrome/Application/chrome.exe' : null,
                'C:/Program Files/Google/Chrome/Application/chrome.exe',
                'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
                $localAppData ? $localAppData.'/Microsoft/Edge/Application/msedge.exe' : null,
            ]);
        }

        return [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/snap/bin/chromium',
        ];
    }
}
