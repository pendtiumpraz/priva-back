<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Serif', 'Times New Roman', serif; color: #2d3a22; }

        /* ---------- COVER (Botanical Vintage) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f0ead6;
            color: #2d3a22;
            page-break-after: always;
        }
        .vine-tl { position: absolute; top: 16mm; left: 9mm; }
        .vine-tr { position: absolute; top: 16mm; right: 9mm; }
        .vine-bl { position: absolute; bottom: 16mm; left: 9mm; }
        .vine-br { position: absolute; bottom: 16mm; right: 9mm; }

        .frame {
            position: absolute;
            top: 22mm; right: 22mm; bottom: 22mm; left: 22mm;
            border: 0.35mm solid #4a6b3a;
        }
        .cover-inner {
            position: absolute;
            top: 24mm; right: 24mm; bottom: 24mm; left: 24mm;
            padding: 10mm;
            text-align: center;
        }
        .liber-label {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #4a6b3a;
            font-weight: 600;
        }
        .cover-title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 70pt;
            font-weight: 400;
            margin: 9mm 0 4mm;
            letter-spacing: -1pt;
            font-style: italic;
            line-height: 1.02;
        }
        .small-rule {
            width: 18mm; height: 0.3mm;
            background: #4a6b3a;
            margin: 4mm auto;
        }
        .activities-label {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 10pt;
            letter-spacing: 3.5pt;
            text-transform: uppercase;
            color: #4a6b3a;
            font-weight: 600;
        }
        .cover-name {
            margin-top: 22mm;
            font-size: 20pt;
            font-style: italic;
        }
        .cover-org {
            margin-top: 3mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 10pt;
            letter-spacing: 2.2pt;
            text-transform: uppercase;
            color: #5a6b4a;
        }
        .ref-band {
            margin: 18mm auto 0;
            display: inline-block;
            padding: 4mm 9mm;
            border-top: 0.3mm solid #4a6b3a;
            border-bottom: 0.3mm solid #4a6b3a;
        }
        .ref-band table { border-collapse: collapse; }
        .ref-band td { padding: 0 9mm; vertical-align: top; }
        .ref-k {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #4a6b3a;
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
            background: #faf6e8;
            color: #2d3a22;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
        }
        .ct-head {
            padding: 14mm 22mm 4mm;
            border-bottom: 0.3mm solid #4a6b3a;
        }
        .ct-head table { width: 100%; border-collapse: collapse; }
        .ct-head .left { vertical-align: bottom; }
        .ct-head .right { text-align: right; vertical-align: bottom; }
        .ct-h2 {
            font-size: 30pt;
            font-style: italic;
            margin: 0;
            font-weight: 400;
            letter-spacing: -0.5pt;
        }
        .ct-h2 em { font-style: normal; }
        .num-ref {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #5a6b4a;
        }

        .row-list { padding: 7mm 22mm; }
        .row-list table { width: 100%; border-collapse: collapse; }
        .row-list tr td {
            padding: 3mm 0;
            border-bottom: 0.25mm dashed #b4b89a;
            vertical-align: top;
        }
        .row-list .k {
            width: 55mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #4a6b3a;
            font-weight: 600;
            padding-top: 1mm;
        }
        .row-list .v {
            font-size: 13pt;
            font-style: italic;
        }

        .quote-box {
            margin: 4mm 22mm;
            padding: 5mm 8mm;
            background: #e8e3cb;
            border-left: 1mm solid #4a6b3a;
            font-size: 12pt;
            font-style: italic;
            line-height: 1.6;
        }

        .caput-2 {
            margin: 6mm 22mm 0;
            border-top: 0.3mm solid #4a6b3a;
            border-bottom: 0.3mm solid #4a6b3a;
            padding: 4mm 0;
            text-align: center;
        }
        .caput-2 h2 {
            font-size: 22pt;
            font-style: italic;
            margin: 0;
            font-weight: 400;
        }
        .caput-2 h2 em { font-style: normal; }

        .two-col {
            padding: 6mm 22mm;
        }
        .two-col table { width: 100%; border-collapse: collapse; }
        .two-col td {
            width: 50%;
            vertical-align: top;
            padding-right: 8mm;
        }
        .two-col .last { padding-right: 0; padding-left: 8mm; }
        .tc-k {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #4a6b3a;
            font-weight: 600;
        }
        .tc-v {
            margin-top: 1.5mm;
            font-size: 12pt;
            font-style: italic;
        }
        .tc-block { margin-top: 4mm; }

        .ct-foot {
            position: absolute;
            bottom: 14mm; left: 22mm; right: 22mm;
            text-align: center;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 3pt;
            color: #5a6b4a;
        }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        {{-- Botanical vines (SVG); 4 corners, mirrored via transform --}}
        <svg class="vine-tl" width="80" height="200" viewBox="0 0 80 200">
            <path d="M40 0 Q40 50 30 80 Q20 110 40 140 Q60 170 40 200" stroke="#4a6b3a" stroke-width="1.2" fill="none"/>
            <g transform="translate(40 20)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(30)"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(-30)"/></g>
            <g transform="translate(40 60)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(-30)"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(30)"/></g>
            <g transform="translate(40 100)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(30)"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(-30)"/></g>
            <g transform="translate(40 140)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(-30)"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(30)"/></g>
            <g transform="translate(40 180)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(30)"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(-30)"/></g>
        </svg>
        <svg class="vine-tr" width="80" height="200" viewBox="0 0 80 200" style="transform: scaleX(-1);">
            <path d="M40 0 Q40 50 30 80 Q20 110 40 140 Q60 170 40 200" stroke="#4a6b3a" stroke-width="1.2" fill="none"/>
            <g transform="translate(40 20)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(30)"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(-30)"/></g>
            <g transform="translate(40 60)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(-30)"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(30)"/></g>
            <g transform="translate(40 100)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(30)"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(-30)"/></g>
            <g transform="translate(40 140)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(-30)"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(30)"/></g>
            <g transform="translate(40 180)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7" transform="rotate(30)"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5" transform="rotate(-30)"/></g>
        </svg>
        <svg class="vine-bl" width="80" height="200" viewBox="0 0 80 200" style="transform: scaleY(-1);">
            <path d="M40 0 Q40 50 30 80 Q20 110 40 140 Q60 170 40 200" stroke="#4a6b3a" stroke-width="1.2" fill="none"/>
            <g transform="translate(40 20)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 60)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 100)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 140)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 180)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
        </svg>
        <svg class="vine-br" width="80" height="200" viewBox="0 0 80 200" style="transform: scale(-1, -1);">
            <path d="M40 0 Q40 50 30 80 Q20 110 40 140 Q60 170 40 200" stroke="#4a6b3a" stroke-width="1.2" fill="none"/>
            <g transform="translate(40 20)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 60)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 100)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 140)"><ellipse cx="-12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
            <g transform="translate(40 180)"><ellipse cx="12" cy="0" rx="10" ry="4" fill="#4a6b3a" opacity=".7"/><ellipse cx="-12" cy="6" rx="8" ry="3" fill="#4a6b3a" opacity=".5"/></g>
        </svg>

        <div class="frame"></div>
        <div class="cover-inner">
            <div class="liber-label">Liber Processus</div>
            <h1 class="cover-title">Record of<br>Processing</h1>
            <div class="small-rule"></div>
            <div class="activities-label">Activities</div>
            <div class="cover-name">&ldquo;{{ $ropa['name'] ?? '' }}&rdquo;</div>
            <div class="cover-org">{{ $ropa['org'] ?? ($orgName ?? '') }}</div>

            <div class="ref-band">
                <table><tr>
                    <td>
                        <div class="ref-k">Ref</div>
                        <span class="ref-v">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="ref-k">Date</div>
                        <span class="ref-v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <table><tr>
                <td class="left">
                    <h2 class="ct-h2">Caput I &middot; <em>Deskripsi</em></h2>
                </td>
                <td class="right">
                    <span class="num-ref">{{ $ropa['number'] ?? '' }}</span>
                </td>
            </tr></table>
        </div>

        <div class="row-list">
            <table>
                <tr><td class="k">Nomor</td><td class="v">{{ $ropa['number'] ?? '-' }}</td></tr>
                <tr><td class="k">Nama</td><td class="v">{{ $ropa['name'] ?? '-' }}</td></tr>
                <tr><td class="k">Divisi</td><td class="v">{{ $ropa['division'] ?? '-' }}</td></tr>
                <tr><td class="k">Unit</td><td class="v">{{ $ropa['unit'] ?? '-' }}</td></tr>
                <tr><td class="k">Entitas</td><td class="v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr>
            </table>
        </div>

        <div class="quote-box">
            &ldquo;{{ $ropa['description'] ?? '' }}&rdquo;
        </div>

        <div class="caput-2">
            <h2>Caput II &middot; <em>Informasi</em></h2>
        </div>

        <div class="two-col">
            <table><tr>
                <td>
                    <div class="tc-k">Tujuan</div>
                    <div class="tc-v">{{ $ropa['purpose'] ?? '-' }}</div>
                    <div class="tc-block">
                        <div class="tc-k">Dasar Hukum</div>
                        <div class="tc-v">{{ $ropa['legal_basis'] ?? '-' }}</div>
                    </div>
                </td>
                <td class="last">
                    <div class="tc-k">Kategori</div>
                    @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                        @foreach($ropa['categories'] as $cat)
                            <div class="tc-v" style="padding: 0.5mm 0;">&middot; {{ $cat }}</div>
                        @endforeach
                    @else
                        <div class="tc-v">&middot; -</div>
                    @endif
                </td>
            </tr></table>
        </div>

        <div class="ct-foot">
            &diams; {{ $ropa['org'] ?? ($orgName ?? '') }} &diams;
        </div>
    </div>
</body>
</html>
