<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans Mono', 'Courier', monospace; color: #e6edff; }

        /* ---------- COVER (Geometric Tech) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #0a0f1f;
            color: #e6edff;
            overflow: hidden;
            page-break-after: always;
        }
        /* dompdf doesn't render CSS background-grid reliably -> use a table of hairline cells */
        .grid-bg {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            width: 21cm; height: 29.7cm;
            border-collapse: collapse;
            opacity: 1;
        }
        .grid-bg td {
            width: calc(21cm / 13);
            height: 11mm;
            border: 1px solid rgba(110,160,255,0.08);
            padding: 0;
        }
        /* Rotated outer square */
        .rot-sq-1 {
            position: absolute;
            top: 22mm; right: -20mm;
            width: 72mm; height: 72mm;
            border: 1px solid #4d7bff;
            transform: rotate(15deg);
        }
        .rot-sq-2 {
            position: absolute;
            top: 32mm; right: 8mm;
            width: 52mm; height: 52mm;
            border: 1px solid #4d7bff;
            opacity: 0.5;
        }
        .grad-sq {
            position: absolute;
            top: 42mm; right: 20mm;
            width: 32mm; height: 32mm;
            background: #4d7bff;
            background: linear-gradient(135deg, #4d7bff 0%, #2c4cff 100%);
        }

        .cover-inner {
            position: relative;
            padding: 16mm 16mm;
            height: 29.7cm;
        }
        .topbar {
            font-size: 8pt;
            color: #7a8bbf;
        }
        .topbar table { width: 100%; border-collapse: collapse; }
        .topbar .right { text-align: right; }

        .midblock {
            position: absolute;
            left: 16mm; right: 16mm;
            top: 105mm;
        }
        .prompt {
            font-size: 8pt;
            color: #4d7bff;
            letter-spacing: 2pt;
            margin-bottom: 4mm;
        }
        .big-title {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 68pt;
            font-weight: 900;
            line-height: 0.95;
            letter-spacing: -2pt;
            margin: 0;
            color: #e6edff;
        }
        .big-title .accent { color: #4d7bff; }

        .payload {
            margin-top: 8mm;
            padding: 4mm 5mm;
            border: 1px solid #2a3a6e;
            background: rgba(77,123,255,0.06);
            max-width: 150mm;
        }
        .payload .pl-comment {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
            color: #7a8bbf;
            margin-bottom: 2mm;
        }
        .payload .pl-body {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #e6edff;
        }
        .payload .pl-key { color: #4d7bff; }

        .bottom-grid {
            position: absolute;
            left: 16mm; right: 16mm; bottom: 18mm;
            border: 1px solid #2a3a6e;
        }
        .bottom-grid table { width: 100%; border-collapse: collapse; }
        .bottom-grid td {
            width: 25%;
            padding: 4mm 5mm;
            border-right: 1px solid #2a3a6e;
            vertical-align: top;
        }
        .bottom-grid td.last { border-right: none; }
        .bg-key {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 7pt;
            color: #4d7bff;
            letter-spacing: 2pt;
        }
        .bg-val {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            margin-top: 2mm;
            font-size: 10pt;
            color: #e6edff;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #0a0f1f;
            color: #e6edff;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
        }
        .ct-head {
            padding: 10mm 14mm 6mm;
            border-bottom: 1px solid #2a3a6e;
        }
        .ct-head .tinybar {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
            color: #7a8bbf;
        }
        .ct-head .tinybar table { width: 100%; border-collapse: collapse; }
        .ct-head .tinybar td.right { text-align: right; }
        .ct-head h2 {
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: -0.6pt;
            margin: 3mm 0 4mm;
            color: #e6edff;
        }
        .ct-head h2 .sec-tag {
            color: #4d7bff;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 13pt;
            margin-right: 4mm;
        }

        .ct-rows {
            padding: 6mm 10mm;
        }
        .ct-row {
            padding: 3mm 5mm;
            border-bottom: 1px solid #1a233f;
        }
        .ct-row table { width: 100%; border-collapse: collapse; }
        .ct-row td.k {
            width: 60mm;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 9pt;
            color: #4d7bff;
            vertical-align: top;
        }
        .ct-row td.v {
            font-size: 10pt;
            color: #e6edff;
            line-height: 1.5;
            vertical-align: top;
        }

        .ct-divider {
            padding: 6mm 14mm 4mm;
            border-top: 1px solid #2a3a6e;
            border-bottom: 1px solid #2a3a6e;
        }
        .ct-divider .tinybar {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
            color: #7a8bbf;
            margin-bottom: 2mm;
        }
        .ct-divider h2 {
            font-size: 20pt;
            font-weight: 700;
            letter-spacing: -0.6pt;
            margin: 0;
            color: #e6edff;
        }
        .ct-divider h2 .sec-tag {
            color: #4d7bff;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 12pt;
            margin-right: 4mm;
        }
        .cat-chip {
            display: inline-block;
            padding: 1mm 3mm;
            border: 1px solid #2a3a6e;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
            color: #a3b3e6;
            margin: 0 1mm 1.5mm 0;
        }

        .ct-foot {
            position: absolute;
            bottom: 8mm; left: 14mm; right: 14mm;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 7.5pt;
            color: #4d7bff;
        }
        .ct-foot table { width: 100%; border-collapse: collapse; }
        .ct-foot td.right { text-align: right; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        {{-- background grid via table of hairline cells (dompdf grid-line backgrounds unreliable) --}}
        <table class="grid-bg">
            @for($r = 0; $r < 27; $r++)
                <tr>
                    @for($c = 0; $c < 13; $c++)
                        <td>&nbsp;</td>
                    @endfor
                </tr>
            @endfor
        </table>

        <div class="rot-sq-1"></div>
        <div class="rot-sq-2"></div>
        <div class="grad-sq"></div>

        <div class="cover-inner">
            <div class="topbar">
                <table><tr>
                    <td>// CLASSIFIED &middot; L2-CONFIDENTIAL</td>
                    <td class="right">{{ $ropa['number'] ?? '' }}</td>
                </tr></table>
            </div>

            <div class="midblock">
                <div class="prompt">$ ropa --export --format=pdf</div>
                <h1 class="big-title">
                    ROPA<br>
                    <span class="accent">/EXPORT</span>
                </h1>
                <div class="payload">
                    <div class="pl-comment">// payload</div>
                    <div class="pl-body">
                        <span class="pl-key">name:</span> "{{ $ropa['name'] ?? '' }}"<br>
                        <span class="pl-key">org:</span> "{{ $ropa['org'] ?? ($orgName ?? '') }}"
                    </div>
                </div>
            </div>

            <div class="bottom-grid">
                <table><tr>
                    <td>
                        <div class="bg-key">[NUM]</div>
                        <span class="bg-val">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="bg-key">[DIV]</div>
                        <span class="bg-val">{{ \Illuminate\Support\Str::limit($ropa['division'] ?? '-', 12, '') }}</span>
                    </td>
                    <td>
                        <div class="bg-key">[DATE]</div>
                        <span class="bg-val">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                    <td class="last">
                        <div class="bg-key">[STATUS]</div>
                        <span class="bg-val">ACTIVE</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <div class="tinybar">
                <table><tr>
                    <td>// section/01</td>
                    <td class="right">{{ $ropa['number'] ?? '' }} &middot; p.02</td>
                </tr></table>
            </div>
            <h2><span class="sec-tag">&sect;01</span>Deskripsi Pemrosesan</h2>
        </div>
        <div class="ct-rows">
            <div class="ct-row"><table><tr><td class="k">ropa_number:</td><td class="v">{{ $ropa['number'] ?? '-' }}</td></tr></table></div>
            <div class="ct-row"><table><tr><td class="k">name:</td><td class="v">{{ $ropa['name'] ?? '-' }}</td></tr></table></div>
            <div class="ct-row"><table><tr><td class="k">division:</td><td class="v">{{ $ropa['division'] ?? '-' }}</td></tr></table></div>
            <div class="ct-row"><table><tr><td class="k">unit:</td><td class="v">{{ $ropa['unit'] ?? '-' }}</td></tr></table></div>
            <div class="ct-row"><table><tr><td class="k">entity:</td><td class="v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr></table></div>
            <div class="ct-row"><table><tr><td class="k">description:</td><td class="v">{{ $ropa['description'] ?? '-' }}</td></tr></table></div>
        </div>

        <div class="ct-divider">
            <div class="tinybar">// section/02</div>
            <h2><span class="sec-tag">&sect;02</span>Informasi Pemrosesan</h2>
        </div>
        <div class="ct-rows">
            <div class="ct-row"><table><tr><td class="k">purpose:</td><td class="v">{{ $ropa['purpose'] ?? '-' }}</td></tr></table></div>
            <div class="ct-row"><table><tr><td class="k">legal_basis:</td><td class="v">{{ $ropa['legal_basis'] ?? '-' }}</td></tr></table></div>
            <div class="ct-row">
                <table><tr>
                    <td class="k">categories:</td>
                    <td class="v">
                        @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                            @foreach($ropa['categories'] as $cat)
                                <span class="cat-chip">{{ $cat }}</span>
                            @endforeach
                        @else
                            -
                        @endif
                    </td>
                </tr></table>
            </div>
        </div>

        <div class="ct-foot">
            <table><tr>
                <td>$ {{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">EOF &middot; 02/08</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
