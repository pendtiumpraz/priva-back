{{--
    Shared PDF layout for breach documents.
    @yield('content') for body. Header/footer/watermark pulled from
    tenant settings. Phase D will extend with richer template presets
    (rounded tables, cover page, signature block).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Dokumen Breach')</title>
    <style>
        @page { margin: 100px 50px 80px 50px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; line-height: 1.55; color: #111; }
        h1 { font-size: 16pt; margin: 0 0 4px; }
        h2 { font-size: 13pt; margin: 20px 0 6px; color: #1e293b; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        h3 { font-size: 11pt; margin: 14px 0 4px; color: #334155; }
        .muted { color: #64748b; font-size: 9pt; }
        .header {
            position: fixed; top: -70px; left: 0; right: 0;
            padding: 10px 0; border-bottom: 1px solid #e2e8f0;
            display: table; width: 100%;
        }
        .header-left { display: table-cell; vertical-align: middle; }
        .header-right { display: table-cell; vertical-align: middle; text-align: right; font-size: 9pt; color: #64748b; }
        .header img { max-height: 42px; }
        .footer {
            position: fixed; bottom: -50px; left: 0; right: 0;
            padding-top: 8px; border-top: 1px solid #e2e8f0;
            font-size: 8pt; color: #94a3b8;
            display: table; width: 100%;
        }
        .footer-left { display: table-cell; }
        .footer-right { display: table-cell; text-align: right; }
        .watermark {
            position: fixed; top: 40%; left: 0; right: 0;
            text-align: center;
            font-size: 90pt; color: rgba(100, 116, 139, 0.08);
            font-weight: 900; letter-spacing: 8px;
            transform: rotate(-25deg);
            z-index: -1;
        }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.meta td { padding: 4px 8px; vertical-align: top; }
        table.meta td:first-child { width: 40%; font-weight: 700; color: #475569; }
        table.grid th, table.grid td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; font-size: 10pt; }
        table.grid th { background: #f1f5f9; font-weight: 700; }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 999px;
            font-size: 9pt; font-weight: 700; letter-spacing: 0.3px;
        }
        .b-red { background: #fee2e2; color: #991b1b; }
        .b-amber { background: #fef3c7; color: #92400e; }
        .b-blue { background: #dbeafe; color: #1e40af; }
        .b-green { background: #dcfce7; color: #166534; }
        .sig-block { margin-top: 50px; width: 45%; }
        .sig-line { border-bottom: 1px solid #111; height: 60px; }
        .callout {
            padding: 10px 14px; border-left: 4px solid #3b82f6;
            background: #eff6ff; margin: 12px 0; font-size: 10pt;
        }
        .callout.warn { border-color: #f59e0b; background: #fffbeb; }
        .callout.info { border-color: #0ea5e9; background: #f0f9ff; }
        ul, ol { padding-left: 20px; }
        li { margin: 3px 0; }
    </style>
</head>
<body>

    @if($watermark ?? false)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <div class="header">
        <div class="header-left">
            @if($orgLogoUrl ?? false)
                <img src="{{ $orgLogoUrl }}" alt="Logo">
            @else
                <span style="font-size: 13pt; font-weight: 800; color: #1e293b;">{{ $orgName }}</span>
            @endif
        </div>
        <div class="header-right">
            @yield('doc-id', '')
            @if(!empty($documentHeader)) <br>{!! $documentHeader !!} @endif
        </div>
    </div>

    <div class="footer">
        <div class="footer-left">
            {{ $orgName }}
            @if($orgWebsite ?? false) · {{ $orgWebsite }} @endif
            @if(!empty($documentFooter)) · {!! $documentFooter !!} @endif
        </div>
        <div class="footer-right">
            Hal <script type="text/php">
                if (isset($pdf)) {
                    $pdf->page_script('$font = $fontMetrics->getFont("DejaVu Sans", "normal"); $pdf->text(265, 820, "Hal " . $PAGE_NUM . " dari " . $PAGE_COUNT, $font, 8);');
                }
            </script>
        </div>
    </div>

    @yield('content')

</body>
</html>
