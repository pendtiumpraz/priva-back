<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #0a0a0a; }

        /* ---------- COVER (Bauhaus Primary) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f3eed8;
            color: #0a0a0a;
            page-break-after: always;
        }
        /* dompdf no clip-path → inline svg composition top-right */
        .corner-shapes {
            position: absolute;
            top: 0; right: 0;
            width: 95mm; height: 95mm;
        }
        .yellow-circle {
            position: absolute;
            top: 95mm; left: 0;
            width: 53mm; height: 53mm;
            background: #f1c40f;
            border-radius: 50%;
        }

        /* bottom rule bands */
        .band-black {
            position: absolute;
            bottom: 0; left: 0;
            width: 127mm; height: 3.2mm;
            background: #0a0a0a;
        }
        .band-red {
            position: absolute;
            bottom: 3.2mm; left: 0;
            width: 85mm; height: 1.6mm;
            background: #e63946;
        }
        .band-blue {
            position: absolute;
            bottom: 5.8mm; left: 0;
            width: 53mm; height: 0.8mm;
            background: #1d3557;
        }

        .cover-inner {
            position: absolute;
            top: 17mm; left: 15mm; right: 15mm; bottom: 17mm;
        }
        .top-meta {
            font-size: 9pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            font-weight: 700;
        }
        .top-meta .sub {
            margin-top: 1mm;
            color: #5a5a55;
            font-weight: 700;
        }

        .main-title-wrap {
            position: absolute;
            bottom: 90mm; left: 0; right: 0;
        }
        .cover-title {
            font-size: 90pt;
            font-weight: 900;
            line-height: 0.85;
            letter-spacing: -3pt;
            margin: 0;
            text-transform: lowercase;
        }
        .cover-title .red { color: #e63946; }
        .cover-name {
            margin-top: 9mm;
            max-width: 120mm;
            font-size: 12pt;
            line-height: 1.5;
        }
        .cover-org {
            margin-top: 1.5mm;
            font-size: 10pt;
            color: #5a5a55;
        }

        /* primitive shape row at bottom */
        .shape-row {
            position: absolute;
            bottom: 22mm; left: 15mm; right: 15mm;
        }
        .shape-row table { border-collapse: collapse; }
        .shape-row td { vertical-align: middle; padding: 0; }
        .shape-row td.spacer { width: 4mm; }
        .shape-sq, .shape-cr, .shape-tri-wrap {
            width: 16mm; height: 16mm;
        }
        .shape-sq { background: #1d3557; }
        .shape-cr { background: #f1c40f; border-radius: 50%; }
        .shape-date {
            font-size: 9pt;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            color: #5a5a55;
            padding-left: 6mm;
            line-height: 1.4;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f3eed8;
            color: #0a0a0a;
        }
        .band-red-top { width: 100%; height: 3.2mm; background: #e63946; }
        .band-blue-top { width: 100%; height: 1.6mm; background: #1d3557; }
        .band-yellow-top { width: 100%; height: 0.8mm; background: #f1c40f; }

        .ct-section { padding: 9mm 14mm 0; }
        .ct-h2-row table { border-collapse: collapse; }
        .ct-h2-row td { vertical-align: middle; padding: 0 4mm 0 0; }
        .h2-sq-red { width: 9mm; height: 9mm; background: #e63946; }
        .h2-cr-blue { width: 9mm; height: 9mm; background: #1d3557; border-radius: 50%; }
        .ct-h2 {
            font-size: 26pt;
            font-weight: 900;
            margin: 0;
            letter-spacing: -1pt;
            text-transform: lowercase;
            line-height: 1;
        }

        .grid-4 {
            margin-top: 6mm;
            border: 0.6mm solid #0a0a0a;
            border-collapse: collapse;
            width: 100%;
        }
        .grid-4 td {
            padding: 4mm 5mm;
            vertical-align: top;
            width: 50%;
        }
        .grid-4 td.bg-yellow { background: #f1c40f; }
        .grid-4 td.bg-white { background: #fff; }
        .grid-4 td.bg-blue { background: #1d3557; color: #fff; }
        .grid-4 td.bd-right { border-right: 0.6mm solid #0a0a0a; }
        .grid-4 td.bd-bottom { border-bottom: 0.6mm solid #0a0a0a; }
        .g-key {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 700;
            opacity: 0.7;
        }
        .g-val {
            margin-top: 2mm;
            font-size: 12pt;
            font-weight: 700;
            display: block;
        }

        .desc-box {
            margin-top: 5mm;
            padding: 5mm 6mm;
            border: 0.6mm solid #0a0a0a;
            background: #fff;
        }
        .desc-key {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 700;
        }
        .desc-val {
            margin-top: 2mm;
            font-size: 11pt;
            line-height: 1.55;
        }

        .black-box {
            margin-top: 6mm;
            padding: 5mm 6mm;
            background: #0a0a0a;
            color: #f3eed8;
        }
        .black-box .bk {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #f1c40f;
            font-weight: 700;
        }
        .black-box .bv {
            margin-top: 2mm;
            font-size: 12pt;
        }

        .cat-tags { margin-top: 5mm; }
        .cat-tag {
            display: inline-block;
            padding: 1.5mm 4mm;
            background: #fff;
            border: 0.6mm solid #0a0a0a;
            font-size: 10pt;
            font-weight: 700;
            margin: 0 2mm 2mm 0;
        }

        .band-red-bot {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3.2mm;
            background: #e63946;
        }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        {{-- 3 corner shapes: red square + blue triangle overlay + yellow circle below.
             dompdf no clip-path → inline svg --}}
        <svg class="corner-shapes" viewBox="0 0 100 100" preserveAspectRatio="none">
            <rect x="0" y="0" width="100" height="100" fill="#e63946"/>
            <polygon points="0,100 100,0 100,100" fill="#1d3557"/>
        </svg>
        <div class="yellow-circle"></div>

        <div class="band-black"></div>
        <div class="band-red"></div>
        <div class="band-blue"></div>

        <div class="cover-inner">
            <div class="top-meta">
                BAUHAUS / 1925
                <div class="sub">ROPA &middot; {{ $ropa['number'] ?? '' }}</div>
            </div>

            <div class="main-title-wrap">
                <h1 class="cover-title">
                    record<br>of<br><span class="red">processing.</span>
                </h1>
                <div class="cover-name">{{ $ropa['name'] ?? '' }}</div>
                <div class="cover-org">{{ $ropa['org'] ?? ($orgName ?? '') }}</div>
            </div>

            <div class="shape-row">
                <table><tr>
                    <td><div class="shape-sq"></div></td>
                    <td class="spacer"></td>
                    <td><div class="shape-cr"></div></td>
                    <td class="spacer"></td>
                    {{-- triangle as svg, dompdf no clip-path --}}
                    <td>
                        <svg width="45" height="45" viewBox="0 0 100 100">
                            <polygon points="50,0 100,100 0,100" fill="#e63946"/>
                        </svg>
                    </td>
                    <td class="shape-date">
                        {{ $ropa['date'] ?? ($today ?? '') }}<br>Effective Date
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="band-red-top"></div>
        <div class="band-blue-top"></div>
        <div class="band-yellow-top"></div>

        <div class="ct-section">
            <div class="ct-h2-row">
                <table><tr>
                    <td><div class="h2-sq-red"></div></td>
                    <td><h2 class="ct-h2">01 / deskripsi</h2></td>
                </tr></table>
            </div>

            <table class="grid-4">
                <tr>
                    <td class="bg-yellow bd-right bd-bottom">
                        <div class="g-key">No.</div>
                        <span class="g-val">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td class="bg-white bd-bottom">
                        <div class="g-key">Divisi</div>
                        <span class="g-val">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="bg-white bd-right">
                        <div class="g-key">Unit</div>
                        <span class="g-val">{{ $ropa['unit'] ?? '-' }}</span>
                    </td>
                    <td class="bg-blue">
                        <div class="g-key">Kategori</div>
                        <span class="g-val">{{ $ropa['category'] ?? '-' }}</span>
                    </td>
                </tr>
            </table>

            <div class="desc-box">
                <div class="desc-key">Deskripsi Singkat</div>
                <div class="desc-val">{{ $ropa['description'] ?? '-' }}</div>
            </div>
        </div>

        <div class="ct-section">
            <div class="ct-h2-row">
                <table><tr>
                    <td><div class="h2-cr-blue"></div></td>
                    <td><h2 class="ct-h2">02 / informasi</h2></td>
                </tr></table>
            </div>

            <div class="black-box">
                <div class="bk">Tujuan</div>
                <div class="bv">{{ $ropa['purpose'] ?? '-' }}</div>
            </div>

            <div class="cat-tags">
                @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                    @foreach($ropa['categories'] as $cat)
                        <span class="cat-tag">{{ $cat }}</span>
                    @endforeach
                @else
                    <span class="cat-tag">-</span>
                @endif
            </div>
        </div>

        <div class="band-red-bot"></div>
    </div>
</body>
</html>
