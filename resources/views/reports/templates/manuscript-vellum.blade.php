<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Serif', 'Times New Roman', serif; color: #3a2a14; }

        /* ---------- COVER (Manuscript Vellum) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            /* dompdf 2.0+ supports basic linear-gradient; fallback solid #f1e5c5 */
            background: #f1e5c5;
            background: linear-gradient(135deg, #f1e5c5 0%, #e8d8a9 100%);
            color: #3a2a14;
            page-break-after: always;
        }
        .corner-tl { position: absolute; top: 8mm; left: 8mm; }
        .corner-tr { position: absolute; top: 8mm; right: 8mm; }
        .corner-bl { position: absolute; bottom: 8mm; left: 8mm; }
        .corner-br { position: absolute; bottom: 8mm; right: 8mm; }

        .horiz-rules {
            position: absolute;
            top: 24mm; left: 24mm; right: 24mm; bottom: 24mm;
            border-top: 0.3mm solid #8b6a2a;
            border-bottom: 0.3mm solid #8b6a2a;
        }

        .cover-inner {
            position: absolute;
            top: 38mm; left: 28mm; right: 28mm; bottom: 38mm;
            text-align: center;
        }
        .anno-label {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 4.5pt;
            text-transform: uppercase;
            color: #8b6a2a;
            font-weight: 600;
        }

        /* illuminated initial — big red square with R + inner border */
        .initial-row {
            margin-top: 14mm;
            display: block;
            text-align: center;
        }
        .initial-row table {
            margin: 0 auto;
            border-collapse: collapse;
        }
        .initial-row td { vertical-align: top; padding: 0; }
        .initial-box {
            position: relative;
            width: 30mm; height: 30mm;
            background: #a8201a;
            color: #f1e5c5;
        }
        .initial-letter {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            line-height: 30mm;
            font-size: 64pt;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-weight: 500;
            text-align: center;
        }
        .initial-inner-border {
            position: absolute;
            top: 1.5mm; right: 1.5mm; bottom: 1.5mm; left: 1.5mm;
            border: 0.35mm solid #f1e5c5;
        }
        .initial-words {
            padding-left: 3mm;
            padding-top: 4mm;
            text-align: left;
        }
        .iw-1 {
            font-size: 38pt;
            font-weight: 400;
            line-height: 1;
            letter-spacing: -1pt;
        }
        .iw-2 {
            font-size: 38pt;
            font-weight: 400;
            line-height: 1;
            letter-spacing: -1pt;
            font-style: italic;
            color: #a8201a;
            margin-top: 1mm;
        }

        .latin-prose {
            margin-top: 14mm;
            font-size: 13pt;
            font-style: italic;
            line-height: 1.6;
            max-width: 130mm;
            margin-left: auto;
            margin-right: auto;
        }
        .name-quote {
            margin-top: 9mm;
            font-size: 17pt;
            font-style: italic;
            color: #a8201a;
        }
        .ref-band {
            margin: 16mm auto 0;
            display: inline-block;
            padding: 3mm 9mm;
            border-top: 0.3mm solid #8b6a2a;
            border-bottom: 0.3mm solid #8b6a2a;
        }
        .ref-band table { border-collapse: collapse; }
        .ref-band td { padding: 0 8mm; vertical-align: top; }
        .ref-k {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #8b6a2a;
            font-weight: 600;
        }
        .ref-v {
            margin-top: 1mm;
            font-size: 13pt;
            font-style: italic;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f1e5c5;
            color: #3a2a14;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
        }
        .ct-head {
            padding: 14mm 24mm 5mm;
            text-align: center;
            border-bottom: 0.6mm solid #8b6a2a;
        }
        .folio-label {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 4.5pt;
            text-transform: uppercase;
            color: #8b6a2a;
            font-weight: 600;
        }
        .ct-h2 {
            font-size: 34pt;
            font-weight: 400;
            font-style: italic;
            margin: 3mm 0 0;
            letter-spacing: -0.5pt;
        }
        .ct-h2 .accent { color: #a8201a; }

        .prose-block {
            padding: 7mm 24mm;
            font-size: 12pt;
            line-height: 1.7;
        }
        .prose-block p { margin: 0 0 3mm; }
        .dropcap {
            float: left;
            font-size: 50pt;
            line-height: 0.85;
            margin-right: 3mm;
            color: #a8201a;
            font-weight: 500;
        }

        .key-list {
            margin: 3mm 24mm 0;
            border-top: 0.6mm solid #8b6a2a;
            border-bottom: 0.6mm solid #8b6a2a;
            padding: 4mm 0;
        }
        .key-list table { width: 100%; border-collapse: collapse; }
        .key-list tr td {
            padding: 2mm 0;
            border-bottom: 0.25mm dashed #c4a96a;
            vertical-align: top;
        }
        .key-list tr.last td { border-bottom: none; }
        .k-key {
            width: 55mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 2.7pt;
            text-transform: uppercase;
            color: #8b6a2a;
            font-weight: 600;
            padding-top: 1mm !important;
        }
        .k-val {
            font-size: 12pt;
            font-style: italic;
        }

        .cat-block {
            padding: 6mm 24mm 0;
        }
        .cat-k {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 2.7pt;
            text-transform: uppercase;
            color: #8b6a2a;
            font-weight: 600;
        }
        .cat-v {
            margin-top: 2.5mm;
            font-size: 12pt;
            font-style: italic;
            line-height: 1.6;
        }

        .ct-foot {
            position: absolute;
            bottom: 11mm; left: 0; right: 0;
            text-align: center;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 4pt;
            color: #8b6a2a;
        }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        {{-- decorative corner SVG; mirrored via transform --}}
        <svg class="corner-tl" width="64" height="64" viewBox="0 0 64 64">
            <path d="M0 64 L0 0 L64 0" stroke="#8b6a2a" stroke-width="1.5" fill="none"/>
            <path d="M0 32 Q16 16 32 0" stroke="#8b6a2a" stroke-width="1" fill="none"/>
            <circle cx="0" cy="0" r="4" fill="#8b6a2a"/>
            <path d="M8 8 Q16 4 24 8 Q20 14 8 8" fill="#a8201a"/>
        </svg>
        <svg class="corner-tr" width="64" height="64" viewBox="0 0 64 64" style="transform: scaleX(-1);">
            <path d="M0 64 L0 0 L64 0" stroke="#8b6a2a" stroke-width="1.5" fill="none"/>
            <path d="M0 32 Q16 16 32 0" stroke="#8b6a2a" stroke-width="1" fill="none"/>
            <circle cx="0" cy="0" r="4" fill="#8b6a2a"/>
            <path d="M8 8 Q16 4 24 8 Q20 14 8 8" fill="#a8201a"/>
        </svg>
        <svg class="corner-bl" width="64" height="64" viewBox="0 0 64 64" style="transform: scaleY(-1);">
            <path d="M0 64 L0 0 L64 0" stroke="#8b6a2a" stroke-width="1.5" fill="none"/>
            <path d="M0 32 Q16 16 32 0" stroke="#8b6a2a" stroke-width="1" fill="none"/>
            <circle cx="0" cy="0" r="4" fill="#8b6a2a"/>
            <path d="M8 8 Q16 4 24 8 Q20 14 8 8" fill="#a8201a"/>
        </svg>
        <svg class="corner-br" width="64" height="64" viewBox="0 0 64 64" style="transform: scale(-1,-1);">
            <path d="M0 64 L0 0 L64 0" stroke="#8b6a2a" stroke-width="1.5" fill="none"/>
            <path d="M0 32 Q16 16 32 0" stroke="#8b6a2a" stroke-width="1" fill="none"/>
            <circle cx="0" cy="0" r="4" fill="#8b6a2a"/>
            <path d="M8 8 Q16 4 24 8 Q20 14 8 8" fill="#a8201a"/>
        </svg>

        <div class="horiz-rules"></div>

        <div class="cover-inner">
            <div class="anno-label">~ Anno MMXXVI ~</div>

            <div class="initial-row">
                <table><tr>
                    <td>
                        <div class="initial-box">
                            <div class="initial-letter">R</div>
                            <div class="initial-inner-border"></div>
                        </div>
                    </td>
                    <td>
                        <div class="initial-words">
                            <div class="iw-1">ecord of</div>
                            <div class="iw-2">Processing</div>
                        </div>
                    </td>
                </tr></table>
            </div>

            <div class="latin-prose">
                Hereby is set down a true and faithful record of the processing of personal data, made this day for <em>{{ $ropa['org'] ?? ($orgName ?? '-') }}</em>.
            </div>
            <div class="name-quote">&ldquo;{{ $ropa['name'] ?? '' }}&rdquo;</div>

            <div class="ref-band">
                <table><tr>
                    <td>
                        <div class="ref-k">Ref.</div>
                        <span class="ref-v">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="ref-k">Diem</div>
                        <span class="ref-v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <div class="folio-label">~ Folio II ~</div>
            <h2 class="ct-h2">Of the <span class="accent">Processing</span></h2>
        </div>

        <div class="prose-block">
            <p>
                <span class="dropcap">D</span>
                okumen ini mencatat aktivitas pemrosesan data pribadi yang dijalankan oleh divisi
                <em>{{ $ropa['division'] ?? '-' }}</em>, dengan nomor referensi
                <strong>{{ $ropa['number'] ?? '-' }}</strong>. Pemrosesan dimaksud bertajuk
                <em>{{ $ropa['name'] ?? '-' }}</em>, dan dilaksanakan oleh
                <strong>{{ $ropa['org'] ?? ($orgName ?? '-') }}</strong>.
            </p>
            <p>{{ $ropa['description'] ?? '' }}</p>
        </div>

        <div class="key-list">
            <table>
                <tr><td class="k-key">Nomor</td><td class="k-val">{{ $ropa['number'] ?? '-' }}</td></tr>
                <tr><td class="k-key">Divisi &middot; Unit</td><td class="k-val">{{ $ropa['division'] ?? '-' }} &middot; {{ $ropa['unit'] ?? '-' }}</td></tr>
                <tr><td class="k-key">Tujuan</td><td class="k-val">{{ $ropa['purpose'] ?? '-' }}</td></tr>
                <tr class="last"><td class="k-key">Dasar Hukum</td><td class="k-val">{{ $ropa['legal_basis'] ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="cat-block">
            <div class="cat-k">Kategori Pemrosesan</div>
            <div class="cat-v">
                @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                    {{ implode(' · ', $ropa['categories']) }}
                @else
                    -
                @endif
            </div>
        </div>

        <div class="ct-foot">
            &diams; II &diams;
        </div>
    </div>
</body>
</html>
