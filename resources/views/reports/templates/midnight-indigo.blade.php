<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Serif', 'Times New Roman', serif; }

        /* ---------- COVER (Midnight Indigo) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #0f1530;
            color: #e9e3cf;
            page-break-after: always;
        }
        /* gold rule frames */
        .frame-outer {
            position: absolute;
            top: 14mm; right: 14mm; bottom: 14mm; left: 14mm;
            border: 1px solid #c4a054;
        }
        .frame-inner {
            position: absolute;
            top: 16mm; right: 16mm; bottom: 16mm; left: 16mm;
            border: 1px solid #5a4a26;
        }
        .cover-body {
            position: absolute;
            top: 30mm; left: 25mm; right: 25mm; bottom: 25mm;
        }
        .eyebrow {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            letter-spacing: 4pt;
            font-size: 8.5pt;
            color: #c4a054;
            text-transform: uppercase;
        }
        .eyebrow .dash {
            display: inline-block;
            width: 8mm; height: 1px;
            background: #c4a054;
            vertical-align: middle;
            margin-right: 4mm;
            margin-bottom: 1.5pt;
        }
        .cover-title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 60pt;
            line-height: 1.02;
            margin: 14mm 0 8mm;
            font-weight: 500;
            letter-spacing: -1pt;
            color: #e9e3cf;
        }
        .cover-title em {
            color: #c4a054;
            font-style: italic;
        }
        .gold-divider {
            height: 1px;
            background: #c4a054;
            width: 55mm;
            margin: 10mm 0 6mm;
        }
        .cover-meta-top {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 10pt;
            line-height: 1.7;
            color: #b5ad97;
        }
        /* dompdf flex-quirky -> table layout for bottom row */
        .cover-bottom {
            position: absolute;
            left: 25mm; right: 25mm; bottom: 30mm;
        }
        .cover-bottom table { width: 100%; border-collapse: collapse; }
        .cover-bottom td {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #8a8270;
            vertical-align: bottom;
        }
        .cover-bottom .val {
            color: #c4a054;
            font-size: 17pt;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            letter-spacing: 0;
            text-transform: none;
            margin-bottom: 2mm;
            display: block;
        }
        .td-right { text-align: right; }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #faf7ee;
            color: #0f1530;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
        }
        .header-band {
            background: #0f1530;
            color: #e9e3cf;
            padding: 6mm 18mm;
        }
        .header-band table { width: 100%; border-collapse: collapse; }
        .header-band .h-title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 14pt;
            letter-spacing: -0.2pt;
        }
        .header-band .h-ref {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #c4a054;
            text-align: right;
        }
        .content-body { padding: 14mm 18mm 0; }
        .section { margin-bottom: 9mm; }
        .section-head {
            margin-bottom: 4mm;
            border-bottom: 1px solid rgba(15,21,48,0.12);
            padding-bottom: 2mm;
        }
        .section-head table { width: 100%; border-collapse: collapse; }
        .section-head .num {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            color: #c4a054;
            font-size: 17pt;
            width: 14mm;
        }
        .section-head .title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 17pt;
            font-weight: 500;
            color: #0f1530;
            letter-spacing: -0.3pt;
        }
        .row-table { width: 100%; border-collapse: collapse; }
        .row-table tr td {
            padding: 2.5mm 0;
            border-bottom: 1px solid rgba(15,21,48,0.06);
            vertical-align: top;
        }
        .row-key {
            width: 50mm;
            font-size: 7.5pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: rgba(15,21,48,0.5);
            padding-top: 1mm !important;
        }
        .row-val {
            font-size: 10pt;
            color: #0f1530;
            line-height: 1.55;
        }
        .cat-list { margin: 0; padding: 0; list-style: none; }
        .cat-list li { padding: 0.5mm 0; }
        .diamond {
            display: inline-block;
            width: 4pt; height: 4pt;
            background: #c4a054;
            transform: rotate(45deg);
            margin-right: 3mm;
            vertical-align: middle;
        }
        .content-footer {
            position: absolute;
            bottom: 12mm; left: 18mm; right: 18mm;
            font-size: 7pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: rgba(15,21,48,0.45);
        }
        .content-footer table { width: 100%; border-collapse: collapse; }
        .content-footer .right { text-align: right; }

        .page-break { page-break-after: always; height: 0; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="frame-outer"></div>
        <div class="frame-inner"></div>
        <div class="cover-body">
            <div class="eyebrow"><span class="dash"></span>Confidential &middot; Record of Processing</div>
            <h1 class="cover-title">
                Record of<br>
                <em>Processing</em><br>
                Activities
            </h1>
            <div class="gold-divider"></div>
            <div class="cover-meta-top">
                {{ $ropa['name'] ?? '' }}<br>
                {{ $ropa['org'] ?? ($orgName ?? '') }}
            </div>
        </div>
        <div class="cover-bottom">
            <table>
                <tr>
                    <td>
                        <span class="val">{{ $ropa['number'] ?? '' }}</span>
                        Document Reference
                    </td>
                    <td class="td-right">
                        <span class="val">{{ $ropa['date'] ?? ($today ?? '') }}</span>
                        Effective Date
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="header-band">
            <table>
                <tr>
                    <td class="h-title">Record of Processing Activities</td>
                    <td class="h-ref">{{ $ropa['number'] ?? '' }}</td>
                </tr>
            </table>
        </div>
        <div class="content-body">
            <div class="section">
                <div class="section-head">
                    <table><tr>
                        <td class="num">I.</td>
                        <td class="title">Deskripsi Pemrosesan</td>
                    </tr></table>
                </div>
                <table class="row-table">
                    <tr><td class="row-key">Nama Pemrosesan</td><td class="row-val">{{ $ropa['name'] ?? '-' }}</td></tr>
                    <tr><td class="row-key">Divisi</td><td class="row-val">{{ $ropa['division'] ?? '-' }}</td></tr>
                    <tr><td class="row-key">Unit Kerja</td><td class="row-val">{{ $ropa['unit'] ?? '-' }}</td></tr>
                    <tr><td class="row-key">Entitas</td><td class="row-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr>
                    <tr><td class="row-key">Deskripsi Singkat</td><td class="row-val">{{ $ropa['description'] ?? '-' }}</td></tr>
                </table>
            </div>

            <div class="section">
                <div class="section-head">
                    <table><tr>
                        <td class="num">II.</td>
                        <td class="title">Informasi Pemrosesan</td>
                    </tr></table>
                </div>
                <table class="row-table">
                    <tr><td class="row-key">Tujuan Pemrosesan</td><td class="row-val">{{ $ropa['purpose'] ?? '-' }}</td></tr>
                    <tr><td class="row-key">Aktivitas</td><td class="row-val">{{ $ropa['activity'] ?? '-' }}</td></tr>
                    <tr><td class="row-key">Dasar Hukum</td><td class="row-val">{{ $ropa['legal_basis'] ?? '-' }}</td></tr>
                    <tr><td class="row-key">Kategori Pemrosesan</td><td class="row-val">
                        @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                            <ul class="cat-list">
                                @foreach($ropa['categories'] as $cat)
                                    <li><span class="diamond"></span>{{ $cat }}</li>
                                @endforeach
                            </ul>
                        @else
                            -
                        @endif
                    </td></tr>
                </table>
            </div>
        </div>
        <div class="content-footer">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">Page 02 / 08</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
