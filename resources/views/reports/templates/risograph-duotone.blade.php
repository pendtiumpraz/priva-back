<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #1a1a3a;
        }

        /* =========================================================
           Risograph Duotone palette
           ========================================================= */
        :root {
            --paper: #f5ede0;
            --ink: #1a1a3a;
            --pink: #ff6b9d;
            --blue: #3b5bdb;
            --pink-strong: #ff4a85;
            --blue-strong: #2c4ab8;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: var(--paper);
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* Grain texture overlay (riso signature) */
        .grain {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.12;
            mix-blend-mode: multiply;
            z-index: 5;
        }

        /* Riso shape blobs with multiply blend (signature) */
        .blob {
            position: absolute;
            border-radius: 50%;
            mix-blend-mode: multiply;
            pointer-events: none;
            z-index: 1;
        }
        .blob.pink { background: var(--pink); opacity: 0.85; }
        .blob.blue { background: var(--blue); opacity: 0.75; }

        /* =========================================================
           COVER
           ========================================================= */
        .cover-shell {
            position: relative;
            padding: 18mm;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 2;
        }

        .cover-eyebrow {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 2pt;
            text-transform: uppercase;
        }
        .cover-eyebrow .right { color: var(--pink-strong); }

        .cover-mid .kicker {
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--blue);
        }
        .cover-title {
            font-size: 108pt;
            font-weight: 900;
            line-height: 0.88;
            letter-spacing: -4.5pt;
            margin: 8pt 0 0;
            color: var(--ink);
        }
        .cover-title .activities {
            color: var(--pink);
            mix-blend-mode: multiply;
            display: inline-block;
        }
        .cover-abstract {
            margin-top: 18pt;
            max-width: 470pt;
            font-size: 12pt;
            line-height: 1.55;
            font-weight: 500;
        }

        .cover-footer {
            background: var(--ink);
            color: var(--paper);
            padding: 14pt 18pt;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10pt;
            position: relative;
            z-index: 3;
        }
        .cover-footer .lab {
            font-size: 8pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: var(--pink);
            font-weight: 700;
        }
        .cover-footer .val {
            margin-top: 4pt;
            font-size: 12pt;
            font-weight: 700;
        }

        /* =========================================================
           CONTENT
           ========================================================= */
        .content-shell {
            position: relative;
            padding: 16mm 14mm;
            z-index: 2;
        }

        .section-head {
            display: flex;
            align-items: baseline;
            gap: 14pt;
            margin: 8pt 0 12pt;
            position: relative;
            z-index: 3;
        }
        .section-head .big {
            font-size: 60pt;
            font-weight: 900;
            letter-spacing: -3pt;
            line-height: 0.9;
            mix-blend-mode: multiply;
        }
        .section-head .big.pink { color: var(--pink); }
        .section-head .big.blue { color: var(--blue); }
        .section-head h2 {
            margin: 0;
            font-size: 28pt;
            font-weight: 800;
            letter-spacing: -1.2pt;
            color: var(--ink);
        }
        .section-head .meta {
            margin-left: auto;
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: var(--ink);
            opacity: 0.6;
        }

        /* Dark navy data card (riso signature) */
        .nav-card {
            background: var(--ink);
            color: var(--paper);
            padding: 14pt 16pt;
            margin: 0 0 12pt;
            position: relative;
            z-index: 3;
        }
        .nav-card .row {
            display: grid;
            grid-template-columns: 100pt 1fr;
            gap: 10pt;
            padding: 6pt 0;
            border-bottom: 1px solid rgba(245,237,224,.15);
        }
        .nav-card .row:last-child { border-bottom: none; }
        .nav-card .k {
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            color: var(--pink);
        }
        .nav-card .v {
            font-size: 11pt;
            font-weight: 500;
        }

        /* Pink callout (with mix-blend-mode) */
        .pink-callout {
            background: var(--pink);
            color: var(--ink);
            padding: 14pt 16pt;
            margin: 0 0 12pt;
            mix-blend-mode: multiply;
            position: relative;
            z-index: 3;
        }
        .pink-callout .k {
            font-size: 9pt;
            font-weight: 800;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
        }
        .pink-callout .v {
            margin-top: 5pt;
            font-size: 12pt;
            line-height: 1.5;
            font-weight: 500;
        }

        /* Bordered duo grid */
        .duo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10pt;
            margin-bottom: 12pt;
            position: relative;
            z-index: 3;
        }
        .duo-cell {
            border: 2pt solid var(--ink);
            padding: 12pt 14pt;
            background: var(--paper);
        }
        .duo-cell .k {
            font-size: 9pt;
            font-weight: 800;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            color: var(--blue);
        }
        .duo-cell .v {
            margin-top: 5pt;
            font-size: 11pt;
            font-weight: 500;
            line-height: 1.45;
        }
        .duo-cell.pink-accent .k { color: var(--pink-strong); }

        /* Mono chip (riso category) */
        .mono-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 5pt;
            margin-top: 4pt;
        }
        .mono-chip {
            font-family: 'JetBrains Mono', monospace;
            background: var(--ink);
            color: var(--paper);
            padding: 3pt 8pt;
            font-size: 9pt;
            font-weight: 500;
            letter-spacing: -0.3pt;
        }
        .mono-chip.pink { background: var(--pink); color: var(--ink); mix-blend-mode: multiply; }
        .mono-chip.blue { background: var(--blue); color: var(--paper); mix-blend-mode: multiply; }

        /* Risograph table */
        table.riso-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4pt;
            margin-bottom: 12pt;
            font-size: 9.5pt;
            position: relative;
            z-index: 3;
        }
        table.riso-table th {
            background: var(--ink);
            color: var(--paper);
            text-align: left;
            padding: 7pt 9pt;
            font-size: 8pt;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            font-weight: 800;
        }
        table.riso-table th:first-child { color: var(--pink); }
        table.riso-table td {
            padding: 6pt 9pt;
            vertical-align: top;
            line-height: 1.45;
            border-bottom: 1pt solid rgba(26,26,58,.15);
        }
        table.riso-table tr:last-child td { border-bottom: 2pt solid var(--ink); }
        table.riso-table .seq {
            color: var(--pink-strong);
            font-weight: 800;
            font-family: 'JetBrains Mono', monospace;
        }

        /* Pill data */
        .data-pill {
            display: inline-block;
            background: var(--paper);
            border: 1.5pt solid var(--ink);
            padding: 3pt 9pt;
            font-size: 9pt;
            font-weight: 600;
            margin: 0 3pt 3pt 0;
        }
        .data-pill.pii {
            background: var(--pink);
            color: var(--ink);
            mix-blend-mode: multiply;
            border-color: var(--ink);
        }

        .risk-badge {
            display: inline-block;
            padding: 5pt 14pt;
            font-size: 9pt;
            letter-spacing: 1.6pt;
            text-transform: uppercase;
            font-weight: 800;
            border: 2pt solid var(--ink);
        }
        .risk-HIGH { background: var(--pink); color: var(--ink); mix-blend-mode: multiply; }
        .risk-MEDIUM { background: var(--blue); color: var(--paper); mix-blend-mode: multiply; }
        .risk-LOW { background: var(--paper); color: var(--ink); }

        .content-footer {
            position: absolute;
            left: 14mm; right: 14mm;
            bottom: 12mm;
            display: flex;
            justify-content: space-between;
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: var(--ink);
            z-index: 4;
        }
        .content-footer .ed { color: var(--pink-strong); }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $orgShort = mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', $orgName), 0, 28));
    $edNum = collect(explode('-', $r['number'] ?? ''))->last() ?? '001';
@endphp

@php
    $grainSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"><defs><pattern id="g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs><rect width="100%" height="100%" fill="url(%23g)"/></svg>';
    $grainData = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><defs><pattern id="g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs><rect width="100%" height="100%" fill="url(#g)"/></svg>');
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page">
    <div class="blob pink" style="top: 22mm; right: 18mm; width: 96mm; height: 96mm;"></div>
    <div class="blob blue" style="top: 50mm; right: 46mm; width: 96mm; height: 96mm;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs>
            <pattern id="cover-grain" width="3" height="3" patternUnits="userSpaceOnUse">
                <circle cx="1" cy="1" r=".4" fill="#1a1a3a"/>
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#cover-grain)"/>
    </svg>

    <div class="cover-shell">
        <div class="cover-eyebrow">
            <span>RISO / ed. {{ $edNum }}</span>
            <span class="right">2-Color Print</span>
        </div>
        <div class="cover-mid">
            <div class="kicker">Record of Processing</div>
            <h1 class="cover-title">
                data.<br>
                <span class="activities">activities.</span>
            </h1>
            <div class="cover-abstract">{{ $r['description'] ?? '-' }}</div>
        </div>
        <div class="cover-footer">
            <div>
                <div class="lab">No</div>
                <div class="val">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div>
                <div class="lab">Div</div>
                <div class="val">{{ mb_substr($r['division'] ?? '-', 0, 18) }}</div>
            </div>
            <div>
                <div class="lab">Date</div>
                <div class="val">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div>
                <div class="lab">Org</div>
                <div class="val">{{ mb_strtoupper(mb_substr($orgName, 0, 12)) }}</div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — I Deskripsi + II PDP & PIC
     ============================================================ --}}
<section class="page">
    <div class="blob pink" style="top: -14mm; right: -14mm; width: 68mm; height: 68mm; opacity:.75;"></div>
    <div class="blob blue" style="bottom: -20mm; left: -20mm; width: 80mm; height: 80mm; opacity:.65;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs>
            <pattern id="p2g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#p2g)"/>
    </svg>

    <div class="content-shell">
        <div class="section-head">
            <span class="big pink">01</span>
            <h2>Deskripsi.</h2>
            <div class="meta">Folio I · Bab Pertama</div>
        </div>
        <div class="nav-card">
            <div class="row"><div class="k">Nomor</div><div class="v">{{ $r['number'] ?? '-' }}</div></div>
            <div class="row"><div class="k">Nama</div><div class="v">{{ $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="k">Divisi</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
            <div class="row"><div class="k">Unit</div><div class="v">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="row"><div class="k">Entitas</div><div class="v">{{ $orgName }}</div></div>
            <div class="row"><div class="k">Kategori</div><div class="v">{{ $r['category'] ?? '-' }}</div></div>
        </div>
        <div class="pink-callout">
            <div class="k">Deskripsi Singkat</div>
            <div class="v">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="section-head" style="margin-top: 14pt;">
            <span class="big blue">02</span>
            <h2>Pejabat.</h2>
            <div class="meta">Folio II</div>
        </div>
        <table class="riso-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 30%;">Pejabat PDP (DPO)</th>
                    <th>Email</th>
                    <th style="width: 22%;">Telepon</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">01</td>
                    <td>{{ $r['dpo']['name'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['email'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['phone'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
        <table class="riso-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 30%;">Process Owner / PIC</th>
                    <th style="width: 30%;">Jabatan</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">01</td>
                    <td>{{ $r['pic']['name'] ?? '-' }}</td>
                    <td>{{ $r['pic']['role'] ?? '-' }}</td>
                    <td>{{ $r['pic']['email'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="content-footer">
        <span>{{ $orgShort }}</span>
        <span class="ed">RISO · ED.{{ $edNum }}</span>
        <span>02 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — III Informasi + IV Sistem + V Teknologi
     ============================================================ --}}
<section class="page">
    <div class="blob blue" style="top: -16mm; left: -16mm; width: 70mm; height: 70mm; opacity:.7;"></div>
    <div class="blob pink" style="bottom: -16mm; right: -16mm; width: 70mm; height: 70mm; opacity:.7;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs><pattern id="p3g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs>
        <rect width="100%" height="100%" fill="url(#p3g)"/>
    </svg>

    <div class="content-shell">
        <div class="section-head">
            <span class="big pink">03</span>
            <h2>Informasi.</h2>
            <div class="meta">Folio III</div>
        </div>
        <div class="duo-grid">
            <div class="duo-cell">
                <div class="k">Tujuan</div>
                <div class="v">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Dasar Hukum</div>
                <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
            <div class="duo-cell pink-accent" style="grid-column: span 2;">
                <div class="k">Aktivitas Pemrosesan</div>
                <div class="v">{{ $r['activity'] ?? '-' }}</div>
            </div>
            <div class="duo-cell" style="grid-column: span 2;">
                <div class="k">Kategori Pemrosesan</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['categories'] ?? [] as $cat)
                            <span class="mono-chip">{{ $cat }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="section-head">
            <span class="big blue">04</span>
            <h2>Sistem.</h2>
            <div class="meta">Folio IV</div>
        </div>
        <table class="riso-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Penyimpanan</th>
                    <th>Lokasi Penggunaan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @empty
                    <tr><td class="seq">—</td><td colspan="3">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="section-head">
            <span class="big pink">05</span>
            <h2>Teknologi.</h2>
            <div class="meta">Folio V</div>
        </div>
        <div class="duo-grid">
            <div class="duo-cell">
                <div class="k">Bantuan AI</div>
                <div class="v">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Automated Decision</div>
                <div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Teknologi Baru</div>
                <div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Tujuan Pemrofilan</div>
                <div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="content-footer">
        <span>{{ $orgShort }}</span>
        <span class="ed">RISO · ED.{{ $edNum }}</span>
        <span>03 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — VI Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="blob pink" style="top: -20mm; right: -10mm; width: 80mm; height: 80mm;"></div>
    <div class="blob blue" style="top: 8mm; right: 14mm; width: 80mm; height: 80mm; opacity:.6;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs><pattern id="p4g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs>
        <rect width="100%" height="100%" fill="url(#p4g)"/>
    </svg>

    <div class="content-shell">
        <div class="section-head">
            <span class="big blue">06</span>
            <h2>Pengumpulan Data.</h2>
            <div class="meta">Folio VI</div>
        </div>

        <div class="duo-grid">
            <div class="duo-cell pink-accent">
                <div class="k">Jumlah Subjek Data</div>
                <div class="v" style="font-size: 22pt; font-weight: 800; letter-spacing: -1pt;">{{ $r['data_subjects_volume'] ?? '—' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Sumber Pengumpulan</div>
                <div class="v">{{ $r['data_source'] ?? '-' }}</div>
            </div>
            <div class="duo-cell" style="grid-column: span 2;">
                <div class="k">Jenis Subjek Data</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <span class="mono-chip blue">{{ $s }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="nav-card">
            <div class="row">
                <div class="k">Data Umum</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="mono-chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="k">Data Spesifik</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="mono-chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="k">Data PII</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="mono-chip pink">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-footer">
        <span>{{ $orgShort }}</span>
        <span class="ed">RISO · ED.{{ $edNum }}</span>
        <span>04 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — VII Penggunaan + VIII Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="blob blue" style="top: -16mm; right: -16mm; width: 74mm; height: 74mm;"></div>
    <div class="blob pink" style="bottom: -20mm; left: -10mm; width: 80mm; height: 80mm; opacity:.7;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs><pattern id="p5g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs>
        <rect width="100%" height="100%" fill="url(#p5g)"/>
    </svg>

    <div class="content-shell">
        <div class="section-head">
            <span class="big pink">07</span>
            <h2>Penggunaan.</h2>
            <div class="meta">Folio VII</div>
        </div>

        <div class="duo-grid">
            <div class="duo-cell" style="grid-column: span 2;">
                <div class="k">Kategori Pihak Pemroses</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <span class="mono-chip blue">{{ $role }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="duo-cell">
                <div class="k">Pihak Pemroses Utama</div>
                <div class="v">{{ $r['processor_entity'] ?? '-' }}</div>
            </div>
            <div class="duo-cell pink-accent">
                <div class="k">Pihak Ketiga Terlibat</div>
                <div class="v">{{ $r['has_third_party'] ?? '-' }}</div>
            </div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="section-head">
            <span class="big blue">08</span>
            <h2>Pihak Ketiga.</h2>
            <div class="meta">Folio VIII</div>
        </div>
        <table class="riso-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th>Nama Entitas</th>
                    <th>Alamat</th>
                    <th style="width: 18%;">PIC</th>
                    <th style="width: 22%;">Kontak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="font-size: 8pt; opacity: 0.6;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div class="content-footer">
        <span>{{ $orgShort }}</span>
        <span class="ed">RISO · ED.{{ $edNum }}</span>
        <span>05 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — IX Pengiriman + X Jenis Data Dikirim
     ============================================================ --}}
<section class="page">
    <div class="blob pink" style="top: -16mm; left: -16mm; width: 72mm; height: 72mm;"></div>
    <div class="blob blue" style="bottom: -16mm; right: -16mm; width: 72mm; height: 72mm;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs><pattern id="p6g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs>
        <rect width="100%" height="100%" fill="url(#p6g)"/>
    </svg>

    <div class="content-shell">
        <div class="section-head">
            <span class="big pink">09</span>
            <h2>Pengiriman Data.</h2>
            <div class="meta">Folio IX</div>
        </div>
        <div class="duo-grid">
            <div class="duo-cell">
                <div class="k">Penerima Internal</div>
                <div class="v">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Penerima Eksternal</div>
                <div class="v">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="duo-cell pink-accent">
                <div class="k">Transfer ke Luar Indonesia</div>
                <div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Negara Tujuan</div>
                <div class="v">
                    @if(!empty($r['cross_border_destinations']))
                        <div class="mono-chips">
                            @foreach($r['cross_border_destinations'] as $d)
                                <span class="mono-chip blue">{{ $d }}</span>
                            @endforeach
                        </div>
                    @else
                        -
                    @endif
                </div>
            </div>
        </div>

        <div class="section-head">
            <span class="big blue">10</span>
            <h2>Data Dikirim.</h2>
            <div class="meta">Folio X</div>
        </div>
        <div class="nav-card">
            <div class="row">
                <div class="k">Umum</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="mono-chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="k">Spesifik</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="mono-chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="k">PII</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="mono-chip pink">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-footer">
        <span>{{ $orgShort }}</span>
        <span class="ed">RISO · ED.{{ $edNum }}</span>
        <span>06 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — XI Retensi + XII Keamanan + XIII Risiko
     ============================================================ --}}
<section class="page">
    <div class="blob blue" style="top: -14mm; right: -14mm; width: 70mm; height: 70mm;"></div>
    <div class="blob pink" style="bottom: -16mm; left: -16mm; width: 78mm; height: 78mm;"></div>
    <svg class="grain" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
        <defs><pattern id="p7g" width="3" height="3" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".4" fill="#1a1a3a"/></pattern></defs>
        <rect width="100%" height="100%" fill="url(#p7g)"/>
    </svg>

    <div class="content-shell">
        <div class="section-head">
            <span class="big pink">11</span>
            <h2>Retensi.</h2>
            <div class="meta">Folio XI</div>
        </div>
        <div class="duo-grid">
            <div class="duo-cell" style="grid-column: span 2;">
                <div class="k">Nama Dokumen</div>
                <div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Masa Retensi</div>
                <div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Aktivitas Penghapusan</div>
                <div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Berlaku</div>
                <div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }}</div>
            </div>
            <div class="duo-cell">
                <div class="k">Berakhir</div>
                <div class="v">{{ $r['retention_end_date'] ?? '-' }}</div>
            </div>
        </div>

        <div class="section-head">
            <span class="big blue">12</span>
            <h2>Keamanan.</h2>
            <div class="meta">Folio XII</div>
        </div>
        <div class="nav-card">
            <div class="row">
                <div class="k">Kontrol</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <span class="mono-chip">{{ $ctrl }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="k">Riwayat Insiden</div>
                <div class="v">{{ $r['has_past_incident'] ?? '-' }}</div>
            </div>
        </div>

        <div class="section-head">
            <span class="big pink">13</span>
            <h2>Risiko.</h2>
            <div class="meta">Folio XIII</div>
        </div>
        <div class="pink-callout">
            <div class="k">Level Risiko</div>
            <div class="v" style="margin-top: 8pt;"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            <div class="k" style="margin-top: 12pt;">Justifikasi</div>
            <div class="v">{{ $r['risk_justification'] ?? '-' }}</div>
        </div>
    </div>

    <div class="content-footer">
        <span>{{ $orgShort }}</span>
        <span class="ed">RISO · ED.{{ $edNum }}</span>
        <span>07 / 07</span>
    </div>
</section>

</body>
</html>
