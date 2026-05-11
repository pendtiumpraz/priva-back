<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #f5f5f0; }

        /* ---------- COVER (Onyx Premium) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #0a0a0a;
            color: #f5f5f0;
            overflow: hidden;
            page-break-after: always;
        }
        /* Massive "02" watermark, top-right (rotated negative offset) */
        .wm-02 {
            position: absolute;
            top: -50mm; right: -40mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 380pt;
            font-weight: 900;
            line-height: 0.8;
            letter-spacing: -10pt;
            color: #161616;
        }
        /* Lime arc — huge circle with thick border, clipped by overflow:hidden */
        .lime-arc {
            position: absolute;
            bottom: -180mm; left: -85mm;
            width: 360mm; height: 360mm;
            background: transparent;
            border: 42mm solid #d4ff3a;
            border-radius: 50%;
        }

        .cover-inner {
            position: relative;
            padding: 16mm 16mm;
            height: 29.7cm;
        }
        .topbar {
            position: relative;
        }
        .topbar table { width: 100%; border-collapse: collapse; }
        .topbar td { vertical-align: middle; }
        .topbar td.right { text-align: right; }
        .dot {
            display: inline-block;
            width: 2.5mm; height: 2.5mm;
            background: #d4ff3a;
            border-radius: 50%;
            margin-right: 3mm;
            vertical-align: middle;
        }
        .top-label {
            font-size: 8.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #a5a5a0;
            vertical-align: middle;
        }
        .top-num {
            font-size: 8.5pt;
            letter-spacing: 2pt;
            color: #a5a5a0;
        }

        .midblock {
            position: absolute;
            left: 16mm; right: 16mm;
            top: 110mm;
        }
        .mid-eyebrow {
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #d4ff3a;
            font-weight: 700;
            margin-bottom: 5mm;
        }
        .mid-title {
            font-size: 96pt;
            font-weight: 900;
            line-height: 0.88;
            letter-spacing: -3pt;
            margin: 0;
            color: #f5f5f0;
        }
        .mid-title .accent { color: #d4ff3a; }
        .mid-desc {
            margin-top: 9mm;
            max-width: 160mm;
            font-size: 12pt;
            line-height: 1.5;
            color: #c5c5c0;
        }

        .bot-grid {
            position: absolute;
            left: 16mm; right: 16mm; bottom: 16mm;
            background: #262626;
        }
        .bot-grid table { width: 100%; border-collapse: collapse; }
        .bot-grid td {
            width: 33.33%;
            background: #0a0a0a;
            padding: 6mm 5mm;
            vertical-align: top;
            border-right: 0.3mm solid #262626;
        }
        .bot-grid td.last { border-right: none; }
        .bg-key {
            font-size: 7.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #d4ff3a;
            font-weight: 700;
        }
        .bg-val {
            margin-top: 3mm;
            font-size: 13pt;
            font-weight: 500;
            color: #f5f5f0;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #0a0a0a;
            color: #f5f5f0;
        }
        .ct-head {
            padding: 10mm 14mm 6mm;
            border-bottom: 1px solid #262626;
        }
        .ct-head table { width: 100%; border-collapse: collapse; }
        .ct-head td.right { text-align: right; }
        .ct-head .hd-label {
            font-size: 8.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #a5a5a0;
            vertical-align: middle;
        }
        .ct-head .hd-num {
            font-size: 8.5pt;
            letter-spacing: 2pt;
            color: #a5a5a0;
        }

        .sec-block { padding: 10mm 14mm 0; }
        .big-num {
            font-size: 40pt;
            font-weight: 900;
            color: #d4ff3a;
            letter-spacing: -1.5pt;
            line-height: 0.9;
            display: inline-block;
            vertical-align: middle;
            margin-right: 6mm;
        }
        .big-num-title {
            font-size: 19pt;
            font-weight: 700;
            letter-spacing: -0.5pt;
            display: inline-block;
            vertical-align: middle;
            color: #f5f5f0;
        }
        .sec-title-row { margin-bottom: 6mm; }

        .cell-grid table { width: 100%; border-collapse: collapse; }
        .cell-grid td {
            width: 50%;
            vertical-align: top;
            padding: 0 2mm 3mm 0;
        }
        .cell-grid td.right { padding: 0 0 3mm 2mm; }
        .cell {
            padding: 4mm 5mm;
            background: #141414;
            border-left: 1mm solid #d4ff3a;
        }
        .cell .c-key {
            font-size: 7.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #a5a5a0;
            font-weight: 700;
        }
        .cell .c-val {
            margin-top: 2mm;
            font-size: 11pt;
            color: #f5f5f0;
            line-height: 1.5;
        }
        .cell-wide { display: block; }
        .cat-chip {
            display: inline-block;
            padding: 1.5mm 3.5mm;
            border: 1px solid #d4ff3a;
            color: #d4ff3a;
            font-size: 9pt;
            font-weight: 500;
            margin: 0 1.5mm 1.5mm 0;
        }

        .ct-foot {
            position: absolute;
            bottom: 8mm; left: 14mm; right: 14mm;
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #5a5a55;
        }
        .ct-foot table { width: 100%; border-collapse: collapse; }
        .ct-foot td.right { text-align: right; color: #d4ff3a; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="wm-02">02</div>
        <div class="lime-arc"></div>
        <div class="cover-inner">
            <div class="topbar">
                <table><tr>
                    <td>
                        <span class="dot"></span><span class="top-label">Ropa Export &middot; Premium Tier</span>
                    </td>
                    <td class="right"><span class="top-num">{{ $ropa['number'] ?? '' }}</span></td>
                </tr></table>
            </div>
            <div class="midblock">
                <div class="mid-eyebrow">Record of Processing</div>
                <h1 class="mid-title">
                    ropa.<br>
                    <span class="accent">export</span>
                </h1>
                <div class="mid-desc">{{ $ropa['description'] ?? '' }}</div>
            </div>
            <div class="bot-grid">
                <table><tr>
                    <td>
                        <div class="bg-key">Document</div>
                        <span class="bg-val">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="bg-key">Effective</div>
                        <span class="bg-val">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                    <td class="last">
                        <div class="bg-key">Owner</div>
                        <span class="bg-val">{{ is_array($ropa['pic'] ?? null) ? ($ropa['pic']['name'] ?? '-') : '-' }}</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <table><tr>
                <td>
                    <span class="dot"></span><span class="hd-label">Ropa Export &middot; Page 02</span>
                </td>
                <td class="right"><span class="hd-num">{{ $ropa['number'] ?? '' }}</span></td>
            </tr></table>
        </div>

        <div class="sec-block">
            <div class="sec-title-row">
                <span class="big-num">01</span><span class="big-num-title">Deskripsi Pemrosesan</span>
            </div>
            <div class="cell-grid">
                <table>
                    <tr>
                        <td>
                            <div class="cell">
                                <div class="c-key">Nama Pemrosesan</div>
                                <div class="c-val">{{ $ropa['name'] ?? '-' }}</div>
                            </div>
                        </td>
                        <td class="right">
                            <div class="cell">
                                <div class="c-key">Entitas</div>
                                <div class="c-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="cell">
                                <div class="c-key">Divisi</div>
                                <div class="c-val">{{ $ropa['division'] ?? '-' }}</div>
                            </div>
                        </td>
                        <td class="right">
                            <div class="cell">
                                <div class="c-key">Unit Kerja</div>
                                <div class="c-val">{{ $ropa['unit'] ?? '-' }}</div>
                            </div>
                        </td>
                    </tr>
                </table>
                <div class="cell cell-wide">
                    <div class="c-key">Deskripsi Singkat</div>
                    <div class="c-val">{{ $ropa['description'] ?? '-' }}</div>
                </div>
            </div>
        </div>

        <div class="sec-block">
            <div class="sec-title-row">
                <span class="big-num">02</span><span class="big-num-title">Informasi Pemrosesan</span>
            </div>
            <div class="cell-grid">
                <table>
                    <tr>
                        <td>
                            <div class="cell">
                                <div class="c-key">Tujuan</div>
                                <div class="c-val">{{ $ropa['purpose'] ?? '-' }}</div>
                            </div>
                        </td>
                        <td class="right">
                            <div class="cell">
                                <div class="c-key">Dasar Hukum</div>
                                <div class="c-val">{{ $ropa['legal_basis'] ?? '-' }}</div>
                            </div>
                        </td>
                    </tr>
                </table>
                <div class="cell cell-wide">
                    <div class="c-key">Kategori Pemrosesan</div>
                    <div class="c-val">
                        @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                            @foreach($ropa['categories'] as $cat)
                                <span class="cat-chip">{{ $cat }}</span>
                            @endforeach
                        @else
                            -
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="ct-foot">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">&bull; 02 / 08</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
