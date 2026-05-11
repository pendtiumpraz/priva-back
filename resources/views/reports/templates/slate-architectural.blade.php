<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #1d2025; }

        /* ---------- COVER (Slate Architectural) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #e9eaec;
            color: #1d2025;
            page-break-after: always;
        }
        /* corner tick marks — drafting-board style */
        .tick-h, .tick-v { position: absolute; background: #1d2025; }
        .tick-h { width: 8mm; height: 0.35mm; }
        .tick-v { width: 0.35mm; height: 8mm; }
        .tick-tl-h { top: 14mm; left: 14mm; }
        .tick-tl-v { top: 14mm; left: 14mm; }
        .tick-tr-h { top: 14mm; right: 14mm; }
        .tick-tr-v { top: 14mm; right: 14mm; }
        .tick-bl-h { bottom: 14mm; left: 14mm; }
        .tick-bl-v { bottom: 14mm; left: 14mm; }
        .tick-br-h { bottom: 14mm; right: 14mm; }
        .tick-br-v { bottom: 14mm; right: 14mm; }

        .hair-frame {
            position: absolute;
            top: 20mm; right: 20mm; bottom: 20mm; left: 20mm;
            border: 0.3mm solid #b5b8bd;
        }
        .cover-inner {
            position: absolute;
            top: 28mm; right: 28mm; bottom: 28mm; left: 28mm;
        }
        .label-strip {
            border-bottom: 0.3mm solid #b5b8bd;
            padding-bottom: 4mm;
            font-size: 8pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #5a6068;
        }
        .label-strip table { width: 100%; border-collapse: collapse; }
        .label-strip td.c { text-align: center; }
        .label-strip td.r { text-align: right; }

        .cover-main { padding: 22mm 0; }
        .cover-title-eyebrow {
            font-size: 9pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #5a6068;
        }
        .cover-title {
            font-size: 56pt;
            font-weight: 300;
            letter-spacing: -1.5pt;
            line-height: 1.02;
            margin: 4mm 0 0;
            color: #1d2025;
        }
        .ref-line {
            margin-top: 10mm;
            font-size: 11pt;
            color: #5a6068;
        }
        .ref-line .ref-val {
            color: #1d2025;
            font-weight: 700;
            margin-left: 2mm;
        }
        .name-band {
            margin-top: 10mm;
            padding: 6mm 0;
            border-top: 0.3mm solid #b5b8bd;
            border-bottom: 0.3mm solid #b5b8bd;
            font-size: 12pt;
        }
        .name-band .org {
            display: block;
            margin-top: 1.5mm;
            color: #5a6068;
            font-size: 10.5pt;
        }

        /* title block at bottom — 4-col drafting table */
        .title-block {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            border: 0.4mm solid #1d2025;
        }
        .title-block table { width: 100%; border-collapse: collapse; }
        .title-block td {
            padding: 3mm 4mm;
            border-right: 0.4mm solid #1d2025;
            vertical-align: top;
            width: 25%;
        }
        .title-block td.last { border-right: none; }
        .tb-key {
            font-size: 7pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #5a6068;
        }
        .tb-val {
            font-size: 10pt;
            font-weight: 500;
            margin-top: 1mm;
            display: block;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f4f5f6;
            color: #1d2025;
        }
        .ct-head {
            padding: 12mm 18mm 5mm;
            border-bottom: 0.7mm solid #1d2025;
        }
        .ct-head table { width: 100%; border-collapse: collapse; }
        .ct-head .left { vertical-align: bottom; }
        .ct-head .right { text-align: right; vertical-align: bottom; }
        .sheet-label {
            font-size: 8pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: #5a6068;
        }
        .sheet-title {
            font-size: 22pt;
            font-weight: 300;
            letter-spacing: -0.5pt;
            margin: 2mm 0 0;
        }
        .sheet-title strong { font-weight: 500; }
        .ref-mini {
            font-size: 9pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: #5a6068;
        }

        /* drafting field grid */
        .field-grid {
            padding: 7mm 18mm 0;
        }
        .field-grid table {
            width: 100%;
            border-collapse: collapse;
            border-top: 0.3mm solid #d4d6da;
            border-left: 0.3mm solid #d4d6da;
        }
        .field-grid td {
            border-right: 0.3mm solid #d4d6da;
            border-bottom: 0.3mm solid #d4d6da;
            padding: 3mm 4mm;
            vertical-align: top;
        }
        .fg-key {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: #5a6068;
        }
        .fg-val {
            margin-top: 1.5mm;
            font-size: 10.5pt;
            line-height: 1.5;
            display: block;
        }

        .section-sep {
            margin: 5mm 18mm 0;
            border-top: 0.3mm solid #d4d6da;
        }
        .section-sep-strong {
            margin: 0 18mm;
            border-top: 0.7mm solid #1d2025;
        }
        .ct-h2 {
            padding: 4mm 18mm 4mm;
            font-size: 22pt;
            font-weight: 300;
            letter-spacing: -0.5pt;
            margin: 0;
        }
        .ct-h2 strong { font-weight: 500; }

        /* bottom title block on content too */
        .content-tb {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            border-top: 0.4mm solid #1d2025;
        }
        .content-tb table { width: 100%; border-collapse: collapse; }
        .content-tb td {
            padding: 3mm 4mm;
            border-right: 0.4mm solid #1d2025;
            vertical-align: top;
        }
        .content-tb td.last { border-right: none; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="tick-h tick-tl-h"></div><div class="tick-v tick-tl-v"></div>
        <div class="tick-h tick-tr-h"></div><div class="tick-v tick-tr-v"></div>
        <div class="tick-h tick-bl-h"></div><div class="tick-v tick-bl-v"></div>
        <div class="tick-h tick-br-h"></div><div class="tick-v tick-br-v"></div>

        <div class="hair-frame"></div>

        <div class="cover-inner">
            <div class="label-strip">
                <table><tr>
                    <td>Drawing 01 / ROPA Series</td>
                    <td class="c">Scale 1:1</td>
                    <td class="r">Sheet A</td>
                </tr></table>
            </div>

            <div class="cover-main">
                <div class="cover-title-eyebrow">Title</div>
                <h1 class="cover-title">Record of<br>Processing Activities</h1>
                <div class="ref-line">ref.<span class="ref-val">{{ $ropa['number'] ?? '' }}</span></div>
                <div class="name-band">
                    {{ $ropa['name'] ?? '' }}
                    <span class="org">{{ $ropa['org'] ?? ($orgName ?? '') }}</span>
                </div>
            </div>

            <div class="title-block">
                <table><tr>
                    <td>
                        <div class="tb-key">Project</div>
                        <span class="tb-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</span>
                    </td>
                    <td>
                        <div class="tb-key">Drawn</div>
                        <span class="tb-val">{{ $ropa['pic']['name'] ?? '-' }}</span>
                    </td>
                    <td>
                        <div class="tb-key">Date</div>
                        <span class="tb-val">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                    </td>
                    <td class="last">
                        <div class="tb-key">Sheet</div>
                        <span class="tb-val">A-01</span>
                    </td>
                </tr></table>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <table><tr>
                <td class="left">
                    <div class="sheet-label">Sheet A-02 &middot; Deskripsi Pemrosesan</div>
                    <div class="sheet-title">01 &middot; <strong>Deskripsi Pemrosesan</strong></div>
                </td>
                <td class="right">
                    <div class="ref-mini">{{ $ropa['number'] ?? '' }}</div>
                </td>
            </tr></table>
        </div>

        <div class="field-grid">
            <table>
                <tr>
                    <td style="width: 33.33%;">
                        <div class="fg-key">Nomor ROPA</div>
                        <span class="fg-val">{{ $ropa['number'] ?? '-' }}</span>
                    </td>
                    <td style="width: 33.33%;">
                        <div class="fg-key">Divisi</div>
                        <span class="fg-val">{{ $ropa['division'] ?? '-' }}</span>
                    </td>
                    <td style="width: 33.34%;">
                        <div class="fg-key">Unit Kerja</div>
                        <span class="fg-val">{{ $ropa['unit'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="width: 66.66%;">
                        <div class="fg-key">Nama Pemrosesan</div>
                        <span class="fg-val">{{ $ropa['name'] ?? '-' }}</span>
                    </td>
                    <td style="width: 33.34%;">
                        <div class="fg-key">Kategori</div>
                        <span class="fg-val">{{ $ropa['category'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        <div class="fg-key">Entitas</div>
                        <span class="fg-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        <div class="fg-key">Deskripsi Singkat</div>
                        <span class="fg-val">{{ $ropa['description'] ?? '-' }}</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section-sep"></div>
        <h2 class="ct-h2">02 &middot; <strong>Informasi Pemrosesan</strong></h2>
        <div class="section-sep-strong"></div>

        <div class="field-grid">
            <table>
                <tr>
                    <td colspan="2">
                        <div class="fg-key">Tujuan</div>
                        <span class="fg-val">{{ $ropa['purpose'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <div class="fg-key">Aktivitas</div>
                        <span class="fg-val">{{ $ropa['activity'] ?? '-' }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="width: 50%;">
                        <div class="fg-key">Dasar Hukum</div>
                        <span class="fg-val">{{ $ropa['legal_basis'] ?? '-' }}</span>
                    </td>
                    <td style="width: 50%;">
                        <div class="fg-key">Profiling</div>
                        <span class="fg-val">Penawaran Produk &middot; Personalisasi Konten</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <div class="fg-key">Kategori Pemrosesan</div>
                        <span class="fg-val">
                            @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                                {{ implode(' · ', $ropa['categories']) }}
                            @else
                                -
                            @endif
                        </span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="content-tb">
            <table><tr>
                <td style="width: 40%;">
                    <div class="tb-key">Project</div>
                    <span class="tb-val">{{ $ropa['org'] ?? ($orgName ?? '-') }}</span>
                </td>
                <td style="width: 20%;">
                    <div class="tb-key">Drawn</div>
                    <span class="tb-val">{{ $ropa['pic']['name'] ?? '-' }}</span>
                </td>
                <td style="width: 20%;">
                    <div class="tb-key">Date</div>
                    <span class="tb-val">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                </td>
                <td style="width: 20%;" class="last">
                    <div class="tb-key">Sheet</div>
                    <span class="tb-val">A-02</span>
                </td>
            </tr></table>
        </div>
    </div>
</body>
</html>
