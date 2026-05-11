<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Cormorant Garamond', 'Times New Roman', serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #3a2a14;
        }

        /* =========================================================
           Manuscript Vellum palette
           ========================================================= */
        :root {
            --parch-1: #f1e5c5;
            --parch-2: #e8d8a9;
            --ink: #3a2a14;
            --red: #a8201a;
            --brown: #8b6a2a;
            --sienna: #7a4f1d;
            --rule: #c4a96a;
            --rule-soft: rgba(139, 106, 42, 0.35);
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: linear-gradient(135deg, var(--parch-1) 0%, var(--parch-2) 100%);
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* Decorative corner ornaments */
        .corner {
            position: absolute;
            width: 22mm;
            height: 22mm;
            z-index: 3;
        }
        .corner.tl { top: 10mm; left: 10mm; }
        .corner.tr { top: 10mm; right: 10mm; transform: scaleX(-1); }
        .corner.bl { bottom: 10mm; left: 10mm; transform: scaleY(-1); }
        .corner.br { bottom: 10mm; right: 10mm; transform: scale(-1, -1); }

        /* Inner double-rule frame */
        .frame {
            position: absolute;
            top: 28mm; left: 28mm; right: 28mm; bottom: 28mm;
            border-top: 1pt solid var(--brown);
            border-bottom: 1pt solid var(--brown);
            z-index: 1;
            pointer-events: none;
        }
        .frame::before, .frame::after {
            content: "";
            position: absolute;
            left: -2pt; right: -2pt;
            height: 1pt;
            background: var(--brown);
            opacity: 0.4;
        }
        .frame::before { top: -4pt; }
        .frame::after { bottom: -4pt; }

        /* =========================================================
           COVER
           ========================================================= */
        .cover-wrap {
            position: relative;
            padding: 38mm 28mm 28mm;
            text-align: center;
            z-index: 2;
        }
        .anno {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 5pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
        }
        .cover-title-block {
            margin-top: 28pt;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 14pt;
        }
        .illum-initial {
            width: 92pt;
            height: 92pt;
            background: var(--red);
            color: var(--parch-1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Cormorant Garamond', serif;
            font-size: 78pt;
            font-weight: 500;
            line-height: 1;
            position: relative;
            flex-shrink: 0;
        }
        .illum-initial::after {
            content: "";
            position: absolute;
            inset: 5pt;
            border: 1pt solid var(--parch-1);
        }
        .cover-title-text {
            text-align: left;
            padding-top: 12pt;
        }
        .cover-title-text .line1 {
            font-size: 46pt;
            font-weight: 400;
            line-height: 1;
            letter-spacing: -1pt;
            color: var(--ink);
        }
        .cover-title-text .line2 {
            font-size: 46pt;
            font-weight: 400;
            font-style: italic;
            line-height: 1;
            letter-spacing: -1pt;
            color: var(--red);
        }

        .cover-prose {
            margin-top: 38pt;
            font-size: 14pt;
            font-style: italic;
            max-width: 440pt;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.65;
            color: var(--ink);
        }
        .cover-prose em { color: var(--red); font-weight: 500; }

        .cover-name {
            margin-top: 28pt;
            font-size: 22pt;
            font-style: italic;
            color: var(--red);
        }

        .cover-meta-row {
            margin-top: 48pt;
            display: inline-flex;
            gap: 26pt;
            padding: 10pt 28pt;
            border-top: 1pt solid var(--brown);
            border-bottom: 1pt solid var(--brown);
        }
        .cover-meta-row .cell {
            text-align: center;
        }
        .cover-meta-row .k {
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 3.5pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
        }
        .cover-meta-row .v {
            margin-top: 4pt;
            font-size: 14pt;
            font-style: italic;
            color: var(--ink);
        }

        /* =========================================================
           CONTENT
           ========================================================= */
        .content-head {
            position: relative;
            padding: 28mm 28mm 0;
            text-align: center;
            z-index: 2;
        }
        .folio-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 5pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
        }
        .folio-title {
            margin: 10pt 0 0;
            font-size: 38pt;
            font-weight: 400;
            font-style: italic;
            letter-spacing: -1pt;
            color: var(--ink);
        }
        .folio-title em {
            color: var(--red);
            font-style: normal;
        }
        .folio-rule {
            margin: 14pt auto 0;
            width: 100%;
            border-top: 2pt solid var(--brown);
        }

        .content-body {
            position: relative;
            padding: 14pt 28mm;
            z-index: 2;
            font-size: 12pt;
            line-height: 1.65;
        }

        .drop-cap::first-letter {
            float: left;
            font-size: 60pt;
            line-height: 0.85;
            margin: 4pt 8pt 0 0;
            color: var(--red);
            font-weight: 500;
        }

        .quill-row {
            display: grid;
            grid-template-columns: 150pt 1fr;
            gap: 14pt;
            padding: 8pt 0;
            border-bottom: 1px dashed var(--rule);
        }
        .quill-row:last-child { border-bottom: none; }
        .quill-row .k {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
            padding-top: 5pt;
        }
        .quill-row .v {
            font-size: 13pt;
            font-style: italic;
            color: var(--ink);
        }

        .double-rule-box {
            border-top: 2pt solid var(--brown);
            border-bottom: 2pt solid var(--brown);
            padding: 14pt 0;
            margin: 14pt 0;
        }
        .double-rule-box::before, .double-rule-box::after {
            content: "";
            display: block;
            border-top: 1pt solid var(--brown);
            opacity: 0.5;
        }
        .double-rule-box::before { margin-top: -18pt; margin-bottom: 14pt; }
        .double-rule-box::after { margin-bottom: -18pt; margin-top: 14pt; }

        .caput-mid {
            text-align: center;
            margin: 22pt 0 14pt;
        }
        .caput-mid .lab {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
        }
        .caput-mid h3 {
            margin: 8pt 0 0;
            font-size: 26pt;
            font-weight: 400;
            font-style: italic;
            color: var(--ink);
            letter-spacing: -0.5pt;
        }
        .caput-mid h3 em { color: var(--red); font-style: normal; }

        /* Illuminated section number (small red square + roman) */
        .illum-mini {
            display: inline-flex;
            align-items: center;
            gap: 8pt;
            margin-bottom: 8pt;
        }
        .illum-mini-box {
            width: 22pt; height: 22pt;
            background: var(--red);
            color: var(--parch-1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: 'Cormorant Garamond', serif;
            font-size: 14pt;
            font-weight: 500;
            position: relative;
        }
        .illum-mini-box::after {
            content: "";
            position: absolute;
            inset: 2pt;
            border: 0.5pt solid var(--parch-1);
        }
        .illum-mini .lab {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
        }

        /* Manuscript table */
        table.vellum-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8pt;
            font-family: 'Cormorant Garamond', serif;
            font-size: 11pt;
        }
        table.vellum-table th {
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--brown);
            font-weight: 600;
            text-align: left;
            padding: 7pt 8pt 7pt 0;
            border-top: 1.5pt solid var(--brown);
            border-bottom: 1pt solid var(--brown);
        }
        table.vellum-table td {
            padding: 7pt 8pt 7pt 0;
            border-bottom: 1px dashed var(--rule);
            vertical-align: top;
            font-style: italic;
            color: var(--ink);
            line-height: 1.5;
        }
        table.vellum-table tr:last-child td { border-bottom: 1.5pt solid var(--brown); }
        table.vellum-table .seq {
            color: var(--red);
            font-weight: 500;
            width: 30pt;
            font-style: normal;
            font-family: 'Cormorant Garamond', serif;
        }

        .vellum-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .vellum-list li {
            padding: 3pt 0;
            font-size: 13pt;
            font-style: italic;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8pt;
        }
        .vellum-list li::before {
            content: "✦";
            color: var(--red);
            font-size: 9pt;
            font-style: normal;
        }

        .risk-badge {
            display: inline-block;
            padding: 5pt 16pt;
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            font-weight: 700;
            border: 1.5pt solid var(--ink);
        }
        .risk-HIGH { background: var(--red); color: var(--parch-1); border-color: var(--red); }
        .risk-MEDIUM { background: var(--brown); color: var(--parch-1); border-color: var(--brown); }
        .risk-LOW { background: var(--parch-1); color: var(--ink); }

        .page-footer {
            position: absolute;
            bottom: 18mm;
            left: 0; right: 0;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 5pt;
            color: var(--brown);
            z-index: 4;
            font-weight: 600;
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $romans = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII','XIII'];
@endphp

@php
    // Decorative corner SVG (reused on all pages)
    $cornerSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="100%" height="100%">
        <path d="M0 64 L0 0 L64 0" stroke="#8b6a2a" stroke-width="1.5" fill="none"/>
        <path d="M0 32 Q16 16 32 0" stroke="#8b6a2a" stroke-width="1" fill="none"/>
        <circle cx="0" cy="0" r="4" fill="#8b6a2a"/>
        <path d="M8 8 Q16 4 24 8 Q20 14 8 8" fill="#a8201a"/>
        <circle cx="14" cy="22" r="2" fill="#a8201a"/>
        <circle cx="22" cy="14" r="2" fill="#a8201a"/>
    </svg>';
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>
    <div class="frame"></div>

    <div class="cover-wrap">
        <div class="anno">~ Anno MMXXVI ~</div>

        <div class="cover-title-block">
            <div class="illum-initial">R</div>
            <div class="cover-title-text">
                <div class="line1">ecord of</div>
                <div class="line2">Processing</div>
            </div>
        </div>

        <div class="cover-prose">
            Hereby is set down a true and faithful record of the processing of personal data, made this day for <em>{{ $orgName }}</em>.
        </div>

        <div class="cover-name">"{{ $r['name'] ?? '-' }}"</div>

        <div class="cover-meta-row">
            <div class="cell">
                <div class="k">Ref.</div>
                <div class="v">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="cell">
                <div class="k">Diem</div>
                <div class="v">{{ $r['date'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Folio II · Caput I Deskripsi + Caput II Pejabat
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>

    <div class="content-head">
        <div class="folio-eyebrow">~ Folio II ~</div>
        <h2 class="folio-title">Of the <em>Processing</em></h2>
        <div class="folio-rule"></div>
    </div>

    <div class="content-body">
        <div class="illum-mini">
            <span class="illum-mini-box">I</span>
            <span class="lab">Caput Primum · Deskripsi</span>
        </div>
        <p class="drop-cap" style="margin: 0 0 12pt;">
            Dokumen ini mencatat aktivitas pemrosesan data pribadi yang dijalankan oleh divisi <em style="color: var(--red); font-style: italic;">{{ $r['division'] ?? '-' }}</em>, dengan nomor referensi <strong style="color: var(--ink); font-weight: 600;">{{ $r['number'] ?? '-' }}</strong>. Pemrosesan dimaksud bertajuk <em style="color: var(--red); font-style: italic;">{{ $r['name'] ?? '-' }}</em>, dan dilaksanakan oleh <strong style="color: var(--ink); font-weight: 600;">{{ $orgName }}</strong>.
        </p>
        <p style="margin: 0 0 14pt; font-style: italic;">{{ $r['description'] ?? '-' }}</p>

        <div class="double-rule-box">
            <div class="quill-row">
                <div class="k">Nomor</div>
                <div class="v">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Divisi · Unit</div>
                <div class="v">{{ $r['division'] ?? '-' }} · {{ $r['unit'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Kategori</div>
                <div class="v">{{ $r['category'] ?? '-' }}</div>
            </div>
        </div>

        <div class="caput-mid">
            <div class="lab">Caput Secundum</div>
            <h3>Of the <em>Officers</em></h3>
        </div>
        <table class="vellum-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th style="width: 30%;">Pejabat PDP (DPO)</th>
                    <th>Email</th>
                    <th style="width: 22%;">Telepon</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">I</td>
                    <td>{{ $r['dpo']['name'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['email'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['phone'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
        <table class="vellum-table" style="margin-top: 14pt;">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th style="width: 30%;">Process Owner / PIC</th>
                    <th style="width: 28%;">Jabatan</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">I</td>
                    <td>{{ $r['pic']['name'] ?? '-' }}</td>
                    <td>{{ $r['pic']['role'] ?? '-' }}</td>
                    <td>{{ $r['pic']['email'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="page-footer">✦ II ✦</div>
</section>

{{-- ============================================================
     PAGE 3 — Folio III · Caput III/IV/V Informasi/Sistem/Teknologi
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>

    <div class="content-head">
        <div class="folio-eyebrow">~ Folio III ~</div>
        <h2 class="folio-title">Of the <em>Information</em></h2>
        <div class="folio-rule"></div>
    </div>

    <div class="content-body">
        <div class="illum-mini">
            <span class="illum-mini-box">III</span>
            <span class="lab">Caput Tertium · Informasi</span>
        </div>
        <div class="quill-row">
            <div class="k">Tujuan</div>
            <div class="v">{{ $r['purpose'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Aktivitas</div>
            <div class="v">{{ $r['activity'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Dasar Hukum</div>
            <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Kategori</div>
            <div class="v">
                @foreach($r['categories'] ?? [] as $i => $cat)
                    @if($i > 0) · @endif{{ $cat }}
                @endforeach
            </div>
        </div>

        <div class="caput-mid">
            <div class="lab">Caput Quartum</div>
            <h3>Of the <em>Systems</em></h3>
        </div>
        <table class="vellum-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Penyimpanan</th>
                    <th>Lokasi Penggunaan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ $romans[$i] ?? ($i + 1) }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @empty
                    <tr><td class="seq">—</td><td colspan="3">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="caput-mid">
            <div class="lab">Caput Quintum</div>
            <h3>Of <em>Technology</em></h3>
        </div>
        <div class="double-rule-box">
            <div class="quill-row">
                <div class="k">Bantuan AI</div>
                <div class="v">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Pengambilan Keputusan Otomatis</div>
                <div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Teknologi Baru</div>
                <div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Tujuan Pemrofilan</div>
                <div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="page-footer">✦ III ✦</div>
</section>

{{-- ============================================================
     PAGE 4 — Folio IV · Caput VI Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>

    <div class="content-head">
        <div class="folio-eyebrow">~ Folio IV ~</div>
        <h2 class="folio-title">Of <em>Collection</em></h2>
        <div class="folio-rule"></div>
    </div>

    <div class="content-body">
        <div class="illum-mini">
            <span class="illum-mini-box">VI</span>
            <span class="lab">Caput Sextum · Pengumpulan Data</span>
        </div>

        <div class="quill-row">
            <div class="k">Jumlah Subjek</div>
            <div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Sumber</div>
            <div class="v">{{ $r['data_source'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Subjek Data</div>
            <div class="v">
                <ul class="vellum-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="double-rule-box">
            <div class="quill-row">
                <div class="k">Data Umum</div>
                <div class="v">
                    <ul class="vellum-list">
                        @foreach($r['data_general'] ?? [] as $d)
                            <li>{{ $d }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="quill-row">
                <div class="k">Data Spesifik</div>
                <div class="v">
                    <ul class="vellum-list">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <li>{{ $d }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="quill-row">
                <div class="k">Data PII</div>
                <div class="v">
                    <ul class="vellum-list">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <li style="color: var(--red);">{{ $d }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">✦ IV ✦</div>
</section>

{{-- ============================================================
     PAGE 5 — Folio V · Caput VII/VIII Penggunaan & Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>

    <div class="content-head">
        <div class="folio-eyebrow">~ Folio V ~</div>
        <h2 class="folio-title">Of the <em>Processors</em></h2>
        <div class="folio-rule"></div>
    </div>

    <div class="content-body">
        <div class="illum-mini">
            <span class="illum-mini-box">VII</span>
            <span class="lab">Caput Septimum · Penggunaan</span>
        </div>
        <div class="quill-row">
            <div class="k">Kategori Pemroses</div>
            <div class="v">
                <ul class="vellum-list">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <li>{{ $role }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="quill-row">
            <div class="k">Pemroses Utama</div>
            <div class="v">{{ $r['processor_entity'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Pihak Ketiga</div>
            <div class="v">{{ $r['has_third_party'] ?? '-' }}</div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="caput-mid">
            <div class="lab">Caput Octavum</div>
            <h3>Of <em>Third Parties</em></h3>
        </div>
        <table class="vellum-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th>Nama Entitas</th>
                    <th>Alamat</th>
                    <th style="width: 18%;">PIC</th>
                    <th style="width: 22%;">Kontak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ $romans[$i] ?? ($i + 1) }}</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="font-style: normal; font-family: 'Inter', sans-serif; font-size: 9pt; color: var(--brown);">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div class="page-footer">✦ V ✦</div>
</section>

{{-- ============================================================
     PAGE 6 — Folio VI · Caput IX/X Pengiriman & Jenis Data
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>

    <div class="content-head">
        <div class="folio-eyebrow">~ Folio VI ~</div>
        <h2 class="folio-title">Of <em>Transmission</em></h2>
        <div class="folio-rule"></div>
    </div>

    <div class="content-body">
        <div class="illum-mini">
            <span class="illum-mini-box">IX</span>
            <span class="lab">Caput Nonum · Pengiriman</span>
        </div>
        <div class="double-rule-box">
            <div class="quill-row">
                <div class="k">Penerima Internal</div>
                <div class="v">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Penerima Eksternal</div>
                <div class="v">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Transfer Luar Negeri</div>
                <div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="quill-row">
                <div class="k">Negara Tujuan</div>
                <div class="v">{{ !empty($r['cross_border_destinations']) ? implode(' · ', $r['cross_border_destinations']) : '-' }}</div>
            </div>
        </div>

        <div class="caput-mid">
            <div class="lab">Caput Decimum</div>
            <h3>Of <em>Data Sent</em></h3>
        </div>
        <div class="quill-row">
            <div class="k">Umum</div>
            <div class="v">
                <ul class="vellum-list">
                    @foreach($r['data_general'] ?? [] as $d)
                        <li>{{ $d }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="quill-row">
            <div class="k">Spesifik</div>
            <div class="v">
                <ul class="vellum-list">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <li>{{ $d }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="quill-row">
            <div class="k">PII</div>
            <div class="v">
                <ul class="vellum-list">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <li style="color: var(--red);">{{ $d }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="page-footer">✦ VI ✦</div>
</section>

{{-- ============================================================
     PAGE 7 — Folio VII · Caput XI/XII/XIII Retensi/Keamanan/Risiko
     ============================================================ --}}
<section class="page">
    <div class="corner tl">{!! $cornerSvg !!}</div>
    <div class="corner tr">{!! $cornerSvg !!}</div>
    <div class="corner bl">{!! $cornerSvg !!}</div>
    <div class="corner br">{!! $cornerSvg !!}</div>

    <div class="content-head">
        <div class="folio-eyebrow">~ Folio VII ~</div>
        <h2 class="folio-title">Of <em>Custody</em> &amp; <em>Risk</em></h2>
        <div class="folio-rule"></div>
    </div>

    <div class="content-body">
        <div class="illum-mini">
            <span class="illum-mini-box">XI</span>
            <span class="lab">Caput Undecimum · Retensi</span>
        </div>
        <div class="quill-row">
            <div class="k">Nama Dokumen</div>
            <div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Masa Retensi</div>
            <div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Tanggal Berlaku</div>
            <div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} s/d {{ $r['retention_end_date'] ?? '-' }}</div>
        </div>
        <div class="quill-row">
            <div class="k">Aktivitas Penghapusan</div>
            <div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div>
        </div>

        <div class="caput-mid">
            <div class="lab">Caput Duodecimum</div>
            <h3>Of <em>Security</em></h3>
        </div>
        <div class="quill-row">
            <div class="k">Kontrol Keamanan</div>
            <div class="v">
                <ul class="vellum-list">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <li>{{ $ctrl }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="quill-row">
            <div class="k">Riwayat Insiden</div>
            <div class="v">{{ $r['has_past_incident'] ?? '-' }}</div>
        </div>

        <div class="caput-mid">
            <div class="lab">Caput Tertium Decimum</div>
            <h3>Of <em>Risk</em></h3>
        </div>
        <div class="quill-row">
            <div class="k">Level Risiko</div>
            <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
        </div>
        <div class="quill-row">
            <div class="k">Justifikasi</div>
            <div class="v">{{ $r['risk_justification'] ?? '-' }}</div>
        </div>
    </div>

    <div class="page-footer">✦ VII · FINIS ✦</div>
</section>

</body>
</html>
