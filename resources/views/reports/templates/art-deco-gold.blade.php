<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Serif', 'Times New Roman', serif; color: #e8d9a7; }

        /* ---------- COVER (Art Deco Gold) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #0c0c0c;
            color: #e8d9a7;
            page-break-after: always;
        }
        /* Nested gold frames (inset expanded explicitly for dompdf) */
        .frame-outer {
            position: absolute;
            top: 11mm; right: 11mm; bottom: 11mm; left: 11mm;
            border: 2pt solid #d4af37;
        }
        .frame-inner {
            position: absolute;
            top: 14mm; right: 14mm; bottom: 14mm; left: 14mm;
            border: 1pt solid #6c5a1e;
        }
        /* Corner fans (SVG, rotated) */
        .fan {
            position: absolute;
            width: 36mm; height: 36mm;
        }
        .fan-tl { top: 14mm; left: 14mm; }
        .fan-tr { top: 14mm; right: 14mm; transform: rotate(90deg); transform-origin: top right; }
        .fan-bl { bottom: 14mm; left: 14mm; transform: rotate(-90deg); transform-origin: bottom left; }
        .fan-br { bottom: 14mm; right: 14mm; transform: rotate(180deg); transform-origin: bottom right; }

        .cover-inner {
            position: absolute;
            top: 35mm; left: 28mm; right: 28mm; bottom: 35mm;
            text-align: center;
        }
        .cv-top, .cv-mid, .cv-bot { text-align: center; }
        .cv-mid { margin-top: 12mm; }
        .cv-bot { margin-top: 14mm; }

        .ornament {
            text-align: center;
            margin: 4mm 0;
        }
        .anno {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            margin-top: 5mm;
            font-size: 8.5pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #d4af37;
        }
        .pretitle {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 5pt;
            color: #d4af37;
            font-weight: 700;
        }
        .big-processing {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 92pt;
            font-weight: 400;
            line-height: 0.95;
            font-style: italic;
            letter-spacing: -1pt;
            margin: 5mm 0;
            color: #e8d9a7;
        }
        .name-quote {
            margin-top: 9mm;
            font-size: 17pt;
            font-style: italic;
            color: #e8d9a7;
        }
        .org-name {
            margin-top: 6mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #d4af37;
        }
        .meta-row {
            margin-top: 8mm;
        }
        .meta-row table {
            margin: 0 auto;
            border-collapse: collapse;
        }
        .meta-row td {
            padding: 0 6mm;
            text-align: center;
            vertical-align: top;
        }
        .mr-k {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 7.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #d4af37;
        }
        .mr-v {
            margin-top: 2mm;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 13pt;
            font-style: italic;
            display: block;
            color: #e8d9a7;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #faf4e0;
            color: #1a1a1a;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
        }
        .sec-band {
            background: #0c0c0c;
            color: #d4af37;
            padding: 6mm 20mm;
            text-align: center;
        }
        .sec-band .sb-eyebrow {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 5pt;
            text-transform: uppercase;
            font-weight: 700;
            color: #d4af37;
        }
        .sec-band .sb-title {
            margin-top: 1.5mm;
            font-size: 22pt;
            font-style: italic;
            color: #e8d9a7;
        }

        .sec-body { padding: 10mm 20mm 0; }
        .row-frame {
            border-top: 1mm solid #d4af37;
            border-bottom: 1mm solid #d4af37;
            padding: 6mm 0;
            max-width: 165mm;
            margin: 0 auto;
        }
        .row-frame table { width: 100%; border-collapse: collapse; }
        .row-frame tr td {
            padding: 3mm 5mm;
            vertical-align: top;
            border-bottom: 0.5pt solid #d4af37;
        }
        .row-frame tr:last-child td { border-bottom: none; }
        .row-frame td.k {
            width: 55mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #8a6f1d;
            font-weight: 700;
            padding-top: 4mm !important;
        }
        .row-frame td.v {
            font-size: 13pt;
            font-style: italic;
            color: #1a1a1a;
        }

        .desc-box {
            margin: 8mm auto 0;
            padding: 6mm 8mm;
            background: #0c0c0c;
            color: #e8d9a7;
            text-align: center;
            border: 1px solid #d4af37;
            max-width: 165mm;
        }
        .desc-box .db-key {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: #d4af37;
            font-weight: 700;
        }
        .desc-box .db-val {
            margin-top: 3mm;
            font-size: 13pt;
            font-style: italic;
            line-height: 1.55;
        }

        .twocol { padding: 6mm 20mm 0; }
        .twocol table { width: 100%; border-collapse: collapse; }
        .twocol td {
            width: 50%;
            vertical-align: top;
            padding-right: 8mm;
        }
        .twocol td.right { padding-right: 0; padding-left: 8mm; }
        .tc-k {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #8a6f1d;
            font-weight: 700;
        }
        .tc-v {
            margin-top: 3mm;
            font-size: 13pt;
            font-style: italic;
            color: #1a1a1a;
        }

        .ct-foot {
            position: absolute;
            bottom: 10mm; left: 0; right: 0;
            text-align: center;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 4pt;
            color: #8a6f1d;
        }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="frame-outer"></div>
        <div class="frame-inner"></div>

        {{-- Corner fans (inline SVG, rotated via container) --}}
        <div class="fan fan-tl">
            <svg width="100%" height="100%" viewBox="0 0 100 100">
                <path d="M0 0 L100 0 L0 100 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L80 0 L0 80 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L60 0 L0 60 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L40 0 L0 40 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
            </svg>
        </div>
        <div class="fan fan-tr">
            <svg width="100%" height="100%" viewBox="0 0 100 100">
                <path d="M0 0 L100 0 L0 100 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L80 0 L0 80 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L60 0 L0 60 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L40 0 L0 40 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
            </svg>
        </div>
        <div class="fan fan-bl">
            <svg width="100%" height="100%" viewBox="0 0 100 100">
                <path d="M0 0 L100 0 L0 100 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L80 0 L0 80 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L60 0 L0 60 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L40 0 L0 40 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
            </svg>
        </div>
        <div class="fan fan-br">
            <svg width="100%" height="100%" viewBox="0 0 100 100">
                <path d="M0 0 L100 0 L0 100 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L80 0 L0 80 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L60 0 L0 60 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
                <path d="M0 0 L40 0 L0 40 Z" fill="none" stroke="#d4af37" stroke-width="0.5" />
            </svg>
        </div>

        <div class="cover-inner">
            <div class="cv-top">
                <div class="ornament">
                    <svg width="80" height="20" viewBox="0 0 80 40">
                        <path d="M40 4 L46 18 L60 20 L46 22 L40 36 L34 22 L20 20 L34 18 Z" fill="#d4af37" />
                        <line x1="0" y1="20" x2="18" y2="20" stroke="#d4af37" stroke-width="1" />
                        <line x1="62" y1="20" x2="80" y2="20" stroke="#d4af37" stroke-width="1" />
                    </svg>
                </div>
                <div class="anno">Anno Domini &middot; 2026</div>
            </div>

            <div class="cv-mid">
                <div class="pretitle">RECORD OF</div>
                <h1 class="big-processing">Processing</h1>
                <div class="pretitle">ACTIVITIES</div>
                <div class="ornament">
                    <svg width="80" height="20" viewBox="0 0 80 40">
                        <path d="M40 4 L46 18 L60 20 L46 22 L40 36 L34 22 L20 20 L34 18 Z" fill="#d4af37" />
                        <line x1="0" y1="20" x2="18" y2="20" stroke="#d4af37" stroke-width="1" />
                        <line x1="62" y1="20" x2="80" y2="20" stroke="#d4af37" stroke-width="1" />
                    </svg>
                </div>
                <div class="name-quote">&ldquo;{{ $ropa['name'] ?? '' }}&rdquo;</div>
                <div class="org-name">{{ $ropa['org'] ?? ($orgName ?? '') }}</div>
            </div>

            <div class="cv-bot">
                <div class="ornament">
                    <svg width="80" height="20" viewBox="0 0 80 40">
                        <path d="M40 4 L46 18 L60 20 L46 22 L40 36 L34 22 L20 20 L34 18 Z" fill="#d4af37" />
                        <line x1="0" y1="20" x2="18" y2="20" stroke="#d4af37" stroke-width="1" />
                        <line x1="62" y1="20" x2="80" y2="20" stroke="#d4af37" stroke-width="1" />
                    </svg>
                </div>
                <div class="meta-row">
                    <table><tr>
                        <td>
                            <div class="mr-k">Ref</div>
                            <span class="mr-v">{{ $ropa['number'] ?? '-' }}</span>
                        </td>
                        <td>
                            <div class="mr-k">Date</div>
                            <span class="mr-v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                        </td>
                        <td>
                            <div class="mr-k">Div</div>
                            <span class="mr-v">{{ $ropa['division'] ?? '-' }}</span>
                        </td>
                    </tr></table>
                </div>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="sec-band">
            <div class="sb-eyebrow">Section I</div>
            <div class="sb-title">Deskripsi Pemrosesan</div>
        </div>
        <div class="sec-body">
            <div class="row-frame">
                <table>
                    <tr><td class="k">Nomor</td><td class="v">{{ $ropa['number'] ?? '-' }}</td></tr>
                    <tr><td class="k">Nama Pemrosesan</td><td class="v">{{ $ropa['name'] ?? '-' }}</td></tr>
                    <tr><td class="k">Divisi</td><td class="v">{{ $ropa['division'] ?? '-' }}</td></tr>
                    <tr><td class="k">Unit Kerja</td><td class="v">{{ $ropa['unit'] ?? '-' }}</td></tr>
                    <tr><td class="k">Entitas</td><td class="v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</td></tr>
                </table>
            </div>
            <div class="desc-box">
                <div class="db-key">Deskripsi Singkat</div>
                <div class="db-val">&ldquo;{{ $ropa['description'] ?? '-' }}&rdquo;</div>
            </div>
        </div>

        <div class="sec-band" style="margin-top: 10mm;">
            <div class="sb-eyebrow">Section II</div>
            <div class="sb-title">Informasi Pemrosesan</div>
        </div>
        <div class="twocol">
            <table><tr>
                <td>
                    <div class="tc-k">Tujuan</div>
                    <div class="tc-v">{{ $ropa['purpose'] ?? '-' }}</div>
                </td>
                <td class="right">
                    <div class="tc-k">Dasar Hukum</div>
                    <div class="tc-v">{{ $ropa['legal_basis'] ?? '-' }}</div>
                </td>
            </tr></table>
        </div>

        <div class="ct-foot">
            &diams; &nbsp; PAGE TWO &nbsp; &diams;
        </div>
    </div>
</body>
</html>
