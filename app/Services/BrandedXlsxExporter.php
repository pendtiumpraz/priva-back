<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\TenantTheme;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * BrandedXlsxExporter
 *
 * Renders a two-sheet branded XLSX export ("Cover" + "Data") with:
 *  - Org logo (when available) + name + document title on cover
 *  - Export metadata (Indonesian date, exporter, filters, row count)
 *  - Branded data sheet using the tenant theme primary color for headers
 *  - Alternating row backgrounds, auto-width columns, frozen header row
 *  - Indonesian date formatting and Ya/Tidak booleans
 *
 * Column config shape per entry:
 *   [
 *     'key'    => 'string',          // accessor key used to look up row value
 *     'label'  => 'string',          // header text shown in spreadsheet
 *     'format' => 'date'|'datetime'|'boolean'|'number'|'text', // optional, default 'text'
 *     'width'  => 18,                // optional explicit column width
 *   ]
 *
 * Row shape: associative array keyed by column['key'].
 */
class BrandedXlsxExporter
{
    private const FALLBACK_PRIMARY = '16284C';   // navy
    private const ROW_EVEN_BG = 'FAFAFC';
    private const BORDER_GRAY = 'D0D7E2';

    private const ID_MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /**
     * Build the workbook and return a temp file path. Caller is responsible
     * for streaming the file back and deleting it once sent.
     *
     * @param  array<int,array<string,mixed>>  $rows  associative row data keyed by column['key']
     * @param  array<int,array<string,mixed>>  $columnConfig
     * @param  array<string,mixed>             $filters  ['Label' => 'Value', ...]
     */
    public function export(
        Organization $org,
        string $title,
        string $module,
        array $rows,
        array $columnConfig,
        array $filters = [],
        ?User $user = null,
    ): string {
        $palette = $this->resolvePalette($org);
        $primaryHex = $this->normalizeHex($palette['primary'] ?? null) ?: self::FALLBACK_PRIMARY;

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Privasimu Nexus')
            ->setTitle($title)
            ->setSubject($module)
            ->setDescription("Branded export — {$title}");

        $this->buildCoverSheet($spreadsheet, $org, $title, $module, count($rows), $filters, $user, $primaryHex);
        $this->buildDataSheet($spreadsheet, $rows, $columnConfig, $primaryHex);

        $spreadsheet->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'branded_xlsx_').'.xlsx';
        $writer = new XlsxWriter($spreadsheet);
        $writer->save($tmp);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $tmp;
    }

    /**
     * Suggested filename: {ModuleName}_{OrgSlug}_{YYYY-MM-DD}.xlsx
     */
    public function suggestedFilename(string $moduleName, Organization $org): string
    {
        $slug = $org->slug ?: Str::slug($org->name ?: 'organisasi');
        $date = now()->format('Y-m-d');
        $safeModule = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '', $moduleName)) ?: 'Export';

        return "{$safeModule}_{$slug}_{$date}.xlsx";
    }

    // =====================================================================
    // Cover sheet
    // =====================================================================
    private function buildCoverSheet(
        Spreadsheet $spreadsheet,
        Organization $org,
        string $title,
        string $module,
        int $rowCount,
        array $filters,
        ?User $user,
        string $primaryHex,
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cover');
        $sheet->getSheetView()->setShowGridLines(false);

        // Page columns A..F (B is the content column, with a left margin).
        $sheet->getColumnDimension('A')->setWidth(3);
        $sheet->getColumnDimension('B')->setWidth(34);
        $sheet->getColumnDimension('C')->setWidth(34);
        $sheet->getColumnDimension('D')->setWidth(3);

        // Top accent band
        $sheet->getStyle('A1:D1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $primaryHex]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(8);

        // Logo (if local resolvable file). Best-effort — silently skipped on failure.
        $logoPath = $this->resolveLogoLocalPath($org);
        $contentStartRow = 4;
        if ($logoPath) {
            try {
                $drawing = new Drawing;
                $drawing->setName('Organization Logo');
                $drawing->setDescription($org->name ?? 'Logo');
                $drawing->setPath($logoPath);
                $drawing->setHeight(56);
                $drawing->setCoordinates('B'.$contentStartRow);
                $drawing->setOffsetX(0);
                $drawing->setOffsetY(0);
                $drawing->setWorksheet($sheet);
                $sheet->getRowDimension($contentStartRow)->setRowHeight(64);
                $contentStartRow += 2;
            } catch (\Throwable) {
                // Logo failed — fall through with no image.
            }
        }

        // Organization name
        $sheet->setCellValue('B'.$contentStartRow, $org->name ?? '-');
        $sheet->getStyle('B'.$contentStartRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => $primaryHex]],
        ]);
        $sheet->mergeCells('B'.$contentStartRow.':C'.$contentStartRow);
        $sheet->getRowDimension($contentStartRow)->setRowHeight(30);

        // Document title
        $titleRow = $contentStartRow + 1;
        $sheet->setCellValue('B'.$titleRow, $title);
        $sheet->getStyle('B'.$titleRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '333333']],
        ]);
        $sheet->mergeCells('B'.$titleRow.':C'.$titleRow);
        $sheet->getRowDimension($titleRow)->setRowHeight(22);

        // Spacer + divider
        $dividerRow = $titleRow + 2;
        $sheet->getStyle('B'.$dividerRow.':C'.$dividerRow)->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => $primaryHex],
                ],
            ],
        ]);
        $sheet->getRowDimension($dividerRow)->setRowHeight(4);

        // Metadata block
        $metaStart = $dividerRow + 1;
        $metaItems = [
            ['Tanggal Export', $this->formatIndonesianDate(now()->toDateString())],
            ['Diekspor oleh', $this->formatUser($user)],
            ['Modul', $module],
            ['Jumlah Baris', number_format($rowCount, 0, ',', '.')],
        ];
        if (! empty($filters)) {
            $metaItems[] = ['Filter Diterapkan', $this->formatFilters($filters)];
        }

        $r = $metaStart;
        foreach ($metaItems as [$label, $value]) {
            $sheet->setCellValue('B'.$r, $label);
            $sheet->setCellValue('C'.$r, $value);
            $sheet->getStyle('B'.$r)->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '666666']],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
            $sheet->getStyle('C'.$r)->applyFromArray([
                'font' => ['size' => 10, 'color' => ['rgb' => '222222']],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(20);
            $r++;
        }

        // Classification badge
        $r += 1;
        $sheet->setCellValue('B'.$r, 'KLASIFIKASI: Confidential — Internal Use Only');
        $sheet->getStyle('B'.$r)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'B00020']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF4F4']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'F5C6CB']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->mergeCells('B'.$r.':C'.$r);
        $sheet->getRowDimension($r)->setRowHeight(24);

        // Footer (website / generator)
        $footerRow = $r + 2;
        $footerParts = array_filter([
            $org->website ?: null,
            'Privasimu Nexus',
        ]);
        $sheet->setCellValue('B'.$footerRow, implode('  ·  ', $footerParts));
        $sheet->getStyle('B'.$footerRow)->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '888888']],
        ]);
        $sheet->mergeCells('B'.$footerRow.':C'.$footerRow);
    }

    // =====================================================================
    // Data sheet
    // =====================================================================
    private function buildDataSheet(
        Spreadsheet $spreadsheet,
        array $rows,
        array $columnConfig,
        string $primaryHex,
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Data');

        if (empty($columnConfig)) {
            $sheet->setCellValue('A1', 'Tidak ada kolom yang dikonfigurasi.');

            return;
        }

        // Header row
        $col = 1;
        foreach ($columnConfig as $cfg) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}1", (string) ($cfg['label'] ?? $cfg['key']));
            $col++;
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($columnConfig));
        $headerRange = "A1:{$lastCol}1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $primaryHex]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GRAY],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->freezePane('A2');

        // Body rows
        $row = 2;
        foreach ($rows as $rowData) {
            $col = 1;
            foreach ($columnConfig as $cfg) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $key = $cfg['key'] ?? '';
                $value = is_array($rowData) ? ($rowData[$key] ?? null) : null;
                $format = $cfg['format'] ?? 'text';
                $cellRef = "{$letter}{$row}";

                $rendered = $this->formatValue($value, $format);
                $sheet->setCellValue($cellRef, $rendered);

                // Force text format on long text-like cells to prevent
                // Excel from coercing IDs like "0123" into numbers.
                if ($format === 'text' && is_string($rendered) && (ctype_digit(ltrim($rendered, '0')) || str_starts_with($rendered, '0'))) {
                    $sheet->getStyle($cellRef)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
                $col++;
            }
            $row++;
        }

        // Body styling: alternating fill + border + alignment
        $lastRow = $row - 1;
        if ($lastRow >= 2) {
            $bodyRange = "A2:{$lastCol}{$lastRow}";
            $sheet->getStyle($bodyRange)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => self::BORDER_GRAY],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_TOP,
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'wrapText' => true,
                ],
                'font' => ['size' => 10],
            ]);

            // Stripe even rows (sheet row 3, 5, 7, ... → 1st, 3rd, 5th data row)
            for ($r = 3; $r <= $lastRow; $r += 2) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::ROW_EVEN_BG],
                    ],
                ]);
            }
        }

        // Column widths: explicit if provided, else auto-size with cap.
        $col = 1;
        foreach ($columnConfig as $cfg) {
            $letter = Coordinate::stringFromColumnIndex($col);
            if (! empty($cfg['width']) && is_numeric($cfg['width'])) {
                $sheet->getColumnDimension($letter)->setWidth((float) $cfg['width']);
            } else {
                $sheet->getColumnDimension($letter)->setAutoSize(true);
            }
            $col++;
        }
    }

    // =====================================================================
    // Value formatting
    // =====================================================================
    private function formatValue($value, string $format): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        switch ($format) {
            case 'boolean':
                if (is_bool($value)) {
                    return $value ? 'Ya' : 'Tidak';
                }
                if (is_string($value)) {
                    $v = strtolower(trim($value));
                    if (in_array($v, ['ya', 'yes', '1', 'true', 'y'], true)) {
                        return 'Ya';
                    }
                    if (in_array($v, ['tidak', 'no', '0', 'false', 'n'], true)) {
                        return 'Tidak';
                    }

                    return (string) $value;
                }

                return $value ? 'Ya' : 'Tidak';

            case 'date':
                return $this->formatIndonesianDate((string) $value);

            case 'datetime':
                return $this->formatIndonesianDateTime((string) $value);

            case 'number':
                return is_numeric($value) ? (string) $value : (string) $value;

            case 'array':
                if (is_array($value)) {
                    return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value));
                }

                return (string) $value;

            case 'text':
            default:
                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                return (string) $value;
        }
    }

    private function formatIndonesianDate(?string $raw): string
    {
        if (! $raw) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return $raw;
        }
        $day = (int) $dt->format('j');
        $month = self::ID_MONTHS[(int) $dt->format('n')] ?? $dt->format('M');
        $year = $dt->format('Y');

        return "{$day} {$month} {$year}";
    }

    private function formatIndonesianDateTime(?string $raw): string
    {
        if (! $raw) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return $raw;
        }
        $datePart = $this->formatIndonesianDate($raw);

        return $datePart.' '.$dt->format('H:i');
    }

    private function formatUser(?User $user): string
    {
        if (! $user) {
            return '-';
        }
        $name = (string) ($user->name ?? '');
        $pos = (string) ($user->position ?? '');

        return $pos !== '' ? "{$name} ({$pos})" : ($name ?: '-');
    }

    private function formatFilters(array $filters): string
    {
        $parts = [];
        foreach ($filters as $label => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $parts[] = "{$label}: {$value}";
        }

        return $parts ? implode(' | ', $parts) : 'Tidak ada filter';
    }

    // =====================================================================
    // Brand resolution
    // =====================================================================
    private function resolvePalette(Organization $org): array
    {
        $theme = TenantTheme::query()
            ->where('org_id', $org->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
        if ($theme && is_array($theme->palette)) {
            return $theme->palette;
        }

        return [];
    }

    private function normalizeHex(?string $hex): ?string
    {
        if (! $hex) {
            return null;
        }
        $hex = ltrim(trim($hex), '#');
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return strtoupper($hex);
    }

    /**
     * Resolve org logo to a local filesystem path that PhpSpreadsheet's
     * Drawing helper can read. Returns null if the logo is remote / missing /
     * unreadable. Supports tenant theme logo first, then org->logo_url.
     */
    private function resolveLogoLocalPath(Organization $org): ?string
    {
        $candidates = [];

        $theme = TenantTheme::query()
            ->where('org_id', $org->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
        if ($theme && $theme->logo_url) {
            $candidates[] = $theme->logo_url;
        }
        if ($org->logo_url) {
            $candidates[] = $org->logo_url;
        }

        foreach ($candidates as $url) {
            $path = $this->logoUrlToLocalPath($url);
            if ($path && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function logoUrlToLocalPath(string $url): ?string
    {
        // data: URI → write to temp and return that path
        if (str_starts_with($url, 'data:')) {
            if (! preg_match('#^data:(image/[^;]+);base64,(.+)$#', $url, $m)) {
                return null;
            }
            $ext = match ($m[1]) {
                'image/png' => 'png',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/gif' => 'gif',
                default => 'png',
            };
            $bytes = base64_decode($m[2], true);
            if ($bytes === false) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'logo_').'.'.$ext;
            file_put_contents($tmp, $bytes);

            return $tmp;
        }

        // Remote URL — fetch best-effort
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            try {
                $bytes = @file_get_contents($url);
                if ($bytes === false) {
                    return null;
                }
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) ?: 'png';
                $tmp = tempnam(sys_get_temp_dir(), 'logo_').'.'.$ext;
                file_put_contents($tmp, $bytes);

                return $tmp;
            } catch (\Throwable) {
                return null;
            }
        }

        // public/ relative
        $relative = ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, 8);
        }
        if (Storage::disk('public')->exists($relative)) {
            return Storage::disk('public')->path($relative);
        }

        // Absolute filesystem path?
        if (is_readable($url)) {
            return $url;
        }

        return null;
    }
}
