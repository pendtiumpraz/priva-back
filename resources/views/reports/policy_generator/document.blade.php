@php
    $cfg = $config ?? \App\Models\DocumentTemplate::DEFAULT_CONFIG;
    $primary = $cfg['primary_color'] ?? '#1e293b';
    $accent = $cfg['accent_color'] ?? '#3b82f6';
    $font = $cfg['font_family'] ?? 'DejaVu Sans';
    $bodySize = $cfg['font_size_body'] ?? 11;
@endphp
<!DOCTYPE html>
<html lang="{{ $metadata['language'] ?? 'id' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 120px 60px 90px 60px; }
        body { font-family: {{ $font }}, sans-serif; font-size: {{ $bodySize }}pt; color: #111827; line-height: 1.55; }
        .pg-header { position: fixed; top: -90px; left: 0; right: 0; height: 70px; @if(!empty($cfg['header_border_bottom'])) border-bottom: 2px solid {{ $accent }}; @endif }
        .pg-header img { max-height: 46px; max-width: 200px; }
        .pg-header .org { font-size: 12pt; font-weight: 700; color: {{ $primary }}; }
        .pg-header .htext { font-size: 8pt; color: #6b7280; }
        .pg-footer { position: fixed; bottom: -60px; left: 0; right: 0; height: 40px; font-size: 8pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
        .pg-footer .right { text-align: right; }
        .doc-title { text-align: center; font-size: 18pt; font-weight: 800; margin: 0 0 6px; color: {{ $primary }}; }
        .doc-meta { text-align: center; color: #6b7280; font-size: 9pt; font-style: italic; margin-bottom: 28px; }
        h1 { font-size: 14pt; margin: 18px 0 8px; color: {{ $primary }}; border-bottom: 1px solid {{ $accent }}; padding-bottom: 3px; }
        h2 { font-size: 12pt; margin: 14px 0 6px; color: #1f2937; }
        h3 { font-size: 11pt; margin: 10px 0 4px; color: #374151; }
        p { text-align: justify; margin: 0 0 10px; }
        p.pg-disclaimer { margin-top: 18px; padding: 8px 10px; background: #fef3c7; border-left: 3px solid #d97706; font-size: 9pt; color: #92400e; }
        ul { margin: 0 0 10px 18px; padding: 0; } ul li { margin: 0 0 4px; }
        table.doc-table { border-collapse: collapse; width: 100%; margin: 8px 0 14px; font-size: 10pt; }
        table.doc-table th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        table.doc-table td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        @if(!empty($cfg['watermark_enabled']) && !empty($cfg['watermark_text']))
        .pg-watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; transform: rotate({{ $cfg['watermark_rotate'] ?? -25 }}deg); font-size: 60pt; color: rgba(15,23,42,{{ $cfg['watermark_opacity'] ?? 0.08 }}); z-index: -1; }
        @endif
    </style>
</head>
<body>
    @if(($cfg['header_enabled'] ?? true))
    <div class="pg-header">
        @if(!empty($cfg['header_show_logo']) && !empty($orgLogoUrl))
            <img src="{{ $orgLogoUrl }}" alt="logo">
        @endif
        @if(($cfg['header_show_org_name'] ?? true) && !empty($orgName))
            <span class="org">{{ $orgName }}</span>
        @endif
        @if(!empty($cfg['header_text']))
            <div class="htext">{{ $cfg['header_text'] }}</div>
        @endif
    </div>
    @endif

    @if(($cfg['footer_enabled'] ?? true))
    <div class="pg-footer">
        <span>{{ $cfg['footer_text'] ?? $orgName }}</span>
        <span class="right">
            @if(($cfg['footer_show_website'] ?? true) && !empty($orgWebsite)) {{ $orgWebsite }} @endif
        </span>
    </div>
    @endif

    @if(!empty($cfg['watermark_enabled']) && !empty($cfg['watermark_text']))
        <div class="pg-watermark">{{ $cfg['watermark_text'] }}</div>
    @endif

    <div class="doc-title">{{ $title }}</div>
    @php
        $metaParts = [];
        if (!empty($metadata['version'])) $metaParts[] = 'Versi '.$metadata['version'];
        if (!empty($metadata['language'])) $metaParts[] = 'Bahasa: '.strtoupper($metadata['language']);
        $metaParts[] = 'Dibuat: '.now()->format('d M Y');
    @endphp
    <div class="doc-meta">{{ implode('  ·  ', $metaParts) }}</div>

    @include('reports.policy_generator._sections', ['sections' => $sections])
</body>
</html>
