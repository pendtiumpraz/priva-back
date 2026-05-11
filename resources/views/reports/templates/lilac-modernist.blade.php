<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #19124e; }

        /* ---------- COVER (Lilac Modernist) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f4f0ff;
            color: #19124e;
            page-break-after: always;
            overflow: hidden;
        }

        /* dompdf radial-gradient unreliable -> solid color circles via border-radius */
        .deco-1 {
            position: absolute;
            top: -60mm; right: -60mm;
            width: 160mm; height: 160mm;
            border-radius: 50%;
            background: #19124e;
        }
        .deco-2 {
            position: absolute;
            top: 32mm; right: 26mm;
            width: 46mm; height: 46mm;
            border-radius: 50%;
            background: #c4b6ff;
        }
        .deco-3 {
            position: absolute;
            top: 80mm; right: 66mm;
            width: 20mm; height: 20mm;
            border-radius: 50%;
            background: #19124e;
        }
        .deco-4 {
            position: absolute;
            bottom: -40mm; left: -40mm;
            width: 108mm; height: 108mm;
            border-radius: 50%;
            background: #ddd2ff;
        }

        .cover-pad {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            padding: 24mm 22mm;
        }
        .brand-row {
            position: absolute;
            top: 24mm; left: 22mm;
        }
        .brand-row table { border-collapse: collapse; }
        .brand-row td {
            vertical-align: middle;
            padding: 0;
        }
        .brand-mark {
            width: 12mm; height: 12mm;
            border-radius: 3mm;
            background: #19124e;
            color: #f4f0ff;
            text-align: center;
            font-weight: 700;
            font-size: 14pt;
            line-height: 12mm;
        }
        .brand-text {
            padding-left: 4mm;
            font-size: 11pt;
            font-weight: 600;
        }

        .cover-mid {
            position: absolute;
            top: 130mm; left: 22mm; right: 22mm;
        }
        .cover-mid .eyebrow {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #6852c8;
            font-weight: 700;
        }
        .cover-mid h1 {
            font-size: 50pt;
            line-height: 0.98;
            font-weight: 800;
            letter-spacing: -1.2pt;
            margin: 5mm 0 7mm;
            color: #19124e;
        }
        .cover-mid h1 .accent { color: #6852c8; }
        .cover-mid .descline {
            font-size: 13pt;
            color: #3d3373;
            max-width: 145mm;
            line-height: 1.55;
        }

        .info-cards {
            position: absolute;
            bottom: 24mm; left: 22mm; right: 22mm;
        }
        .info-cards table { width: 100%; border-collapse: separate; border-spacing: 4mm 0; }
        .info-cards td {
            width: 33.33%;
            vertical-align: top;
        }
        .info-card {
            background: #fff;
            border: 1px solid #e3dbff;
            border-radius: 5mm;
            padding: 5mm 5mm;
        }
        .info-card .k {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #6852c8;
            font-weight: 700;
        }
        .info-card .v {
            margin-top: 2.5mm;
            font-size: 12pt;
            font-weight: 600;
            display: block;
            color: #19124e;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #fff;
            color: #19124e;
        }
        /* dompdf radial-gradient unreliable -> solid color */
        .ct-head {
            background: #19124e;
            color: #f4f0ff;
            padding: 9mm 18mm 12mm;
        }
        .ct-head table { width: 100%; border-collapse: collapse; }
        .ct-head .eyebrow {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #c4b6ff;
        }
        .ct-head .title {
            font-size: 18pt;
            font-weight: 700;
            margin-top: 1.5mm;
            letter-spacing: -0.2pt;
        }
        .ct-head .ref {
            text-align: right;
            font-size: 10pt;
            vertical-align: bottom;
            color: #c4b6ff;
        }

        .ct-body {
            padding: 12mm 18mm 0;
        }
        .grid2 { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }
        .grid2 td {
            width: 50%;
            vertical-align: top;
            background: #f7f4ff;
            border-radius: 4mm;
            padding: 4.5mm 5mm;
        }
        .fld-k {
            font-size: 8pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: #6852c8;
            font-weight: 700;
        }
        .fld-v {
            margin-top: 2mm;
            font-size: 11pt;
            font-weight: 600;
            color: #19124e;
            line-height: 1.4;
            display: block;
        }

        .full-card {
            margin-top: 4mm;
            background: #f7f4ff;
            border-radius: 4mm;
            padding: 5mm 6mm;
        }
        .full-card .fld-v { font-weight: 500; line-height: 1.6; }

        .section2-title {
            margin-top: 11mm;
            font-size: 16pt;
            font-weight: 700;
            letter-spacing: -0.3pt;
            color: #19124e;
        }
        .section2-dot {
            display: inline-block;
            width: 3mm; height: 3mm;
            border-radius: 50%;
            background: #6852c8;
            margin-right: 3mm;
            vertical-align: middle;
        }

        .info-panel {
            margin-top: 5mm;
            background: #f7f4ff;
            border-radius: 4mm;
            padding: 6mm 7mm;
        }
        .info-panel .k {
            font-size: 8pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: #6852c8;
            font-weight: 700;
            margin-top: 4mm;
        }
        .info-panel .k:first-child { margin-top: 0; }
        .info-panel .v {
            margin-top: 2mm;
            font-size: 11pt;
            line-height: 1.45;
        }
        .chip {
            display: inline-block;
            padding: 1.5mm 3.5mm;
            border-radius: 999px;
            background: #e9e1ff;
            color: #19124e;
            font-size: 9.5pt;
            font-weight: 500;
            margin: 0 2mm 2mm 0;
        }

        .ct-foot {
            position: absolute;
            bottom: 9mm; left: 18mm; right: 18mm;
            font-size: 9pt;
            color: #9b8fd0;
            font-weight: 500;
        }
        .ct-foot table { width: 100%; border-collapse: collapse; }
        .ct-foot .right { text-align: right; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="deco-1"></div>
        <div class="deco-2"></div>
        <div class="deco-3"></div>
        <div class="deco-4"></div>

        <div class="brand-row">
            <table><tr>
                <td><div class="brand-mark">R</div></td>
                <td class="brand-text">ROPA Export</td>
            </tr></table>
        </div>

        <div class="cover-mid">
            <div class="eyebrow">Record of Processing Activities</div>
            <h1>
                {{ $ropa['name'] ?? '-' }}<br>
                <span class="accent">{{ $ropa['org'] ?? ($orgName ?? '') }}</span>
            </h1>
            <div class="descline">{{ $ropa['description'] ?? '' }}</div>
        </div>

        <div class="info-cards">
            <table><tr>
                <td>
                    <div class="info-card">
                        <div class="k">ROPA No.</div>
                        <span class="v">{{ $ropa['number'] ?? '-' }}</span>
                    </div>
                </td>
                <td>
                    <div class="info-card">
                        <div class="k">Divisi</div>
                        <span class="v">{{ $ropa['division'] ?? '-' }}</span>
                    </div>
                </td>
                <td>
                    <div class="info-card">
                        <div class="k">Tanggal</div>
                        <span class="v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </div>
                </td>
            </tr></table>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <table><tr>
                <td>
                    <div class="eyebrow">Bagian 01</div>
                    <div class="title">Deskripsi Pemrosesan</div>
                </td>
                <td class="ref">{{ $ropa['number'] ?? '' }}</td>
            </tr></table>
        </div>

        <div class="ct-body">
            <table class="grid2">
                <tr>
                    <td>
                        <div class="fld-k">Nama Pemrosesan</div>
                        <span class="fld-v">{{ $ropa['name'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="fld-k">Entitas</div>
                        <span class="fld-v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div class="fld-k">Divisi</div>
                        <span class="fld-v">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="fld-k">Unit Kerja</div>
                        <span class="fld-v">{{ $ropa['unit'] ?? '-' }}</span>
                    </td>
                </tr>
            </table>

            <div class="full-card">
                <div class="fld-k">Deskripsi Singkat</div>
                <span class="fld-v">{{ $ropa['description'] ?? '-' }}</span>
            </div>

            <div class="section2-title">
                <span class="section2-dot"></span>Informasi Pemrosesan
            </div>

            <div class="info-panel">
                <div class="k">Tujuan</div>
                <div class="v">{{ $ropa['purpose'] ?? '-' }}</div>

                <div class="k">Kategori Pemrosesan</div>
                <div class="v">
                    @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                        @foreach($ropa['categories'] as $cat)
                            <span class="chip">{{ $cat }}</span>
                        @endforeach
                    @else
                        -
                    @endif
                </div>

                <div class="k">Dasar Hukum</div>
                <div class="v"><span class="chip">{{ $ropa['legal_basis'] ?? '-' }}</span></div>
            </div>
        </div>

        <div class="ct-foot">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">02 / 08</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
