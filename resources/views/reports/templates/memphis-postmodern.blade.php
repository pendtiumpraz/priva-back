<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #1a1a2e; }

        /* ---------- COVER (Memphis Postmodern) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #fef7ed;
            color: #1a1a2e;
            page-break-after: always;
            overflow: hidden;
        }
        /* decorative shapes top-right */
        .deco-circle-red {
            position: absolute;
            top: 18mm; right: 18mm;
            width: 32mm; height: 32mm;
            background: #ff6b6b;
            border-radius: 50%;
        }
        .deco-sq-teal {
            position: absolute;
            top: 28mm; right: 56mm;
            width: 17mm; height: 17mm;
            background: #4ecdc4;
        }
        .deco-bars {
            position: absolute;
            top: 56mm; right: 28mm;
        }
        .deco-bar {
            width: 22mm; height: 2.2mm;
            background: #1a1a2e;
            margin-bottom: 2.2mm;
        }
        /* dot grid SVG (5x5) */
        .deco-dots {
            position: absolute;
            top: 78mm; right: 56mm;
        }
        /* yellow triangle bottom-left, dompdf no clip-path → inline svg */
        .deco-triangle {
            position: absolute;
            bottom: -20mm; left: -20mm;
            width: 62mm; height: 62mm;
        }
        /* dashed cyan circle bottom-right */
        .deco-dashed {
            position: absolute;
            bottom: 16mm; right: -10mm;
            width: 45mm; height: 45mm;
        }

        .cover-inner {
            position: absolute;
            top: 16mm; left: 16mm; right: 16mm; bottom: 16mm;
        }
        .logo-row table { border-collapse: collapse; }
        .logo-row td { vertical-align: middle; padding: 0 3mm 0 0; }
        .logo-sq {
            width: 11mm; height: 11mm;
            background: #1a1a2e;
            color: #ffd166;
            font-weight: 900;
            font-size: 17pt;
            text-align: center;
            line-height: 11mm;
        }
        .logo-text { font-size: 11pt; font-weight: 700; }

        .title-block {
            position: absolute;
            top: 90mm; left: 0; right: 30mm;
        }
        .pill-tag {
            display: inline-block;
            padding: 1.5mm 4mm;
            background: #1a1a2e;
            color: #ffd166;
            border-radius: 8mm;
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 1.2pt;
            text-transform: uppercase;
        }
        .cover-title {
            font-size: 76pt;
            font-weight: 900;
            line-height: 0.92;
            letter-spacing: -2.5pt;
            margin: 4mm 0 0;
        }
        .cover-title .red { color: #ff6b6b; }
        .cover-desc {
            margin-top: 7mm;
            font-size: 12pt;
            line-height: 1.55;
            color: #3a3a5e;
            max-width: 140mm;
        }

        /* 3 colored info boxes at bottom */
        .info-row {
            position: absolute;
            bottom: 0; left: 0; right: 0;
        }
        .info-row table { width: 100%; border-collapse: separate; border-spacing: 3mm 0; }
        .info-row td {
            width: 33.33%;
            padding: 4mm 5mm;
            border: 0.8mm solid #1a1a2e;
            border-radius: 5mm;
            vertical-align: top;
        }
        .info-row td.c-red { background: #ff6b6b; }
        .info-row td.c-teal { background: #4ecdc4; }
        .info-row td.c-yellow { background: #ffd166; }
        .info-k {
            font-size: 8pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2pt;
        }
        .info-v {
            margin-top: 2mm;
            font-size: 12pt;
            font-weight: 800;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #fef7ed;
            color: #1a1a2e;
        }
        .ct-pad { padding: 12mm 14mm 0; }
        .ct-h-row { width: 100%; border-collapse: collapse; }
        .ct-h-row td { vertical-align: middle; }
        .ct-h-row td.r { text-align: right; }
        .ct-h2 {
            font-size: 24pt;
            font-weight: 900;
            margin: 0;
            letter-spacing: -0.7pt;
        }
        .ct-h2 .red { color: #ff6b6b; }
        .ct-h2 .teal { color: #4ecdc4; }
        .ct-pill {
            display: inline-block;
            padding: 1.5mm 4mm;
            background: #1a1a2e;
            color: #fef7ed;
            border-radius: 8mm;
            font-size: 9pt;
            font-weight: 700;
        }

        /* card grid */
        .card-grid { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; margin-top: 4mm; }
        .card {
            padding: 4mm 5mm;
            border: 0.8mm solid #1a1a2e;
            border-radius: 5mm;
            vertical-align: top;
            background: #fff;
        }
        .card.c-red { background: #ff6b6b; }
        .card.c-teal { background: #4ecdc4; }
        .card.c-yellow { background: #ffd166; }
        .c-key {
            font-size: 8pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.3pt;
            color: #1a1a2e;
        }
        .c-key.muted { color: #7a7a8e; }
        .c-val {
            margin-top: 2mm;
            font-size: 12pt;
            font-weight: 700;
            display: block;
        }
        .c-val.normal { font-weight: 500; font-size: 10.5pt; line-height: 1.5; }

        .cat-row {
            margin-top: 2mm;
        }
        .cat-pill {
            display: inline-block;
            padding: 1.2mm 4mm;
            border-radius: 8mm;
            font-size: 9.5pt;
            font-weight: 700;
            border: 0.6mm solid #1a1a2e;
            margin: 0 1.5mm 1.5mm 0;
            color: #1a1a2e;
        }
        .cp-red { background: #ff6b6b; }
        .cp-teal { background: #4ecdc4; }
        .cp-yellow { background: #ffd166; }
        .cp-purple { background: #a78bfa; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="deco-circle-red"></div>
        <div class="deco-sq-teal"></div>
        <div class="deco-bars">
            <div class="deco-bar"></div>
            <div class="deco-bar"></div>
            <div class="deco-bar"></div>
        </div>
        {{-- dot grid: 5x5 yellow circles via svg --}}
        <svg class="deco-dots" width="34mm" height="34mm" viewBox="0 0 120 120">
            <circle cx="8" cy="8" r="4" fill="#ffd166"/>
            <circle cx="32" cy="8" r="4" fill="#ffd166"/>
            <circle cx="56" cy="8" r="4" fill="#ffd166"/>
            <circle cx="80" cy="8" r="4" fill="#ffd166"/>
            <circle cx="104" cy="8" r="4" fill="#ffd166"/>
            <circle cx="8" cy="32" r="4" fill="#ffd166"/>
            <circle cx="32" cy="32" r="4" fill="#ffd166"/>
            <circle cx="56" cy="32" r="4" fill="#ffd166"/>
            <circle cx="80" cy="32" r="4" fill="#ffd166"/>
            <circle cx="104" cy="32" r="4" fill="#ffd166"/>
            <circle cx="8" cy="56" r="4" fill="#ffd166"/>
            <circle cx="32" cy="56" r="4" fill="#ffd166"/>
            <circle cx="56" cy="56" r="4" fill="#ffd166"/>
            <circle cx="80" cy="56" r="4" fill="#ffd166"/>
            <circle cx="104" cy="56" r="4" fill="#ffd166"/>
            <circle cx="8" cy="80" r="4" fill="#ffd166"/>
            <circle cx="32" cy="80" r="4" fill="#ffd166"/>
            <circle cx="56" cy="80" r="4" fill="#ffd166"/>
            <circle cx="80" cy="80" r="4" fill="#ffd166"/>
            <circle cx="104" cy="80" r="4" fill="#ffd166"/>
            <circle cx="8" cy="104" r="4" fill="#ffd166"/>
            <circle cx="32" cy="104" r="4" fill="#ffd166"/>
            <circle cx="56" cy="104" r="4" fill="#ffd166"/>
            <circle cx="80" cy="104" r="4" fill="#ffd166"/>
            <circle cx="104" cy="104" r="4" fill="#ffd166"/>
        </svg>
        {{-- yellow triangle, dompdf no clip-path → inline svg --}}
        <svg class="deco-triangle" viewBox="0 0 100 100">
            <polygon points="50,0 100,100 0,100" fill="#ffd166"/>
        </svg>
        {{-- dashed teal circle as svg (dashed border can be glitchy in dompdf) --}}
        <svg class="deco-dashed" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="42" stroke="#4ecdc4" stroke-width="6" stroke-dasharray="8 6" fill="none"/>
        </svg>

        <div class="cover-inner">
            <div class="logo-row">
                <table><tr>
                    <td><div class="logo-sq">R</div></td>
                    <td><span class="logo-text">ROPA Export</span></td>
                </tr></table>
            </div>

            <div class="title-block">
                <span class="pill-tag">Record of Processing</span>
                <h1 class="cover-title">
                    Hello, <span class="red">data!</span><br>
                    Mari kita catat.
                </h1>
                <div class="cover-desc">{{ $ropa['description'] ?? '' }}</div>
            </div>

            <div class="info-row">
                <table><tr>
                    <td class="c-red">
                        <div class="info-k">No.</div>
                        <span class="info-v">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td class="c-teal">
                        <div class="info-k">Divisi</div>
                        <span class="info-v">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                    <td class="c-yellow">
                        <div class="info-k">Tanggal</div>
                        <span class="info-v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-pad">
            <table class="ct-h-row"><tr>
                <td><h2 class="ct-h2"><span class="red">01.</span> Deskripsi</h2></td>
                <td class="r"><span class="ct-pill">{{ $ropa['number'] ?? '' }}</span></td>
            </tr></table>

            <table class="card-grid">
                <tr>
                    <td class="card c-red" style="width: 50%;">
                        <div class="c-key">Nama</div>
                        <span class="c-val">{{ $ropa['name'] ?? '-' }}</span>
                    </td>
                    <td class="card c-teal" style="width: 50%;">
                        <div class="c-key">Entitas</div>
                        <span class="c-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="card" style="width: 50%;">
                        <div class="c-key muted">Divisi</div>
                        <span class="c-val">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                    <td class="card" style="width: 50%;">
                        <div class="c-key muted">Unit</div>
                        <span class="c-val">{{ $ropa['unit'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="card c-yellow" colspan="2">
                        <div class="c-key">Deskripsi Singkat</div>
                        <span class="c-val normal">{{ $ropa['description'] ?? '-' }}</span>
                    </td>
                </tr>
            </table>

            <h2 class="ct-h2" style="margin-top: 9mm;"><span class="teal">02.</span> Informasi</h2>

            <table class="card-grid">
                <tr>
                    <td class="card" colspan="2">
                        <div class="c-key muted">Tujuan</div>
                        <span class="c-val normal">{{ $ropa['purpose'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="card" colspan="2">
                        <div class="c-key muted">Kategori Pemrosesan</div>
                        <div class="cat-row">
                            @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                                @foreach($ropa['categories'] as $i => $cat)
                                    @php
                                        $cls = ['cp-red','cp-teal','cp-yellow','cp-purple'][$i % 4];
                                    @endphp
                                    <span class="cat-pill {{ $cls }}">{{ $cat }}</span>
                                @endforeach
                            @else
                                <span class="cat-pill cp-red">-</span>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
