<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #2a3528; }

        /* ---------- COVER (Sage Corporate) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f0ede2;
            color: #2a3528;
            page-break-after: always;
        }
        /* dompdf doesn't support writing-mode vertical reliably -> use a left band with stacked R/O/P/A */
        .left-band {
            position: absolute;
            top: 0; bottom: 0; left: 0;
            width: 74mm;
            background: #4a5b3e;
            color: #f0ede2;
            padding: 22mm 12mm;
        }
        .lb-top-label {
            font-size: 7.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #c7d1bd;
            margin-bottom: 18mm;
        }
        .stack-letters {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 48pt;
            line-height: 1;
            font-weight: 300;
            letter-spacing: -1pt;
            color: #f0ede2;
        }
        .lb-bottom {
            position: absolute;
            bottom: 22mm; left: 12mm; right: 12mm;
        }
        .lb-rule {
            width: 14mm; height: 1px;
            background: #c7d1bd;
            margin-bottom: 4mm;
        }
        .lb-mini-label {
            font-size: 7.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #c7d1bd;
        }
        .lb-mini-val {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            margin-top: 1.5mm;
            color: #f0ede2;
        }

        /* right column */
        .right-col {
            position: absolute;
            top: 0; bottom: 0;
            left: 74mm; right: 0;
            padding: 28mm 20mm;
        }
        .rc-pretitle {
            font-size: 7.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #7a8a6e;
            font-weight: 700;
        }
        .rc-title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 44pt;
            line-height: 1.05;
            margin: 8mm 0 0;
            font-weight: 400;
            letter-spacing: -0.6pt;
            color: #2a3528;
        }
        .rc-rule {
            width: 28mm; height: 0.7mm;
            background: #4a5b3e;
            margin: 10mm 0;
        }
        .rc-name {
            font-size: 12pt;
            line-height: 1.6;
            color: #4a5b3e;
            max-width: 130mm;
        }
        .rc-org {
            margin-top: 3mm;
            font-size: 10pt;
            color: #7a8a6e;
        }
        .rc-meta {
            position: absolute;
            bottom: 28mm; left: 20mm; right: 20mm;
            border-top: 1px solid #4a5b3e;
            padding-top: 6mm;
        }
        .rc-meta table { width: 100%; border-collapse: collapse; }
        .rc-meta td { width: 50%; vertical-align: top; padding-right: 6mm; padding-bottom: 5mm; }
        .rc-meta .k {
            font-size: 7.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #7a8a6e;
            font-weight: 700;
        }
        .rc-meta .v {
            margin-top: 1.5mm;
            font-size: 11pt;
            font-weight: 500;
            color: #2a3528;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f8f6ee;
            color: #2a3528;
        }
        .sec-head {
            background: #4a5b3e;
            color: #f0ede2;
            padding: 6mm 20mm;
        }
        .sec-head table { width: 100%; border-collapse: collapse; }
        .sec-head .h-title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 17pt;
            font-style: italic;
            color: #f0ede2;
        }
        .sec-head .h-ref {
            text-align: right;
            font-size: 7.5pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #c7d1bd;
        }
        .sec-head.muted {
            background: #e6e3d4;
            color: #2a3528;
            margin-top: 3mm;
        }
        .sec-head.muted .h-title { color: #2a3528; }

        .sec-body { padding: 10mm 20mm; }
        .row-table { width: 100%; border-collapse: collapse; font-size: 10pt; }
        .row-table tr td {
            padding: 3.5mm 5mm 3.5mm 0;
            border-bottom: 1px solid #d8d4c2;
            vertical-align: top;
        }
        .row-key {
            width: 60mm;
            font-size: 8pt;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            color: #7a8a6e;
            padding-top: 1mm !important;
        }
        .row-val { font-size: 10pt; line-height: 1.55; color: #2a3528; }

        .info-grid table { width: 100%; border-collapse: collapse; }
        .info-grid td { width: 50%; vertical-align: top; padding: 0 8mm 0 0; }
        .info-grid td.right { padding: 0 0 0 8mm; }
        .ig-label {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #7a8a6e;
            margin-bottom: 3mm;
            display: block;
        }
        .ig-val { font-size: 10.5pt; line-height: 1.55; color: #2a3528; }
        .ig-val.spaced { margin-top: 6mm; display: block; }
        .cat-list { margin: 0; padding-left: 5mm; font-size: 10pt; line-height: 1.7; }

        .content-foot {
            position: absolute;
            bottom: 12mm; left: 20mm; right: 20mm;
            border-top: 1px solid #d8d4c2;
            padding-top: 4mm;
            font-size: 8pt;
            color: #7a8a6e;
        }
        .content-foot table { width: 100%; border-collapse: collapse; }
        .content-foot .right { text-align: right; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="left-band">
            <div class="lb-top-label">Confidential &middot; Internal</div>
            <div class="stack-letters">R<br>O<br>P<br>A</div>
            <div class="lb-bottom">
                <div class="lb-rule"></div>
                <div class="lb-mini-label">Sertifikasi PDP</div>
                <div class="lb-mini-val">UU PDP 27/2022</div>
            </div>
        </div>
        <div class="right-col">
            <div class="rc-pretitle">Record of Processing</div>
            <h1 class="rc-title">Aktivitas<br>Pemrosesan<br>Data Pribadi</h1>
            <div class="rc-rule"></div>
            <div class="rc-name">{{ $ropa['name'] ?? '-' }}</div>
            <div class="rc-org">{{ $ropa['org'] ?? ($orgName ?? '-') }}</div>
            <div class="rc-meta">
                <table>
                    <tr>
                        <td><div class="k">Nomor Dokumen</div><span class="v">{{ $ropa['number'] ?? '-' }}</span></td>
                        <td><div class="k">Tanggal Berlaku</div><span class="v">{{ $ropa['date'] ?? ($today ?? '-') }}</span></td>
                    </tr>
                    <tr>
                        <td><div class="k">Divisi</div><span class="v">{{ $ropa['division'] ?? '-' }}</span></td>
                        <td><div class="k">Kategori</div><span class="v">{{ $ropa['category'] ?? '-' }}</span></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="sec-head">
            <table><tr>
                <td class="h-title">Deskripsi Pemrosesan</td>
                <td class="h-ref">&sect; 01</td>
            </tr></table>
        </div>
        <div class="sec-body">
            <table class="row-table">
                <tr><td class="row-key">Nomor ROPA</td><td class="row-val">{{ $ropa['number'] ?? '-' }}</td></tr>
                <tr><td class="row-key">Nama Pemrosesan</td><td class="row-val">{{ $ropa['name'] ?? '-' }}</td></tr>
                <tr><td class="row-key">Divisi</td><td class="row-val">{{ $ropa['division'] ?? '-' }}</td></tr>
                <tr><td class="row-key">Unit Kerja</td><td class="row-val">{{ $ropa['unit'] ?? '-' }}</td></tr>
                <tr><td class="row-key">Entitas</td><td class="row-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr>
                <tr><td class="row-key">Deskripsi</td><td class="row-val">{{ $ropa['description'] ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="sec-head muted">
            <table><tr>
                <td class="h-title">Informasi Pemrosesan</td>
                <td class="h-ref">&sect; 02</td>
            </tr></table>
        </div>
        <div class="sec-body info-grid">
            <table><tr>
                <td>
                    <span class="ig-label">Tujuan</span>
                    <div class="ig-val">{{ $ropa['purpose'] ?? '-' }}</div>
                    <span class="ig-label" style="margin-top: 6mm;">Dasar Hukum</span>
                    <div class="ig-val">{{ $ropa['legal_basis'] ?? '-' }}</div>
                </td>
                <td class="right">
                    <span class="ig-label">Kategori Pemrosesan</span>
                    @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                        <ul class="cat-list">
                            @foreach($ropa['categories'] as $cat)
                                <li>{{ $cat }}</li>
                            @endforeach
                        </ul>
                    @else
                        <div class="ig-val">-</div>
                    @endif
                </td>
            </tr></table>
        </div>

        <div class="content-foot">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">{{ $ropa['number'] ?? '' }} &middot; 02/08</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
