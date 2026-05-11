<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #000; }

        /* ---------- COVER (Swiss International) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #ffffff;
            color: #000;
            page-break-after: always;
        }
        /* faint 12-col grid lines (dompdf grid unsupported -> use 11 absolute vertical rules) */
        .grid-lines {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
        }
        .vline {
            position: absolute;
            top: 0; bottom: 0;
            width: 1px;
            background: #f0f0f0;
        }
        .v1  { left: calc(21cm * 1 / 12); }
        .v2  { left: calc(21cm * 2 / 12); }
        .v3  { left: calc(21cm * 3 / 12); }
        .v4  { left: calc(21cm * 4 / 12); }
        .v5  { left: calc(21cm * 5 / 12); }
        .v6  { left: calc(21cm * 6 / 12); }
        .v7  { left: calc(21cm * 7 / 12); }
        .v8  { left: calc(21cm * 8 / 12); }
        .v9  { left: calc(21cm * 9 / 12); }
        .v10 { left: calc(21cm * 10 / 12); }
        .v11 { left: calc(21cm * 11 / 12); }

        .top-strip {
            padding: 18mm 22mm 0;
        }
        .top-strip table { width: 100%; border-collapse: collapse; }
        .top-strip td {
            font-size: 9.5pt;
            font-weight: 700;
        }
        .top-strip .right { text-align: right; }

        .top-rule {
            position: absolute;
            top: 35mm; left: 22mm; right: 22mm;
            height: 1px;
            background: #000;
        }

        .title-block {
            position: absolute;
            top: 55mm; left: 22mm; right: 22mm;
        }
        .title-block table { width: 100%; border-collapse: collapse; }
        .title-block .col-title { width: 75%; vertical-align: top; }
        .title-block .col-mark { width: 25%; vertical-align: top; padding-top: 14mm; }
        .swiss-title {
            font-size: 76pt;
            font-weight: 700;
            line-height: 0.95;
            letter-spacing: -3pt;
            margin: 0;
        }
        .red-mark {
            width: 8mm; height: 8mm;
            background: #e30613;
            margin-left: auto;
        }

        .abstract-block {
            position: absolute;
            top: 175mm; left: 22mm; right: 22mm;
        }
        .abstract-block table { width: 100%; border-collapse: collapse; }
        .abstract-block td {
            width: 50%;
            vertical-align: top;
            font-size: 10.5pt;
            line-height: 1.55;
        }
        .abstract-block td.left { padding-right: 8mm; }
        .abstract-block td.right { padding-left: 8mm; }
        .sw-label {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            margin-bottom: 2mm;
            display: block;
        }
        .muted-gray { color: #666; }

        .bottom-strip {
            position: absolute;
            bottom: 22mm; left: 22mm; right: 22mm;
            border-top: 3px solid #000;
            padding-top: 5mm;
        }
        .bottom-strip table { width: 100%; border-collapse: collapse; }
        .bottom-strip td {
            width: 25%;
            vertical-align: top;
            padding-right: 4mm;
        }
        .num-big {
            font-size: 30pt;
            font-weight: 700;
            letter-spacing: -1.5pt;
            color: #e30613;
            line-height: 1;
        }
        .sw-mini-label {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            margin-top: 3mm;
            display: block;
        }
        .sw-mini-val {
            font-size: 10pt;
            margin-top: 1.5mm;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #fff;
            color: #000;
        }
        .ct-head {
            padding: 14mm 22mm 4mm;
            border-bottom: 3px solid #000;
        }
        .ct-head table { width: 100%; border-collapse: collapse; }
        .ct-head td.h-title {
            font-size: 32pt;
            font-weight: 700;
            letter-spacing: -1pt;
            line-height: 1;
        }
        .ct-head td.h-page {
            text-align: right;
            font-size: 9.5pt;
            font-weight: 700;
            vertical-align: bottom;
            width: 25mm;
        }
        .red { color: #e30613; }

        .ct-section { padding: 8mm 22mm 0; }
        .ct-section table { width: 100%; border-collapse: collapse; }
        .ct-section td {
            vertical-align: top;
            border-top: 1px solid #000;
            padding: 3mm 3mm 4mm 0;
        }
        .ct-section td.last { padding-right: 0; }
        .ct-label {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
        }
        .ct-val {
            margin-top: 2mm;
            font-size: 10.5pt;
            line-height: 1.5;
            display: block;
        }

        .section-divider {
            margin: 6mm 22mm 0;
            border-top: 3px solid #000;
        }
        .ct-h2 {
            padding: 5mm 22mm 0;
            font-size: 32pt;
            font-weight: 700;
            letter-spacing: -1pt;
            line-height: 1;
            margin: 0;
        }

        .ct-foot {
            position: absolute;
            bottom: 12mm; left: 22mm; right: 22mm;
            border-top: 3px solid #000;
            padding-top: 4mm;
        }
        .ct-foot table { width: 100%; border-collapse: collapse; }
        .ct-foot td {
            font-size: 9.5pt;
            font-weight: 700;
        }
        .ct-foot .center { text-align: center; color: #e30613; width: 10mm; }
        .ct-foot .right { text-align: right; }

        .page-break { page-break-after: always; height: 0; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="grid-lines">
            <div class="vline v1"></div><div class="vline v2"></div><div class="vline v3"></div>
            <div class="vline v4"></div><div class="vline v5"></div><div class="vline v6"></div>
            <div class="vline v7"></div><div class="vline v8"></div><div class="vline v9"></div>
            <div class="vline v10"></div><div class="vline v11"></div>
        </div>

        <div class="top-strip">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">{{ $ropa['number'] ?? '' }} / {{ date('Y') }}</td>
            </tr></table>
        </div>
        <div class="top-rule"></div>

        <div class="title-block">
            <table><tr>
                <td class="col-title">
                    <h1 class="swiss-title">Record<br>of Processing<br>Activities.</h1>
                </td>
                <td class="col-mark">
                    <div class="red-mark"></div>
                </td>
            </tr></table>
        </div>

        <div class="abstract-block">
            <table><tr>
                <td class="left">
                    <span class="sw-label">Subject</span>
                    {{ $ropa['name'] ?? '-' }}<br>
                    <span class="muted-gray">{{ $ropa['division'] ?? '-' }} &middot; {{ $ropa['unit'] ?? '-' }}</span>
                </td>
                <td class="right">
                    <span class="sw-label">Abstract</span>
                    {{ $ropa['description'] ?? '-' }}
                </td>
            </tr></table>
        </div>

        <div class="bottom-strip">
            <table><tr>
                <td>
                    <div class="num-big">01</div>
                    <span class="sw-mini-label">No.</span>
                    <span class="sw-mini-val">{{ $ropa['number'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="num-big">02</div>
                    <span class="sw-mini-label">Div</span>
                    <span class="sw-mini-val">{{ $ropa['division'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="num-big">03</div>
                    <span class="sw-mini-label">Date</span>
                    <span class="sw-mini-val">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                </td>
                <td class="last">
                    <div class="num-big">04</div>
                    <span class="sw-mini-label">Basis</span>
                    <span class="sw-mini-val">{{ $ropa['legal_basis'] ?? '-' }}</span>
                </td>
            </tr></table>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <table><tr>
                <td class="h-title"><span class="red">01</span> Deskripsi Pemrosesan</td>
                <td class="h-page">02 / 08</td>
            </tr></table>
        </div>

        {{-- 12-col grid -> nested tables. row 1: 3+5+4, row 2: 6+6, row 3: 12 --}}
        <div class="ct-section">
            <table>
                <tr>
                    <td style="width: 25%;">
                        <span class="ct-label">Nomor ROPA</span>
                        <span class="ct-val">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td style="width: 41.66%;">
                        <span class="ct-label">Divisi</span>
                        <span class="ct-val">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                    <td style="width: 33.33%;" class="last">
                        <span class="ct-label">Unit Kerja</span>
                        <span class="ct-val">{{ $ropa['unit'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="width: 50%;">
                        <span class="ct-label">Nama Pemrosesan</span>
                        <span class="ct-val">{{ $ropa['name'] ?? '-' }}</span>
                    </td>
                    <td style="width: 50%;" class="last">
                        <span class="ct-label">Entitas</span>
                        <span class="ct-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="3" class="last">
                        <span class="ct-label">Deskripsi Singkat</span>
                        <span class="ct-val">{{ $ropa['description'] ?? '-' }}</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section-divider"></div>
        <h2 class="ct-h2"><span class="red">02</span> Informasi Pemrosesan</h2>

        <div class="ct-section">
            <table>
                <tr>
                    <td style="width: 50%;">
                        <span class="ct-label">Tujuan</span>
                        <span class="ct-val">{{ $ropa['purpose'] ?? '-' }}</span>
                    </td>
                    <td style="width: 50%;" class="last">
                        <span class="ct-label">Dasar Hukum</span>
                        <span class="ct-val">{{ $ropa['legal_basis'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" class="last">
                        <span class="ct-label">Kategori</span>
                        <span class="ct-val">
                            @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                                {{ implode(' / ', $ropa['categories']) }}
                            @else
                                -
                            @endif
                        </span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="ct-foot">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="center">&bull;</td>
                <td class="right">{{ $ropa['number'] ?? '' }}</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
