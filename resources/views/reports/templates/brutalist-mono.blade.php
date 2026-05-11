<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans Mono', 'Courier', monospace; color: #000; }

        /* ---------- COVER (Brutalist Mono) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #e8e8e8;
            color: #000;
            padding: 5mm;
            page-break-after: always;
        }
        .box {
            border: 2mm solid #000;
            margin-bottom: 5mm;
        }
        .box-pad-sm {
            padding: 7mm 8mm;
        }
        .box-pad-md {
            padding: 13mm 8mm;
        }
        .box-pad-rd {
            padding: 6mm 7mm;
        }
        .topbox table { width: 100%; border-collapse: collapse; }
        .topbox td { vertical-align: middle; font-weight: 700; font-size: 10pt; text-transform: uppercase; }
        .topbox td.right { text-align: right; font-weight: 400; }

        .headline-box {
            background: #000;
            color: #fff;
        }
        .hl-label {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #aaa;
        }
        .hl-title {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 60pt;
            font-weight: 900;
            line-height: 0.9;
            letter-spacing: -2pt;
            margin: 4mm 0 0;
            text-transform: uppercase;
            color: #fff;
        }

        /* dompdf grid -> table */
        .row-2col table { width: 100%; border-collapse: collapse; }
        .row-2col td { vertical-align: top; padding: 0; }
        .row-2col td.cell-l { width: 66%; padding-right: 2.5mm; }
        .row-2col td.cell-r { width: 34%; padding-left: 2.5mm; }
        .cell-box {
            border: 2mm solid #000;
            padding: 6mm 7mm;
            background: #fff;
        }
        .cell-box.yellow { background: #ffeb3b; }
        .cb-key {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 700;
        }
        .cb-name {
            margin-top: 3mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 12pt;
            font-weight: 700;
        }
        .cb-sub {
            margin-top: 1.5mm;
            font-size: 10pt;
        }
        .cb-ref {
            margin-top: 3mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 16pt;
            font-weight: 900;
        }

        .strip { padding: 5mm 7mm; }
        .strip table { width: 100%; border-collapse: collapse; }
        .strip td {
            font-size: 9pt;
            text-transform: uppercase;
        }
        .strip td.right { text-align: right; }

        .note-box .note-head {
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 3mm;
            font-size: 10pt;
        }
        .note-body {
            font-size: 9pt;
            line-height: 1.5;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #e8e8e8;
            color: #000;
            padding: 5mm;
            font-family: 'DejaVu Sans Mono', 'Courier', monospace;
        }
        .blk-head {
            border: 2mm solid #000;
            background: #000;
            color: #fff;
            padding: 6mm 8mm;
            margin-bottom: 5mm;
        }
        .blk-head table { width: 100%; border-collapse: collapse; }
        .blk-head td { color: #fff; }
        .blk-head td.title {
            font-weight: 700;
            font-size: 13pt;
        }
        .blk-head td.right {
            text-align: right;
            font-size: 10pt;
        }

        /* rows: yellow label + content cell, 3pt black inter-row separators */
        .rowblk {
            border: 2mm solid #000;
            border-top: none;
            background: #fff;
            margin-bottom: 5mm;
        }
        .rowblk table { width: 100%; border-collapse: collapse; }
        .rowblk tr td {
            vertical-align: top;
        }
        .rowblk tr + tr td {
            border-top: 1mm solid #000;
        }
        .rowblk td.k {
            width: 50mm;
            background: #ffeb3b;
            border-right: 1mm solid #000;
            padding: 4mm 5mm;
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .rowblk td.v {
            padding: 4mm 5mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 11pt;
            font-weight: 500;
        }

        .info-box {
            border: 2mm solid #000;
            padding: 7mm 8mm;
            background: #fff;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
        }
        .ib-label {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .ib-val {
            margin-top: 1.5mm;
            font-size: 12pt;
            font-weight: 500;
        }
        .ib-val.spaced { margin-top: 5mm; }
        .cat-tag {
            display: inline-block;
            padding: 1.5mm 3.5mm;
            background: #000;
            color: #fff;
            font-size: 9pt;
            font-weight: 600;
            font-family: 'DejaVu Sans Mono', monospace;
            margin: 0 1.5mm 1.5mm 0;
        }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="box topbox box-pad-sm">
            <table><tr>
                <td>[ROPA-EXPORT-v1]</td>
                <td class="right">{{ $ropa['date'] ?? ($today ?? '') }}</td>
            </tr></table>
        </div>

        <div class="box headline-box box-pad-md">
            <div class="hl-label">// HEADLINE</div>
            <h1 class="hl-title">RECORD OF<br>PROCESSING<br>ACTIVITIES</h1>
        </div>

        <div class="row-2col">
            <table><tr>
                <td class="cell-l">
                    <div class="cell-box">
                        <div class="cb-key">FOR:</div>
                        <div class="cb-name">{{ $ropa['name'] ?? '-' }}</div>
                        <div class="cb-sub">{{ $ropa['org'] ?? ($orgName ?? '-') }}</div>
                    </div>
                </td>
                <td class="cell-r">
                    <div class="cell-box yellow">
                        <div class="cb-key">REF:</div>
                        <div class="cb-ref">{{ $ropa['number'] ?? '-' }}</div>
                    </div>
                </td>
            </tr></table>
        </div>

        <div class="box strip" style="margin-top: 5mm;">
            <table><tr>
                <td>DIV: {{ $ropa['division'] ?? '-' }}</td>
                <td class="right">UNIT: {{ $ropa['unit'] ?? '-' }}</td>
            </tr></table>
        </div>

        <div class="box note-box box-pad-rd" style="background: #fff;">
            <div class="note-head">&gt;&gt; NOTE</div>
            <div class="note-body">{{ $ropa['description'] ?? '-' }}</div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="blk-head">
            <table><tr>
                <td class="title">&gt;&gt; SEC.01 / DESKRIPSI</td>
                <td class="right">p.02</td>
            </tr></table>
        </div>

        <div class="rowblk">
            <table>
                <tr>
                    <td class="k">NO_ROPA</td>
                    <td class="v">{{ $ropa['number'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="k">NAME</td>
                    <td class="v">{{ $ropa['name'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="k">DIVISI</td>
                    <td class="v">{{ $ropa['division'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="k">UNIT</td>
                    <td class="v">{{ $ropa['unit'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="k">ENTITAS</td>
                    <td class="v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td>
                </tr>
            </table>
        </div>

        <div class="blk-head">
            <table><tr>
                <td class="title">&gt;&gt; SEC.02 / INFORMASI</td>
                <td class="right">{{ $ropa['legal_basis'] ?? '-' }}</td>
            </tr></table>
        </div>

        <div class="info-box">
            <div class="ib-label">TUJUAN:</div>
            <div class="ib-val">{{ $ropa['purpose'] ?? '-' }}</div>
            <div class="ib-label spaced">KATEGORI:</div>
            <div class="ib-val">
                @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                    @foreach($ropa['categories'] as $cat)
                        <span class="cat-tag">[{{ $cat }}]</span>
                    @endforeach
                @else
                    -
                @endif
            </div>
        </div>
    </div>
</body>
</html>
