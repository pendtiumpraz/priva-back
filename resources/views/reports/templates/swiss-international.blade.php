<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', Helvetica, Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #000;
        }

        /* =========================================================
           Swiss International palette
           ========================================================= */
        :root {
            --ink: #000;
            --paper: #fff;
            --red: #e30613;
            --grid: rgba(0,0,0,.04);
            --muted: #666;
            --muted-soft: #888;
            --rule-soft: rgba(0,0,0,.12);
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

        /* 12-column grid background (faint vertical lines) */
        .grid-bg {
            position: absolute;
            inset: 0;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            pointer-events: none;
            z-index: 0;
        }
        .grid-bg > div {
            border-right: 1px solid var(--grid);
        }
        .grid-bg > div:last-child { border-right: none; }

        /* =========================================================
           COVER
           ========================================================= */
        .cover-top {
            position: absolute;
            top: 18mm; left: 18mm; right: 18mm;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            font-weight: 600;
            letter-spacing: 0.5pt;
            z-index: 2;
        }
        .cover-rule-top {
            position: absolute;
            top: 30mm; left: 18mm; right: 18mm;
            height: 1px;
            background: var(--ink);
            z-index: 2;
        }

        .cover-title-block {
            position: absolute;
            top: 38mm; left: 18mm; right: 18mm;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 6pt;
            z-index: 2;
        }
        .cover-title {
            grid-column: 1 / span 9;
            font-size: 76pt;
            font-weight: 700;
            line-height: 0.95;
            letter-spacing: -3.5pt;
            margin: 0;
            color: var(--ink);
        }
        .cover-red-square {
            grid-column: 10 / span 3;
            padding-top: 14pt;
        }
        .cover-red-square > div {
            width: 22pt;
            height: 22pt;
            background: var(--red);
        }

        .cover-meta-mid {
            position: absolute;
            top: 110mm; left: 18mm; right: 18mm;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 6pt;
            z-index: 2;
        }
        .cover-meta-mid .col-subject { grid-column: 1 / span 5; }
        .cover-meta-mid .col-abstract { grid-column: 7 / span 6; }
        .meta-label {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 1.6pt;
            text-transform: uppercase;
            margin-bottom: 6pt;
        }
        .meta-body {
            font-size: 10.5pt;
            line-height: 1.55;
            color: var(--ink);
        }
        .meta-body .muted { color: var(--muted); }

        .cover-bottom {
            position: absolute;
            left: 18mm; right: 18mm;
            bottom: 22mm;
            border-top: 3pt solid var(--ink);
            padding-top: 12pt;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 8pt;
            z-index: 2;
        }
        .cover-bottom .col { grid-column: span 3; }
        .cover-num {
            font-size: 36pt;
            font-weight: 700;
            letter-spacing: -1.8pt;
            color: var(--red);
            line-height: 1;
        }
        .cover-bottom-label {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 1.6pt;
            text-transform: uppercase;
            margin-top: 9pt;
        }
        .cover-bottom-value {
            font-size: 9.5pt;
            margin-top: 4pt;
            line-height: 1.35;
        }

        /* =========================================================
           CONTENT
           ========================================================= */
        .content { background: var(--paper); }

        .content-head {
            padding: 18mm 18mm 0 18mm;
        }
        .content-head-top {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: 9pt;
            font-weight: 600;
            margin-bottom: 8pt;
        }
        .content-head-top .red-dot {
            color: var(--red);
            font-size: 14pt;
            line-height: 1;
        }

        .section-block {
            padding: 0 18mm;
            margin-top: 14pt;
            position: relative;
            z-index: 1;
        }

        .section-head {
            border-top: 3pt solid var(--ink);
            padding-top: 12pt;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 12pt;
        }
        .section-head h2 {
            margin: 0;
            font-size: 30pt;
            font-weight: 700;
            letter-spacing: -1.2pt;
        }
        .section-head h2 .num {
            color: var(--red);
            margin-right: 8pt;
        }
        .section-head-meta {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            color: var(--ink);
        }

        .grid-12 {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 10pt;
        }
        .field-cell {
            border-top: 1px solid var(--ink);
            padding-top: 8pt;
        }
        .field-cell .k {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
        }
        .field-cell .v {
            margin-top: 5pt;
            font-size: 10.5pt;
            line-height: 1.45;
            color: var(--ink);
        }
        .field-cell .v.lg { font-size: 12pt; font-weight: 500; }
        .field-cell .v .muted { color: var(--muted); font-size: 9pt; display: block; margin-top: 2pt; }

        .span-3 { grid-column: span 3; }
        .span-4 { grid-column: span 4; }
        .span-5 { grid-column: span 5; }
        .span-6 { grid-column: span 6; }
        .span-7 { grid-column: span 7; }
        .span-8 { grid-column: span 8; }
        .span-9 { grid-column: span 9; }
        .span-12 { grid-column: span 12; }

        /* Big-numeral block (Swiss signature) */
        .num-block {
            border-top: 1px solid var(--ink);
            padding-top: 8pt;
        }
        .num-block .big {
            font-size: 24pt;
            font-weight: 700;
            letter-spacing: -1pt;
            color: var(--red);
            line-height: 1;
        }
        .num-block .lab {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            margin-top: 6pt;
        }
        .num-block .val {
            font-size: 10pt;
            margin-top: 4pt;
            line-height: 1.4;
        }

        /* Pill */
        .pill-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4pt;
            margin-top: 4pt;
        }
        .pill {
            border: 1px solid var(--ink);
            padding: 2pt 7pt;
            font-size: 8.5pt;
            line-height: 1.2;
        }
        .pill.red {
            background: var(--red);
            color: #fff;
            border-color: var(--red);
        }

        /* Data table — Swiss strict */
        table.swiss-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
            margin-top: 6pt;
        }
        table.swiss-table th {
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            padding: 6pt 8pt 6pt 0;
            text-align: left;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
        }
        table.swiss-table td {
            border-bottom: 1px solid var(--rule-soft);
            padding: 6pt 8pt 6pt 0;
            vertical-align: top;
            line-height: 1.45;
        }
        table.swiss-table tr:last-child td { border-bottom: 1px solid var(--ink); }
        table.swiss-table .seq {
            color: var(--red);
            font-weight: 700;
            width: 24pt;
        }

        .risk-badge {
            display: inline-block;
            padding: 4pt 12pt;
            font-size: 8.5pt;
            letter-spacing: 1.6pt;
            text-transform: uppercase;
            font-weight: 700;
        }
        .risk-HIGH { background: var(--red); color: #fff; }
        .risk-MEDIUM { background: #000; color: #fff; }
        .risk-LOW { background: #fff; color: #000; border: 2pt solid #000; }

        /* Page footer */
        .page-footer {
            position: absolute;
            bottom: 12mm; left: 18mm; right: 18mm;
            border-top: 3pt solid var(--ink);
            padding-top: 8pt;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9pt;
            font-weight: 600;
            z-index: 2;
        }
        .page-footer .dot {
            color: var(--red);
            font-size: 14pt;
            line-height: 1;
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $orgShort = mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', $orgName), 0, 32));
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>
    <div class="cover-top">
        <div>{{ $orgShort }}</div>
        <div>{{ $r['number'] ?? '-' }} / 2026</div>
    </div>
    <div class="cover-rule-top"></div>

    <div class="cover-title-block">
        <h1 class="cover-title">Record<br>of Processing<br>Activities.</h1>
        <div class="cover-red-square"><div></div></div>
    </div>

    <div class="cover-meta-mid">
        <div class="col-subject">
            <div class="meta-label">Subject</div>
            <div class="meta-body">
                {{ $r['name'] ?? '-' }}<br>
                <span class="muted">{{ $r['division'] ?? '-' }} · {{ $r['unit'] ?? '-' }}</span>
            </div>
        </div>
        <div class="col-abstract">
            <div class="meta-label">Abstract</div>
            <div class="meta-body">{{ $r['description'] ?? '-' }}</div>
        </div>
    </div>

    <div class="cover-bottom">
        <div class="col">
            <div class="cover-num">01</div>
            <div class="cover-bottom-label">No.</div>
            <div class="cover-bottom-value">{{ $r['number'] ?? '-' }}</div>
        </div>
        <div class="col">
            <div class="cover-num">02</div>
            <div class="cover-bottom-label">Div</div>
            <div class="cover-bottom-value">{{ $r['division'] ?? '-' }}</div>
        </div>
        <div class="col">
            <div class="cover-num">03</div>
            <div class="cover-bottom-label">Date</div>
            <div class="cover-bottom-value">{{ $r['date'] ?? '-' }}</div>
        </div>
        <div class="col">
            <div class="cover-num">04</div>
            <div class="cover-bottom-label">Basis</div>
            <div class="cover-bottom-value">{{ $r['legal_basis'] ?? '-' }}</div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — I Deskripsi + II Pejabat PDP & PIC
     ============================================================ --}}
<section class="page content">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>

    <div class="content-head">
        <div class="content-head-top">
            <span>{{ $orgShort }} · {{ $r['number'] ?? '-' }}</span>
            <span><span class="red-dot">●</span></span>
            <span>02 / 0{{ $totalPages }}</span>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">01</span>Deskripsi Pemrosesan</h2>
            <div class="section-head-meta">Folio I</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-3">
                <div class="k">Nomor ROPA</div>
                <div class="v lg">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-5">
                <div class="k">Nama Pemrosesan</div>
                <div class="v lg">{{ $r['name'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-4">
                <div class="k">Tanggal Berlaku</div>
                <div class="v lg">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-4">
                <div class="k">Divisi</div>
                <div class="v">{{ $r['division'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-4">
                <div class="k">Unit Kerja</div>
                <div class="v">{{ $r['unit'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-4">
                <div class="k">Kategori Perusahaan</div>
                <div class="v">{{ $r['category'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Entitas</div>
                <div class="v">{{ $orgName }}</div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Dasar Hukum</div>
                <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Deskripsi Singkat</div>
                <div class="v">{{ $r['description'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">02</span>Pejabat PDP &amp; PIC</h2>
            <div class="section-head-meta">Folio II</div>
        </div>
        <table class="swiss-table">
            <thead>
                <tr>
                    <th style="width:4%;">No.</th>
                    <th style="width:30%;">Pejabat PDP (DPO)</th>
                    <th>Email</th>
                    <th style="width:22%;">Telepon</th>
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
        <table class="swiss-table" style="margin-top: 10pt;">
            <thead>
                <tr>
                    <th style="width:4%;">No.</th>
                    <th style="width:30%;">Process Owner / PIC</th>
                    <th style="width:30%;">Jabatan</th>
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

    <div class="page-footer">
        <span>{{ $orgShort }}</span>
        <span class="dot">●</span>
        <span>P.02</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — III Informasi + IV Sistem + V Teknologi
     ============================================================ --}}
<section class="page content">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>

    <div class="content-head">
        <div class="content-head-top">
            <span>{{ $orgShort }} · {{ $r['number'] ?? '-' }}</span>
            <span><span class="red-dot">●</span></span>
            <span>03 / 0{{ $totalPages }}</span>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">03</span>Informasi Pemrosesan</h2>
            <div class="section-head-meta">Folio III</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-6">
                <div class="k">Tujuan</div>
                <div class="v">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Aktivitas</div>
                <div class="v">{{ $r['activity'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Kategori Pemrosesan</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['categories'] ?? [] as $cat)
                            <span class="pill">{{ $cat }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">04</span>Sistem Informasi</h2>
            <div class="section-head-meta">Folio IV</div>
        </div>
        <table class="swiss-table">
            <thead>
                <tr>
                    <th style="width:4%;">No.</th>
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
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">05</span>Teknologi &amp; Pemrofilan</h2>
            <div class="section-head-meta">Folio V</div>
        </div>
        <div class="grid-12">
            <div class="num-block span-3">
                <div class="big">A</div>
                <div class="lab">Bantuan AI</div>
                <div class="val">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="num-block span-3">
                <div class="big">B</div>
                <div class="lab">Automated Decision</div>
                <div class="val">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="num-block span-3">
                <div class="big">C</div>
                <div class="lab">Teknologi Baru</div>
                <div class="val">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="num-block span-3">
                <div class="big">D</div>
                <div class="lab">Tujuan Pemrofilan</div>
                <div class="val">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgShort }}</span>
        <span class="dot">●</span>
        <span>P.03</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — VI Pengumpulan Data
     ============================================================ --}}
<section class="page content">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>

    <div class="content-head">
        <div class="content-head-top">
            <span>{{ $orgShort }} · {{ $r['number'] ?? '-' }}</span>
            <span><span class="red-dot">●</span></span>
            <span>04 / 0{{ $totalPages }}</span>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">06</span>Pengumpulan Data</h2>
            <div class="section-head-meta">Folio VI</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-8">
                <div class="k">Jenis Subjek Data</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <span class="pill">{{ $s }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="num-block span-4">
                <div class="big">{{ $r['data_subjects_volume'] ?? '—' }}</div>
                <div class="lab">Jumlah Subjek</div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Sumber Pengumpulan</div>
                <div class="v">{{ $r['data_source'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Data Pribadi — Umum</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Data Pribadi — Spesifik</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Data Pribadi — PII (Sensitif)</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill red">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgShort }}</span>
        <span class="dot">●</span>
        <span>P.04</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — VII Penggunaan/Penyimpanan + VIII Pihak Ketiga
     ============================================================ --}}
<section class="page content">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>

    <div class="content-head">
        <div class="content-head-top">
            <span>{{ $orgShort }} · {{ $r['number'] ?? '-' }}</span>
            <span><span class="red-dot">●</span></span>
            <span>05 / 0{{ $totalPages }}</span>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">07</span>Penggunaan &amp; Penyimpanan</h2>
            <div class="section-head-meta">Folio VII</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-12">
                <div class="k">Kategori Pihak Pemroses</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <span class="pill">{{ $role }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Pihak Pemroses Utama</div>
                <div class="v">{{ $r['processor_entity'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Pihak Ketiga Terlibat</div>
                <div class="v">{{ $r['has_third_party'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    @if(!empty($r['third_parties']))
    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">08</span>Pihak Ketiga</h2>
            <div class="section-head-meta">Folio VIII</div>
        </div>
        <table class="swiss-table">
            <thead>
                <tr>
                    <th style="width:4%;">No.</th>
                    <th>Nama Entitas</th>
                    <th>Alamat</th>
                    <th style="width:18%;">PIC</th>
                    <th style="width:22%;">Kontak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>
                            {{ $tp['pic_email'] ?? '-' }}<br>
                            <span style="color: var(--muted); font-size: 8pt;">{{ $tp['pic_phone'] ?? '' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="page-footer">
        <span>{{ $orgShort }}</span>
        <span class="dot">●</span>
        <span>P.05</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — IX Pengiriman + X Jenis Data
     ============================================================ --}}
<section class="page content">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>

    <div class="content-head">
        <div class="content-head-top">
            <span>{{ $orgShort }} · {{ $r['number'] ?? '-' }}</span>
            <span><span class="red-dot">●</span></span>
            <span>06 / 0{{ $totalPages }}</span>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">09</span>Pengiriman Data</h2>
            <div class="section-head-meta">Folio IX</div>
        </div>
        <div class="grid-12">
            <div class="num-block span-3">
                <div class="big">{{ ($r['recipients_internal'] ?? '') === 'Ya' ? 'Ya' : 'No' }}</div>
                <div class="lab">Internal</div>
                <div class="val">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="num-block span-3">
                <div class="big">{{ ($r['recipients_external'] ?? '') === 'Ya' ? 'Ya' : 'No' }}</div>
                <div class="lab">Eksternal</div>
                <div class="val">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="num-block span-3">
                <div class="big">{{ ($r['cross_border_transfer'] ?? '') === 'Ya' ? 'Ya' : 'No' }}</div>
                <div class="lab">Cross-Border</div>
                <div class="val">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="num-block span-3">
                <div class="big">{{ count($r['cross_border_destinations'] ?? []) }}</div>
                <div class="lab">Negara Tujuan</div>
                <div class="val">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">10</span>Jenis Data Dikirim</h2>
            <div class="section-head-meta">Folio X</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-12">
                <div class="k">Umum</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field-cell span-12">
                <div class="k">Spesifik</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field-cell span-12">
                <div class="k">PII</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill red">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgShort }}</span>
        <span class="dot">●</span>
        <span>P.06</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — XI Retensi + XII Keamanan + XIII Risiko
     ============================================================ --}}
<section class="page content">
    <div class="grid-bg">
        @for($i=0;$i<12;$i++)<div></div>@endfor
    </div>

    <div class="content-head">
        <div class="content-head-top">
            <span>{{ $orgShort }} · {{ $r['number'] ?? '-' }}</span>
            <span><span class="red-dot">●</span></span>
            <span>07 / 0{{ $totalPages }}</span>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">11</span>Retensi Data</h2>
            <div class="section-head-meta">Folio XI</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-6">
                <div class="k">Nama Dokumen</div>
                <div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-3">
                <div class="k">Masa Retensi</div>
                <div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-3">
                <div class="k">Aktivitas Penghapusan</div>
                <div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Berlaku</div>
                <div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }}</div>
            </div>
            <div class="field-cell span-6">
                <div class="k">Berakhir</div>
                <div class="v">{{ $r['retention_end_date'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">12</span>Keamanan Data</h2>
            <div class="section-head-meta">Folio XII</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-9">
                <div class="k">Kontrol Keamanan</div>
                <div class="v">
                    <div class="pill-list">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <span class="pill">{{ $ctrl }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field-cell span-3">
                <div class="k">Riwayat Insiden</div>
                <div class="v">{{ $r['has_past_incident'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-head">
            <h2><span class="num">13</span>Klasifikasi Risiko</h2>
            <div class="section-head-meta">Folio XIII</div>
        </div>
        <div class="grid-12">
            <div class="field-cell span-3">
                <div class="k">Level</div>
                <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="field-cell span-9">
                <div class="k">Justifikasi</div>
                <div class="v">{{ $r['risk_justification'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgShort }}</span>
        <span class="dot">●</span>
        <span>P.07</span>
    </div>
</section>

</body>
</html>
