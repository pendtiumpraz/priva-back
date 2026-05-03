<!DOCTYPE html>
<html lang="{{ $metadata['language'] ?? 'id' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 70px 60px 70px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111827; line-height: 1.55; }
        .doc-title { text-align: center; font-size: 18pt; font-weight: 800; margin: 0 0 6px; color: #111827; }
        .doc-meta { text-align: center; color: #6b7280; font-size: 9pt; font-style: italic; margin-bottom: 28px; }
        h1 { font-size: 14pt; margin: 18px 0 8px; color: #111827; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
        h2 { font-size: 12pt; margin: 14px 0 6px; color: #1f2937; }
        h3 { font-size: 11pt; margin: 10px 0 4px; color: #374151; }
        p { text-align: justify; margin: 0 0 10px; }
        ul { margin: 0 0 10px 18px; padding: 0; }
        ul li { margin: 0 0 4px; }
        table.doc-table { border-collapse: collapse; width: 100%; margin: 8px 0 14px; font-size: 10pt; }
        table.doc-table th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        table.doc-table td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        .signature-block { margin-top: 40px; width: 100%; border-collapse: collapse; }
        .signature-block td { width: 50%; padding: 0 14px; vertical-align: top; }
        .signature-line { display: block; margin: 60px 0 4px; border-bottom: 1px solid #111827; }
        .sig-label { font-weight: 700; font-size: 10pt; }
        .sig-name { font-weight: 700; font-size: 11pt; }
        .sig-title { font-style: italic; color: #6b7280; font-size: 10pt; }
    </style>
</head>
<body>
    <div class="doc-title">{{ $title }}</div>
    @php
        $metaParts = [];
        if (!empty($metadata['version'])) $metaParts[] = 'Versi '.$metadata['version'];
        if (!empty($metadata['language'])) $metaParts[] = 'Bahasa: '.strtoupper($metadata['language']);
        $metaParts[] = 'Dibuat: '.now()->format('d M Y');
    @endphp
    <div class="doc-meta">{{ implode('  ·  ', $metaParts) }}</div>

    @foreach ($sections as $node)
        @php
            $type = $node['type'] ?? null;
            $text = $node['text'] ?? '';
        @endphp
        @switch($type)
            @case('heading_1')
                <h1>{{ $text }}</h1>
                @break
            @case('heading_2')
                <h2>{{ $text }}</h2>
                @break
            @case('heading_3')
                <h3>{{ $text }}</h3>
                @break
            @case('paragraph')
                <p>{{ $text }}</p>
                @break
            @case('list')
                @php $items = is_array($node['items'] ?? null) ? $node['items'] : []; @endphp
                @if (count($items))
                    <ul>
                        @foreach ($items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif
                @break
            @case('table')
                @php
                    $headers = is_array($node['headers'] ?? null) ? $node['headers'] : [];
                    $rows = is_array($node['rows'] ?? null) ? $node['rows'] : [];
                @endphp
                <table class="doc-table">
                    @if (count($headers))
                        <thead><tr>
                            @foreach ($headers as $h)
                                <th>{{ $h }}</th>
                            @endforeach
                        </tr></thead>
                    @endif
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @php $cells = is_array($row) ? $row : []; @endphp
                                @foreach ($cells as $c)
                                    <td>{{ $c }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @break
            @case('signature_block')
                @php $parties = is_array($node['parties'] ?? null) ? $node['parties'] : []; @endphp
                @if (count($parties))
                    <table class="signature-block">
                        <tr>
                            @foreach ($parties as $p)
                                <td>
                                    <div class="sig-label">{{ $p['label'] ?? '' }}</div>
                                    <span class="signature-line"></span>
                                    <div class="sig-name">{{ $p['name'] ?? '' }}</div>
                                    <div class="sig-title">{{ $p['title'] ?? '' }}</div>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                @endif
                @break
            @default
                @if (!empty($text))
                    <p>{{ $text }}</p>
                @endif
        @endswitch
    @endforeach
</body>
</html>
