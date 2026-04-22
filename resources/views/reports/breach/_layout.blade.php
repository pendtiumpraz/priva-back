{{--
    Shared PDF layout for breach documents. Pulls styling from the
    tenant's active DocumentTemplate (Phase D).
--}}
@php
    $config = $config ?? (\App\Models\DocumentTemplate::activeForOrg($org?->id ?? null)?->mergedConfig() ?? \App\Models\DocumentTemplate::DEFAULT_CONFIG);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Dokumen Breach')</title>
    @include('reports._doc_styles', ['config' => $config])
</head>
<body>

    @if(($config['watermark_enabled'] ?? false) && ($config['watermark_text'] ?? $watermark ?? null))
        <div class="watermark">{{ $config['watermark_text'] ?? $watermark }}</div>
    @endif

    @if($config['header_enabled'] ?? true)
    <div class="header">
        <div class="header-left">
            @if(($config['header_show_logo'] ?? true) && ($orgLogoUrl ?? false))
                <img src="{{ $orgLogoUrl }}" alt="Logo">
            @elseif($config['header_show_org_name'] ?? true)
                <span style="font-size: 13pt; font-weight: 800; color: {{ $config['primary_color'] }};">{{ $orgName }}</span>
            @endif
        </div>
        <div class="header-right">
            @yield('doc-id', '')
            @if(!empty($config['header_text'])) <br>{!! $config['header_text'] !!} @endif
        </div>
    </div>
    @endif

    @if($config['footer_enabled'] ?? true)
    <div class="footer">
        <div class="footer-left">
            {{ $orgName }}
            @if(($config['footer_show_website'] ?? true) && ($orgWebsite ?? false)) · {{ $orgWebsite }} @endif
            @if(!empty($config['footer_text'])) · {!! $config['footer_text'] !!} @endif
        </div>
        <div class="footer-right">
            @if($config['footer_show_page_num'] ?? true)
            <script type="text/php">
                if (isset($pdf)) {
                    $pdf->page_script('$font = $fontMetrics->getFont("DejaVu Sans", "normal"); $pdf->text(480, 820, "Hal " . $PAGE_NUM . " dari " . $PAGE_COUNT, $font, 8, [150,150,150]);');
                }
            </script>
            @endif
        </div>
    </div>
    @endif

    @yield('content')

</body>
</html>
