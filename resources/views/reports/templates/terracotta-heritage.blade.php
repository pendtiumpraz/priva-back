<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #3a2418; }

        /* ---------- COVER (Terracotta Heritage) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f3e8d8;
            color: #3a2418;
            page-break-after: always;
            overflow: hidden;
        }

        /* huge border-radius arch unreliable in dompdf -> inline SVG */
        .arch {
            position: absolute;
            top: 0; left: 0;
            width: 21cm; height: 18cm;
        }

        .cover-head {
            position: absolute;
            top: 22mm; left: 22mm; right: 22mm;
            color: #f3e8d8;
        }
        .cover-head table { width: 100%; border-collapse: collapse; }
        .cover-head td {
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            font-weight: 600;
        }
        .cover-head .right { text-align: right; }
        .diamond {
            display: inline-block;
            width: 2.5mm; height: 2.5mm;
            background: #f3e8d8;
            transform: rotate(45deg);
            margin-right: 2mm;
            vertical-align: middle;
        }

        .cover-hero {
            position: absolute;
            top: 50mm; left: 22mm; right: 22mm;
            text-align: center;
            color: #f3e8d8;
        }
        .ropa-script {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 92pt;
            font-style: italic;
            line-height: 0.9;
            font-weight: 400;
            letter-spacing: -2pt;
        }
        .cover-hero .sub-rpa {
            font-size: 9pt;
            letter-spacing: 5pt;
            text-transform: uppercase;
            margin-top: 6mm;
        }
        .cover-hero .quote {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 18pt;
            max-width: 140mm;
            margin: 9mm auto 0;
            line-height: 1.35;
        }

        /* Stamp circle */
        .stamp {
            position: absolute;
            top: 158mm; left: 50%;
            margin-left: -22mm;
            width: 44mm; height: 44mm;
            border-radius: 50%;
            border: 2pt solid #b85b3c;
            background: #f3e8d8;
            color: #b85b3c;
            text-align: center;
            padding-top: 7mm;
        }
        .stamp .num-big {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 22pt;
            font-style: italic;
            line-height: 1;
        }
        .stamp .num-full {
            font-size: 6.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            margin-top: 2mm;
        }
        .stamp .rule {
            width: 9mm; height: 1px;
            background: #b85b3c;
            margin: 2mm auto;
        }
        .stamp .sealed {
            font-size: 6.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
        }

        /* Bottom info */
        .cover-foot {
            position: absolute;
            bottom: 22mm; left: 22mm; right: 22mm;
        }
        .cover-foot .by {
            text-align: center;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 14pt;
            color: #7a4f1d;
        }
        .cover-foot .org {
            text-align: center;
            font-size: 14pt;
            font-weight: 600;
            margin-top: 2mm;
        }
        .cover-foot .grid {
            margin-top: 10mm;
            border-top: 1px solid #b85b3c;
            padding-top: 5mm;
        }
        .cover-foot .grid table { width: 100%; border-collapse: collapse; }
        .cover-foot .grid td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
        }
        .cover-foot .grid .k {
            font-size: 7.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #b85b3c;
            font-weight: 600;
        }
        .cover-foot .grid .v {
            margin-top: 1.5mm;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f3e8d8;
            color: #3a2418;
        }
        .ct-head {
            padding: 16mm 20mm 8mm;
            border-bottom: 2pt solid #b85b3c;
        }
        .ct-head table { width: 100%; border-collapse: collapse; }
        .ct-head .title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 27pt;
            font-style: italic;
            letter-spacing: -0.4pt;
            vertical-align: baseline;
        }
        .ct-head .ref {
            text-align: right;
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #b85b3c;
            font-weight: 600;
            vertical-align: baseline;
        }

        .field-rows {
            padding: 10mm 20mm 0;
        }
        .field-rows table { width: 100%; border-collapse: collapse; }
        .field-rows tr td {
            padding: 3.5mm 0;
            border-bottom: 1px dashed #c89878;
            vertical-align: top;
        }
        .fld-k {
            width: 68mm;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 12pt;
            color: #7a4f1d;
        }
        .fld-v {
            font-size: 11pt;
            line-height: 1.5;
        }

        .desc-block {
            margin: 6mm 20mm 0;
            padding: 6mm 7mm;
            background: #ead7bc;
            border-left: 4pt solid #b85b3c;
        }
        .desc-block .k {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 12pt;
            color: #7a4f1d;
            margin-bottom: 2mm;
        }
        .desc-block .v {
            font-size: 11pt;
            line-height: 1.6;
        }

        .ct-h2 {
            margin: 10mm 20mm 6mm;
            padding-bottom: 3mm;
            border-bottom: 2pt solid #b85b3c;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 22pt;
            font-style: italic;
            letter-spacing: -0.3pt;
        }

        .info-grid {
            padding: 0 20mm;
        }
        .info-grid table { width: 100%; border-collapse: separate; border-spacing: 6mm 0; }
        .info-grid td {
            width: 50%;
            vertical-align: top;
        }
        .info-grid .k {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 12pt;
            color: #7a4f1d;
        }
        .info-grid .v {
            margin-top: 1.5mm;
            font-size: 11pt;
            line-height: 1.5;
        }

        .cat-block {
            padding: 7mm 20mm 0;
        }
        .cat-block .cat-head {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 12pt;
            color: #7a4f1d;
            margin-bottom: 3mm;
        }
        .cat-row {
            padding: 1.5mm 0;
        }
        .cat-row .roman {
            display: inline-block;
            width: 8mm;
            color: #b85b3c;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            vertical-align: top;
        }
        .cat-row .txt {
            display: inline-block;
            font-size: 11pt;
            vertical-align: top;
        }

        .ct-foot {
            position: absolute;
            bottom: 12mm; left: 20mm; right: 20mm;
            text-align: center;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            color: #b85b3c;
        }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        {{-- arch shape: huge border-radius unreliable in dompdf, use SVG path --}}
        <svg class="arch" viewBox="0 0 210 180" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M 0 0 L 210 0 L 210 100 Q 210 180 105 180 Q 0 180 0 100 Z" fill="#b85b3c"/>
        </svg>

        <div class="cover-head">
            <table><tr>
                <td>Anno &middot; {{ date('Y') }}</td>
                <td class="right"><span class="diamond"></span>Ropa Export</td>
            </tr></table>
        </div>

        <div class="cover-hero">
            <div class="ropa-script">Ropa</div>
            <div class="sub-rpa">Record of Processing Activities</div>
            <div class="quote">&ldquo;{{ $ropa['name'] ?? '-' }}&rdquo;</div>
        </div>

        <div class="stamp">
            @php
                $numParts = explode('-', $ropa['number'] ?? '');
                $stampNum = end($numParts) ?: '00';
            @endphp
            <div class="num-big">{{ $stampNum }}</div>
            <div class="num-full">{{ $ropa['number'] ?? '' }}</div>
            <div class="rule"></div>
            <div class="sealed">Sealed</div>
        </div>

        <div class="cover-foot">
            <div class="by">Disusun oleh</div>
            <div class="org">{{ $ropa['org'] ?? ($orgName ?? '-') }}</div>
            <div class="grid">
                <table><tr>
                    <td>
                        <div class="k">Divisi</div>
                        <span class="v">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="k">Tanggal</div>
                        <span class="v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                    <td>
                        <div class="k">Dasar</div>
                        <span class="v">{{ $ropa['legal_basis'] ?? '-' }}</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <table><tr>
                <td class="title">I. Deskripsi Pemrosesan</td>
                <td class="ref">{{ $ropa['number'] ?? '' }}</td>
            </tr></table>
        </div>

        <div class="field-rows">
            <table>
                <tr>
                    <td class="fld-k">Nomor ROPA</td>
                    <td class="fld-v">{{ $ropa['number'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="fld-k">Nama Pemrosesan</td>
                    <td class="fld-v">{{ $ropa['name'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="fld-k">Divisi &middot; Unit Kerja</td>
                    <td class="fld-v">{{ $ropa['division'] ?? '-' }} &middot; {{ $ropa['unit'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="fld-k">Entitas</td>
                    <td class="fld-v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td>
                </tr>
            </table>
        </div>

        <div class="desc-block">
            <div class="k">Deskripsi Singkat</div>
            <div class="v">{{ $ropa['description'] ?? '-' }}</div>
        </div>

        <h2 class="ct-h2">II. Informasi Pemrosesan</h2>

        <div class="info-grid">
            <table><tr>
                <td>
                    <div class="k">Tujuan</div>
                    <div class="v">{{ $ropa['purpose'] ?? '-' }}</div>
                </td>
                <td>
                    <div class="k">Dasar Hukum</div>
                    <div class="v">{{ $ropa['legal_basis'] ?? '-' }}</div>
                </td>
            </tr></table>
        </div>

        <div class="cat-block">
            <div class="cat-head">Kategori Pemrosesan</div>
            @php
                $roman = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];
            @endphp
            @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                @foreach($ropa['categories'] as $i => $cat)
                    <div class="cat-row">
                        <span class="roman">{{ $roman[$i] ?? ($i + 1) }}.</span>
                        <span class="txt">{{ $cat }}</span>
                    </div>
                @endforeach
            @else
                <div class="cat-row">
                    <span class="roman">i.</span>
                    <span class="txt">-</span>
                </div>
            @endif
        </div>

        <div class="ct-foot">
            ~ {{ $ropa['org'] ?? ($orgName ?? '') }} &middot; {{ $ropa['number'] ?? '' }} &middot; 02 ~
        </div>
    </div>
</body>
</html>
