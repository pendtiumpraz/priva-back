@php
    $primary = $config['primary_color'] ?? ($config['accent_color'] ?? '#16284c');
    $font = $config['font_family'] ?? 'DejaVu Sans';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "{{ $font }}", sans-serif; box-sizing: border-box; }
        @page { margin: 28px 34px; }
        body { color: #1f2937; font-size: 11px; margin: 0; }
        .hdr { border-bottom: 3px solid {{ $primary }}; padding-bottom: 10px; margin-bottom: 16px; }
        .hdr table { width: 100%; border-collapse: collapse; }
        .hdr .logo { width: 90px; vertical-align: middle; }
        .hdr .logo img { max-height: 46px; max-width: 90px; }
        .hdr .org { font-size: 16px; font-weight: bold; color: {{ $primary }}; }
        .hdr .kind { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
        .doc-title { font-size: 14px; font-weight: bold; color: #111827; margin: 2px 0 2px; }
        .meta { font-size: 9px; color: #6b7280; }
        .sec { margin-top: 14px; page-break-inside: avoid; }
        .sec-title { background: {{ $primary }}; color: #fff; font-size: 11px; font-weight: bold; padding: 6px 10px; border-radius: 3px; }
        table.rows { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.rows td { border: 1px solid #d0d7e2; padding: 5px 8px; vertical-align: top; font-size: 10.5px; }
        table.rows td.k { width: 32%; font-weight: bold; color: #374151; background: #f4f6fa; }
        table.rows td.v { width: 68%; white-space: pre-line; }
        .badge { display: inline-block; background: #fff4f4; color: #b00020; border: 1px solid #f5c6cb; border-radius: 3px; padding: 2px 8px; font-size: 9px; font-weight: bold; }
        .foot { margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 6px; font-size: 8.5px; color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <div class="hdr">
        <table>
            <tr>
                @if (!empty($orgLogoUrl))
                    <td class="logo"><img src="{{ $orgLogoUrl }}" alt="logo"></td>
                @endif
                <td>
                    <div class="org">{{ $orgName }}</div>
                    <div class="kind">{{ $kindLabel }}</div>
                    <div class="doc-title">{{ $title }}</div>
                    <div class="meta">Dibuat: {{ $generatedAt }} · oleh {{ $generatedBy }}</div>
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <span class="badge">CONFIDENTIAL</span>
                </td>
            </tr>
        </table>
    </div>

    @foreach ($sections as $section)
        <div class="sec">
            <div class="sec-title">{{ $section['title'] ?? '' }}</div>
            <table class="rows">
                @foreach (($section['rows'] ?? []) as $row)
                    <tr>
                        <td class="k">{{ $row['label'] ?? '' }}</td>
                        <td class="v">{{ ($row['value'] ?? '') !== '' ? $row['value'] : '-' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach

    <div class="foot">
        {{ $orgName }}@if(!empty($orgWebsite)) · {{ $orgWebsite }}@endif · Dokumen dihasilkan oleh Privasimu Nexus. Klasifikasi: Confidential — Internal Use Only.
    </div>
</body>
</html>
