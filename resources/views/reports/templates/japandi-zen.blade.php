<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #2a2620; }

        /* ---------- COVER (Japandi Zen) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #ebe5d6;
            color: #2a2620;
            page-break-after: always;
            padding: 26mm 26mm;
        }

        /* Enso circle: dompdf transparent-border + rotate unreliable -> inline SVG arc */
        .enso {
            position: absolute;
            top: 60mm; right: 22mm;
            width: 76mm; height: 76mm;
        }

        .ja-eyebrow {
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #7a6f5e;
        }
        .ja-eyebrow .latin {
            margin-top: 1.5mm;
        }

        .cover-title-block {
            position: absolute;
            top: 130mm; left: 26mm; right: 26mm;
        }
        .cover-title-block h1 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 48pt;
            font-weight: 300;
            line-height: 1.05;
            letter-spacing: -1pt;
            margin: 0;
        }
        .cover-title-block h1 em { font-style: italic; }
        .short-rule {
            margin-top: 10mm;
            width: 14mm; height: 1px;
            background: #2a2620;
        }
        .cover-quote {
            margin-top: 7mm;
            max-width: 130mm;
        }
        .cover-quote .name-it {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 14pt;
            color: #5a4f3e;
            line-height: 1.45;
        }
        .cover-quote .org {
            margin-top: 5mm;
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #7a6f5e;
        }

        .cover-meta {
            position: absolute;
            bottom: 26mm; left: 26mm; right: 26mm;
            border-top: 1px solid #c4bba6;
            padding-top: 10mm;
        }
        .cover-meta table { width: 100%; border-collapse: separate; border-spacing: 10mm 0; }
        .cover-meta td {
            width: 33.33%;
            vertical-align: top;
        }
        .cm-k {
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #7a6f5e;
        }
        .cm-v {
            margin-top: 2mm;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 14pt;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #ebe5d6;
            color: #2a2620;
        }
        .ct-head {
            padding: 20mm 24mm 6mm;
        }
        .ct-head .eyebrow {
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #7a6f5e;
        }
        .ct-head h2 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 30pt;
            font-weight: 300;
            margin: 4mm 0 0;
            letter-spacing: -0.6pt;
        }
        .ct-head h2 em { font-style: italic; }
        .ct-head .rule {
            margin-top: 5mm;
            width: 11mm; height: 1px;
            background: #2a2620;
        }

        .field-list {
            padding: 0 24mm;
        }
        .field-list table { width: 100%; border-collapse: collapse; }
        .field-list tr td {
            padding: 4.5mm 0;
            border-bottom: 1px solid #d5cdb6;
            vertical-align: top;
        }
        .fl-k {
            width: 48mm;
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #7a6f5e;
            padding-top: 1.2mm !important;
        }
        .fl-v {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 13pt;
        }

        .desc-row {
            padding: 7mm 24mm 0;
        }
        .desc-row .inner {
            padding: 6mm 0;
            border-bottom: 1px solid #d5cdb6;
        }
        .desc-row .k {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #7a6f5e;
            margin-bottom: 3mm;
        }
        .desc-row .v {
            font-size: 11pt;
            line-height: 1.7;
        }

        .ct-section2 {
            padding: 10mm 24mm 0;
        }
        .ct-section2 .eyebrow {
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #7a6f5e;
        }
        .ct-section2 h3 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 22pt;
            font-weight: 300;
            margin: 3mm 0 6mm;
            letter-spacing: -0.4pt;
        }
        .ct-section2 h3 em { font-style: italic; }

        .info-row {
            margin-top: 4mm;
            font-size: 11pt;
            line-height: 1.7;
        }
        .info-row .lead {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 12pt;
            color: #7a6f5e;
        }

        .cat-row {
            margin-top: 6mm;
        }
        .cat-row .lead {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 12pt;
            color: #7a6f5e;
            margin-bottom: 2mm;
        }
        .cat-row .v {
            font-size: 11pt;
            line-height: 1.7;
        }

        .ct-foot {
            position: absolute;
            bottom: 14mm; left: 24mm; right: 24mm;
            font-size: 9pt;
            letter-spacing: 2.4pt;
            color: #7a6f5e;
        }
        .ct-foot table { width: 100%; border-collapse: collapse; }
        .ct-foot .center { text-align: center; }
        .ct-foot .right { text-align: right; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        {{-- Enso: borderTopColor:transparent + rotate unreliable in dompdf -> SVG arc --}}
        <svg class="enso" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <circle cx="50" cy="50" r="44" stroke="#2a2620" stroke-width="1.5" fill="none"
                stroke-dasharray="250 80" transform="rotate(15 50 50)"/>
            <circle cx="50" cy="50" r="40" stroke="#2a2620" stroke-width="0.6" fill="none" opacity="0.15"/>
        </svg>

        <div class="ja-eyebrow">
            記 録
            <div class="latin">Record</div>
        </div>

        <div class="cover-title-block">
            <h1>
                Catatan<br>
                <em>Pemrosesan</em><br>
                Data Pribadi
            </h1>
            <div class="short-rule"></div>
            <div class="cover-quote">
                <div class="name-it">&ldquo;{{ $ropa['name'] ?? '-' }}&rdquo;</div>
                <div class="org">{{ $ropa['org'] ?? ($orgName ?? '') }}</div>
            </div>
        </div>

        <div class="cover-meta">
            <table><tr>
                <td>
                    <div class="cm-k">Nomor</div>
                    <span class="cm-v">{{ $ropa['number'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="cm-k">Divisi</div>
                    <span class="cm-v">{{ $ropa['division'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="cm-k">Tanggal</div>
                    <span class="cm-v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                </td>
            </tr></table>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <div class="eyebrow">一 &middot; Bagian Pertama</div>
            <h2>Deskripsi <em>Pemrosesan</em></h2>
            <div class="rule"></div>
        </div>

        <div class="field-list">
            <table>
                <tr><td class="fl-k">Nomor</td><td class="fl-v">{{ $ropa['number'] ?? '-' }}</td></tr>
                <tr><td class="fl-k">Nama</td><td class="fl-v">{{ $ropa['name'] ?? '-' }}</td></tr>
                <tr><td class="fl-k">Divisi</td><td class="fl-v">{{ $ropa['division'] ?? '-' }}</td></tr>
                <tr><td class="fl-k">Unit Kerja</td><td class="fl-v">{{ $ropa['unit'] ?? '-' }}</td></tr>
                <tr><td class="fl-k">Entitas</td><td class="fl-v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr>
            </table>
        </div>

        <div class="desc-row">
            <div class="inner">
                <div class="k">Deskripsi</div>
                <div class="v">{{ $ropa['description'] ?? '-' }}</div>
            </div>
        </div>

        <div class="ct-section2">
            <div class="eyebrow">二 &middot; Bagian Kedua</div>
            <h3>Informasi <em>Pemrosesan</em></h3>

            <div class="info-row">
                <span class="lead">Tujuan &mdash; </span>{{ $ropa['purpose'] ?? '-' }}
            </div>
            <div class="info-row">
                <span class="lead">Dasar Hukum &mdash; </span>{{ $ropa['legal_basis'] ?? '-' }}
            </div>

            <div class="cat-row">
                <div class="lead">Kategori &mdash;</div>
                <div class="v">
                    @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                        {{ implode(' &middot; ', $ropa['categories']) }}
                    @else
                        -
                    @endif
                </div>
            </div>
        </div>

        <div class="ct-foot">
            <table><tr>
                <td>&mdash;</td>
                <td class="center">二</td>
                <td class="right">&mdash;</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
