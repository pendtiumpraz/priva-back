{{--
    Shared PDF layout for DSR certificates. Mirrors reports/breach/_layout
    pattern — pulls styling from tenant DocumentTemplate.
--}}
@php
    $config = $config ?? (\App\Models\DocumentTemplate::activeForOrg($org?->id ?? null)?->mergedConfig() ?? \App\Models\DocumentTemplate::DEFAULT_CONFIG);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Sertifikat DSR')</title>
    @include('reports._doc_styles', ['config' => $config])
    <style>
        .cert-frame { border: 2px solid {{ $config['accent_color'] ?? '#0ea5e9' }}; padding: 28px; margin-top: 12px; }
        .cert-title { text-align: center; font-size: 18pt; font-weight: 800; color: {{ $config['primary_color'] ?? '#0f172a' }}; margin: 0 0 6px; letter-spacing: 1px; }
        .cert-sub   { text-align: center; font-size: 10pt; color: #64748b; margin: 0 0 18px; }
        .field-row  { margin: 4px 0; }
        .field-label { display: inline-block; min-width: 160px; color: #475569; font-weight: 600; }
        .scope-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .scope-table th, .scope-table td { border: 1px solid #e2e8f0; padding: 6px 8px; font-size: 9pt; text-align: left; }
        .scope-table th { background: #f1f5f9; font-weight: 700; }
        .signoff { margin-top: 28px; }
        .sig-line { border-bottom: 1px solid #475569; width: 220px; margin: 36px 0 4px; }
        .stamp-id { font-family: monospace; font-size: 8pt; color: #94a3b8; margin-top: 6px; }
        .check { color: #16a34a; font-weight: 800; }
    </style>
</head>
<body>
    @if(($config['header_enabled'] ?? true))
    <div class="header">
        <div class="header-left">
            @if(($config['header_show_logo'] ?? true) && ($orgLogoUrl ?? false))
                <img src="{{ $orgLogoUrl }}" alt="Logo">
            @else
                <span style="font-size: 13pt; font-weight: 800; color: {{ $config['primary_color'] }};">{{ $orgName }}</span>
            @endif
        </div>
        <div class="header-right">
            @yield('doc-id', '')
        </div>
    </div>
    @endif

    @if(($config['footer_enabled'] ?? true))
    <div class="footer">
        <div class="footer-left">
            {{ $orgName }}
            @if(($config['footer_show_website'] ?? true) && ($orgWebsite ?? false)) · {{ $orgWebsite }} @endif
        </div>
        <div class="footer-right">
            Issued via Privasimu Nexus · {{ now()->format('d M Y H:i') }} WIB
        </div>
    </div>
    @endif

    <div class="content-wrap">
        @yield('content')
    </div>
</body>
</html>
