<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #1a1a2e;
        }

        /* =========================================================
           Palette Memphis Postmodern
           ========================================================= */
        :root {
            --cream: #fef7ed;
            --ink: #1a1a2e;
            --pink: #ff6b6b;
            --teal: #4ecdc4;
            --yellow: #ffd166;
            --purple: #a78bfa;
            --muted: #7a7a8e;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: var(--cream);
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* =========================================================
           COVER — decorative shapes
           ========================================================= */
        .shape { position: absolute; }
        .shape.circle-pink {
            top: 14mm; right: 14mm;
            width: 32mm; height: 32mm;
            background: var(--pink);
            border-radius: 50%;
        }
        .shape.square-teal {
            top: 24mm; right: 56mm;
            width: 16mm; height: 16mm;
            background: var(--teal);
        }
        .shape.bars {
            top: 50mm; right: 22mm;
            width: 22mm; height: 24mm;
        }
        .shape.bars div {
            height: 2.4mm;
            background: var(--ink);
            margin-bottom: 4mm;
        }
        .shape.dot-grid {
            top: 76mm; right: 56mm;
        }
        .shape.triangle-yellow {
            bottom: -22mm; left: -22mm;
            width: 60mm; height: 60mm;
            background: var(--yellow);
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
        }
        .shape.dashed-circle {
            bottom: 14mm; right: -10mm;
            width: 44mm; height: 44mm;
            border-radius: 50%;
            border: 3.4mm dashed var(--teal);
        }

        .cover-inner {
            position: relative;
            z-index: 2;
            padding: 18mm 16mm;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .brand-row { display: flex; align-items: center; gap: 10pt; }
        .brand-mark {
            width: 32pt; height: 32pt;
            background: var(--ink);
            color: var(--yellow);
            border-radius: 8pt;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 17pt;
        }
        .brand-name {
            font-size: 11pt;
            font-weight: 700;
        }

        .pill-eyebrow {
            display: inline-block;
            padding: 5pt 13pt;
            background: var(--ink);
            color: var(--yellow);
            border-radius: 999pt;
            font-size: 9.5pt;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
        .cover-title {
            font-size: 80pt;
            font-weight: 900;
            line-height: 0.92;
            letter-spacing: -0.04em;
            margin: 14pt 0 0;
        }
        .cover-title .pink { color: var(--pink); }
        .cover-desc {
            margin-top: 18pt;
            font-size: 13pt;
            line-height: 1.55;
            max-width: 360pt;
            color: #3a3a5e;
            font-weight: 500;
        }

        .meta-cards {
            display: flex;
            gap: 10pt;
        }
        .meta-card {
            flex: 1;
            padding: 12pt 14pt;
            border: 2.4pt solid var(--ink);
            border-radius: 14pt;
            box-shadow: 4pt 4pt 0 var(--ink);
        }
        .meta-card.pink { background: var(--pink); }
        .meta-card.teal { background: var(--teal); }
        .meta-card.yellow { background: var(--yellow); }
        .meta-card .k {
            font-size: 9pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }
        .meta-card .v {
            margin-top: 5pt;
            font-size: 14pt;
            font-weight: 800;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .content-pad { padding: 14mm 14mm 18mm; position: relative; height: 100%; }

        .sec-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14pt;
        }
        .sec-head h2 {
            font-size: 30pt;
            font-weight: 900;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .sec-head h2 .num { color: var(--pink); }
        .sec-head h2 .num.teal { color: var(--teal); }
        .sec-head h2 .num.yellow { color: var(--yellow); }
        .sec-head h2 .num.purple { color: var(--purple); }
        .sec-head .badge {
            padding: 6pt 12pt;
            background: var(--ink);
            color: var(--cream);
            border-radius: 999pt;
            font-size: 10pt;
            font-weight: 700;
        }

        .card-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10pt;
            margin-bottom: 14pt;
        }
        .card {
            padding: 13pt 15pt;
            background: #fff;
            border: 2.4pt solid var(--ink);
            border-radius: 16pt;
            box-shadow: 4pt 4pt 0 var(--ink);
        }
        .card.span-2 { grid-column: span 2; }
        .card.pink { background: var(--pink); }
        .card.teal { background: var(--teal); }
        .card.yellow { background: var(--yellow); }
        .card.purple { background: var(--purple); }
        .card.ink { background: var(--ink); color: var(--cream); }
        .card .k {
            font-size: 9pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.16em;
        }
        .card .k.muted { color: var(--muted); }
        .card .v {
            margin-top: 5pt;
            font-size: 14pt;
            font-weight: 700;
            line-height: 1.4;
        }
        .card .v.body { font-size: 11.5pt; font-weight: 500; line-height: 1.55; }

        /* Chip / category pills */
        .chip-row { display: flex; flex-wrap: wrap; gap: 5pt; margin-top: 7pt; }
        .chip {
            padding: 5pt 11pt;
            border-radius: 999pt;
            font-size: 10pt;
            font-weight: 700;
            border: 2pt solid var(--ink);
            color: var(--ink);
        }
        .chip.c0 { background: var(--pink); }
        .chip.c1 { background: var(--teal); }
        .chip.c2 { background: var(--yellow); }
        .chip.c3 { background: var(--purple); }
        .chip.pii { background: var(--ink); color: var(--cream); }
        .chip.outline { background: #fff; }

        /* Lists inside cards */
        ul.fun-list { list-style: none; margin: 4pt 0 0; padding: 0; }
        ul.fun-list li {
            padding: 3pt 0;
            font-size: 11pt;
            font-weight: 600;
            display: flex;
            gap: 7pt;
            align-items: center;
        }
        ul.fun-list li::before {
            content: "";
            width: 8pt; height: 8pt;
            border-radius: 50%;
            background: var(--pink);
            flex-shrink: 0;
        }
        ul.fun-list li:nth-child(2n)::before { background: var(--teal); }
        ul.fun-list li:nth-child(3n)::before { background: var(--yellow); }
        ul.fun-list li:nth-child(4n)::before { background: var(--purple); }

        /* Data table */
        table.fun-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 4pt;
        }
        table.fun-table th {
            background: var(--ink);
            color: var(--cream);
            padding: 8pt 10pt;
            text-align: left;
            font-size: 9pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        table.fun-table th:first-child { border-top-left-radius: 12pt; }
        table.fun-table th:last-child { border-top-right-radius: 12pt; }
        table.fun-table td {
            padding: 8pt 10pt;
            background: #fff;
            border-top: 2pt solid var(--ink);
            font-size: 10.5pt;
            vertical-align: top;
            line-height: 1.45;
        }
        table.fun-table tr td:first-child { border-left: 2pt solid var(--ink); }
        table.fun-table tr td:last-child { border-right: 2pt solid var(--ink); }
        table.fun-table tr:last-child td { border-bottom: 2pt solid var(--ink); }
        table.fun-table tr:last-child td:first-child { border-bottom-left-radius: 12pt; }
        table.fun-table tr:last-child td:last-child { border-bottom-right-radius: 12pt; }
        table.fun-table td.seq {
            font-weight: 800;
            background: var(--yellow);
            text-align: center;
        }

        .risk-card {
            padding: 16pt 18pt;
            border-radius: 16pt;
            border: 2.4pt solid var(--ink);
            box-shadow: 4pt 4pt 0 var(--ink);
            display: flex;
            align-items: center;
            gap: 16pt;
        }
        .risk-card.HIGH { background: var(--pink); }
        .risk-card.MEDIUM { background: var(--yellow); }
        .risk-card.LOW { background: var(--teal); }
        .risk-card .badge {
            padding: 8pt 18pt;
            background: var(--ink);
            color: var(--cream);
            border-radius: 999pt;
            font-size: 12pt;
            font-weight: 900;
            letter-spacing: 0.14em;
        }

        /* Footer */
        .page-footer {
            position: absolute;
            bottom: 8mm;
            left: 14mm; right: 14mm;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            font-weight: 700;
            color: var(--muted);
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $catColors = ['c0', 'c1', 'c2', 'c3'];
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page">
    <div class="shape circle-pink"></div>
    <div class="shape square-teal"></div>
    <div class="shape bars"><div></div><div></div><div></div></div>
    <svg class="shape dot-grid" width="120" height="120" viewBox="0 0 120 120">
        @for($i = 0; $i < 5; $i++)
            @for($j = 0; $j < 5; $j++)
                <circle cx="{{ $i * 24 + 8 }}" cy="{{ $j * 24 + 8 }}" r="4" fill="#ffd166" />
            @endfor
        @endfor
    </svg>
    <div class="shape triangle-yellow"></div>
    <div class="shape dashed-circle"></div>

    <div class="cover-inner">
        <div class="brand-row">
            <div class="brand-mark">R</div>
            <div class="brand-name">ROPA Export</div>
        </div>

        <div>
            <span class="pill-eyebrow">Record of Processing</span>
            <h1 class="cover-title">Hello, <span class="pink">data!</span><br>Mari kita catat.</h1>
            <div class="cover-desc">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="meta-cards">
            <div class="meta-card pink">
                <div class="k">No.</div>
                <div class="v">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="meta-card teal">
                <div class="k">Divisi</div>
                <div class="v">{{ $r['division'] ?? '-' }}</div>
            </div>
            <div class="meta-card yellow">
                <div class="k">Tanggal</div>
                <div class="v">{{ $r['date'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — I Deskripsi + II Pejabat
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="sec-head">
            <h2><span class="num">01.</span> Deskripsi</h2>
            <div class="badge">{{ $r['number'] ?? '-' }}</div>
        </div>
        <div class="card-grid">
            <div class="card pink">
                <div class="k">Nama Pemrosesan</div>
                <div class="v">{{ $r['name'] ?? '-' }}</div>
            </div>
            <div class="card teal">
                <div class="k">Entitas</div>
                <div class="v">{{ $orgName }}</div>
            </div>
            <div class="card">
                <div class="k muted">Divisi</div>
                <div class="v">{{ $r['division'] ?? '-' }}</div>
            </div>
            <div class="card">
                <div class="k muted">Unit Kerja</div>
                <div class="v">{{ $r['unit'] ?? '-' }}</div>
            </div>
            <div class="card span-2 yellow">
                <div class="k">Deskripsi Singkat</div>
                <div class="v body">{{ $r['description'] ?? '-' }}</div>
            </div>
            <div class="card span-2">
                <div class="k muted">Kategori Perusahaan</div>
                <div class="v body">{{ $r['category'] ?? '-' }}</div>
            </div>
        </div>

        <div class="sec-head">
            <h2><span class="num teal">02.</span> Pejabat &amp; PIC</h2>
            <div class="badge">DPO + PIC</div>
        </div>
        <div class="card-grid">
            <div class="card span-2 purple">
                <div class="k">Pejabat PDP (DPO)</div>
                <div class="v">{{ $r['dpo']['name'] ?? '-' }}</div>
                <div class="v body">{{ $r['dpo']['email'] ?? '-' }} &nbsp;·&nbsp; {{ $r['dpo']['phone'] ?? '-' }}</div>
            </div>
            <div class="card span-2 ink">
                <div class="k">Process Owner / PIC</div>
                <div class="v">{{ $r['pic']['name'] ?? '-' }}</div>
                <div class="v body">{{ $r['pic']['role'] ?? '-' }} &nbsp;·&nbsp; {{ $r['pic']['email'] ?? '-' }}</div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>02 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — III Informasi + IV Sistem + V Teknologi
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="sec-head">
            <h2><span class="num yellow">03.</span> Informasi</h2>
            <div class="badge">PROCESSING</div>
        </div>
        <div class="card-grid">
            <div class="card span-2">
                <div class="k muted">Tujuan</div>
                <div class="v body">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div class="card span-2">
                <div class="k muted">Aktivitas</div>
                <div class="v body">{{ $r['activity'] ?? '-' }}</div>
            </div>
            <div class="card span-2 pink">
                <div class="k">Dasar Hukum</div>
                <div class="v body">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
            <div class="card span-2">
                <div class="k muted">Kategori Pemrosesan</div>
                <div class="chip-row">
                    @foreach($r['categories'] ?? [] as $i => $cat)
                        <span class="chip {{ $catColors[$i % 4] }}">{{ $cat }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="sec-head">
            <h2><span class="num purple">04.</span> Sistem</h2>
            <div class="badge">{{ count($r['systems'] ?? []) }} systems</div>
        </div>
        <table class="fun-table">
            <thead>
                <tr>
                    <th style="width: 6%;">No.</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Simpan</th>
                    <th>Lokasi Pakai</th>
                </tr>
            </thead>
            <tbody>
                @forelse($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ $i + 1 }}</td>
                        <td><b>{{ $sys['name'] ?? '-' }}</b></td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;">—</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="sec-head" style="margin-top: 14pt;">
            <h2><span class="num">05.</span> Teknologi</h2>
            <div class="badge">AI · ADM</div>
        </div>
        <div class="card-grid">
            <div class="card pink"><div class="k">Bantuan AI</div><div class="v">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="card teal"><div class="k">Keputusan Otomatis</div><div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="card yellow"><div class="k">Teknologi Baru</div><div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="card purple"><div class="k">Tujuan Pemrofilan</div><div class="v body">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>03 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — VI Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="sec-head">
            <h2><span class="num teal">06.</span> Pengumpulan Data</h2>
            <div class="badge">DATA SUBJECTS</div>
        </div>
        <div class="card-grid">
            <div class="card span-2 yellow">
                <div class="k">Jenis Subjek Data</div>
                <ul class="fun-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="card pink"><div class="k">Jumlah Subjek</div><div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
            <div class="card teal"><div class="k">Sumber Pengumpulan</div><div class="v body">{{ $r['data_source'] ?? '-' }}</div></div>

            <div class="card span-2">
                <div class="k muted">Data Pribadi — Umum</div>
                <div class="chip-row">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="chip outline">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="card span-2">
                <div class="k muted">Data Pribadi — Spesifik</div>
                <div class="chip-row">
                    @foreach($r['data_specific'] ?? [] as $i => $d)
                        <span class="chip {{ $catColors[$i % 4] }}">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="card span-2 ink">
                <div class="k">Data Pribadi — PII (Sensitif)</div>
                <div class="chip-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="chip pii" style="background: var(--pink); color: var(--ink); border-color: var(--cream);">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>04 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — VII Penggunaan + VIII Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="sec-head">
            <h2><span class="num purple">07.</span> Penggunaan &amp; Simpan</h2>
            <div class="badge">STORAGE</div>
        </div>
        <div class="card-grid">
            <div class="card span-2 teal">
                <div class="k">Kategori Pemroses</div>
                <div class="chip-row">
                    @foreach($r['processor_role'] ?? [] as $i => $role)
                        <span class="chip outline">{{ $role }}</span>
                    @endforeach
                </div>
            </div>
            <div class="card"><div class="k muted">Pemroses Utama</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="card"><div class="k muted">Pihak Ketiga Terlibat</div><div class="v">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="sec-head">
            <h2><span class="num">08.</span> Pihak Ketiga</h2>
            <div class="badge">{{ count($r['third_parties']) }} mitra</div>
        </div>
        <table class="fun-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th>Nama Entitas</th>
                    <th>Alamat</th>
                    <th>PIC</th>
                    <th>Kontak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ $i + 1 }}</td>
                        <td><b>{{ $tp['name'] ?? '-' }}</b></td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: var(--muted); font-size: 9pt;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>05 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — IX Pengiriman + X Jenis Data Dikirim
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="sec-head">
            <h2><span class="num yellow">09.</span> Pengiriman Data</h2>
            <div class="badge">TRANSFER</div>
        </div>
        <div class="card-grid">
            <div class="card pink"><div class="k">Penerima Internal</div><div class="v body">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="card teal"><div class="k">Penerima Eksternal</div><div class="v body">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="card yellow"><div class="k">Transfer Lintas Batas</div><div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="card purple"><div class="k">Negara Tujuan</div><div class="v body">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>

        <div class="sec-head">
            <h2><span class="num teal">10.</span> Jenis Data Dikirim</h2>
            <div class="badge">CATEGORIES</div>
        </div>
        <div class="card-grid">
            <div class="card span-2">
                <div class="k muted">Umum</div>
                <div class="chip-row">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="chip outline">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="card span-2">
                <div class="k muted">Spesifik</div>
                <div class="chip-row">
                    @foreach($r['data_specific'] ?? [] as $i => $d)
                        <span class="chip {{ $catColors[$i % 4] }}">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="card span-2 ink">
                <div class="k">PII</div>
                <div class="chip-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="chip" style="background: var(--pink); color: var(--ink); border-color: var(--cream);">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>06 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — XI Retensi + XII Keamanan + XIII Risiko
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="sec-head">
            <h2><span class="num purple">11.</span> Retensi</h2>
            <div class="badge">DATA LIFECYCLE</div>
        </div>
        <div class="card-grid">
            <div class="card span-2 teal">
                <div class="k">Dokumen Terkait</div>
                <div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div>
            </div>
            <div class="card pink"><div class="k">Masa Retensi</div><div class="v">{{ $r['retention_period'] ?? '-' }}</div></div>
            <div class="card yellow"><div class="k">Aktivitas Penghapusan</div><div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
            <div class="card span-2"><div class="k muted">Tanggal Berlaku</div><div class="v">{{ $r['retention_effective_date'] ?? '-' }} &nbsp;&rarr;&nbsp; {{ $r['retention_end_date'] ?? '-' }}</div></div>
        </div>

        <div class="sec-head">
            <h2><span class="num">12.</span> Keamanan</h2>
            <div class="badge">CONTROLS</div>
        </div>
        <div class="card-grid">
            <div class="card span-2 yellow">
                <div class="k">Kontrol Keamanan</div>
                <ul class="fun-list">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <li>{{ $ctrl }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="card span-2"><div class="k muted">Riwayat Insiden</div><div class="v">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>

        <div class="sec-head">
            <h2><span class="num teal">13.</span> Risiko</h2>
            <div class="badge">{{ $r['risk_level'] ?? 'MEDIUM' }}</div>
        </div>
        <div class="risk-card {{ $r['risk_level'] ?? 'MEDIUM' }}">
            <span class="badge">{{ $r['risk_level'] ?? 'MEDIUM' }}</span>
            <div style="font-size: 12pt; font-weight: 600; line-height: 1.5; flex: 1;">{{ $r['risk_justification'] ?? '-' }}</div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>07 / {{ $totalPages }}</span>
    </div>
</section>

</body>
</html>
