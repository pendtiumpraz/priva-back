<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #0a0a0a; }

        /* ---------- COVER (Pure Minimal) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #fbfaf8;
            color: #0a0a0a;
            page-break-after: always;
        }
        .top-strip {
            padding: 14mm 22mm 0;
        }
        .top-strip table { width: 100%; border-collapse: collapse; }
        .top-strip td {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #9b9b96;
        }
        .top-strip .right { text-align: right; }

        .mega-r {
            padding: 0 22mm;
            margin-top: 18mm;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 220pt;
            font-weight: 300;
            line-height: 0.9;
            letter-spacing: -8pt;
            color: #0a0a0a;
        }
        .mega-r .dot { color: #c9b27a; }
        .ropa-eyebrow {
            padding: 0 22mm;
            margin-top: 4mm;
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #5a5a55;
        }

        .mid-rule {
            position: absolute;
            top: 175mm; left: 22mm; right: 22mm;
            height: 1px;
            background: #0a0a0a;
        }

        .name-block {
            position: absolute;
            top: 187mm; left: 22mm; right: 22mm;
        }
        .name-block h1 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 30pt;
            font-weight: 300;
            line-height: 1.15;
            letter-spacing: -0.6pt;
            margin: 0;
            max-width: 160mm;
        }
        .name-block .name-org {
            margin-top: 4mm;
            font-size: 12pt;
            color: #5a5a55;
        }

        .bottom-grid {
            position: absolute;
            bottom: 18mm; left: 22mm; right: 22mm;
            border-top: 1px solid #0a0a0a;
        }
        .bottom-grid table { width: 100%; border-collapse: collapse; }
        .bottom-grid td {
            width: 25%;
            vertical-align: top;
            padding: 6mm 6mm 0 0;
        }
        .bg-label {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #9b9b96;
        }
        .bg-val {
            margin-top: 3mm;
            font-size: 13pt;
            font-weight: 400;
            letter-spacing: -0.2pt;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #fbfaf8;
            color: #0a0a0a;
            padding: 14mm 22mm;
        }
        .ct-top {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #9b9b96;
        }
        .ct-top table { width: 100%; border-collapse: collapse; }
        .ct-top .right { text-align: right; }

        .ct-section-head {
            margin-top: 18mm;
        }
        .ct-section-head table { width: 100%; border-collapse: collapse; }
        .ct-section-head .num {
            width: 18mm;
            font-size: 10pt;
            letter-spacing: 2.4pt;
            color: #c9b27a;
            vertical-align: baseline;
            padding-top: 3mm;
        }
        .ct-section-head .title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 22pt;
            font-weight: 400;
            letter-spacing: -0.6pt;
            vertical-align: baseline;
        }

        .field-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6mm;
        }
        .field-table tr td {
            padding: 4mm 0;
            border-bottom: 1px solid #ececea;
            vertical-align: top;
        }
        .fld-key {
            width: 52mm;
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #9b9b96;
            padding-top: 1mm !important;
        }
        .fld-val {
            font-size: 11pt;
            line-height: 1.55;
        }

        .ct-bottom {
            position: absolute;
            bottom: 11mm; left: 22mm; right: 22mm;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #9b9b96;
        }
        .ct-bottom table { width: 100%; border-collapse: collapse; }
        .ct-bottom .right { text-align: right; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="top-strip">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">{{ $ropa['number'] ?? '' }}</td>
            </tr></table>
        </div>
        <div class="mega-r">R<span class="dot">&middot;</span></div>
        <div class="ropa-eyebrow">Record of Processing Activities</div>

        <div class="mid-rule"></div>
        <div class="name-block">
            <h1>{{ $ropa['name'] ?? '-' }}</h1>
            <div class="name-org">{{ $ropa['org'] ?? ($orgName ?? '') }}</div>
        </div>

        <div class="bottom-grid">
            <table><tr>
                <td>
                    <div class="bg-label">Document</div>
                    <span class="bg-val">{{ $ropa['number'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="bg-label">Effective</div>
                    <span class="bg-val">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                </td>
                <td>
                    <div class="bg-label">Division</div>
                    <span class="bg-val">{{ $ropa['division'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="bg-label">Status</div>
                    <span class="bg-val">Active</span>
                </td>
            </tr></table>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-top">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">02 / 08</td>
            </tr></table>
        </div>

        <div class="ct-section-head">
            <table><tr>
                <td class="num">01</td>
                <td class="title">Deskripsi Pemrosesan</td>
            </tr></table>
        </div>
        <table class="field-table">
            <tr><td class="fld-key">Nomor ROPA</td><td class="fld-val">{{ $ropa['number'] ?? '-' }}</td></tr>
            <tr><td class="fld-key">Nama Pemrosesan</td><td class="fld-val">{{ $ropa['name'] ?? '-' }}</td></tr>
            <tr><td class="fld-key">Divisi</td><td class="fld-val">{{ $ropa['division'] ?? '-' }}</td></tr>
            <tr><td class="fld-key">Unit Kerja</td><td class="fld-val">{{ $ropa['unit'] ?? '-' }}</td></tr>
            <tr><td class="fld-key">Entitas</td><td class="fld-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr>
            <tr><td class="fld-key">Deskripsi Singkat</td><td class="fld-val">{{ $ropa['description'] ?? '-' }}</td></tr>
        </table>

        <div class="ct-section-head">
            <table><tr>
                <td class="num">02</td>
                <td class="title">Informasi Pemrosesan</td>
            </tr></table>
        </div>
        <table class="field-table">
            <tr><td class="fld-key">Tujuan</td><td class="fld-val">{{ $ropa['purpose'] ?? '-' }}</td></tr>
            <tr><td class="fld-key">Dasar Hukum</td><td class="fld-val">{{ $ropa['legal_basis'] ?? '-' }}</td></tr>
            <tr><td class="fld-key">Kategori</td><td class="fld-val">
                @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                    {{ implode(' · ', $ropa['categories']) }}
                @else
                    -
                @endif
            </td></tr>
        </table>

        <div class="ct-bottom">
            <table><tr>
                <td>Confidential</td>
                <td class="right">{{ $ropa['number'] ?? '' }}</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
