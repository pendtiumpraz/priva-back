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
            font-family: 'Inter', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        :root {
            --black: #0a0a0a;
            --black-2: #141414;
            --black-3: #161616;
            --line: #262626;
            --ink: #f5f5f0;
            --ink-2: #c5c5c0;
            --muted: #a5a5a0;
            --muted-2: #5a5a55;
            --lime: #d4ff3a;
            --lime-deep: #b8e02a;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: var(--black);
            color: var(--ink);
        }
        .page:last-child { page-break-after: auto; }

        /* =========================================================
           COVER
           ========================================================= */
        .cover {}

        /* Massive 02 watermark top-right */
        .watermark-02 {
            position: absolute;
            top: -60pt;
            right: -60pt;
            font-size: 540pt;
            font-weight: 900;
            color: var(--black-3);
            line-height: .8;
            letter-spacing: -.08em;
            font-family: 'Inter', sans-serif;
            z-index: 1;
            user-select: none;
        }

        /* Lime arc — huge circle with 60pt lime border, bottom-left */
        .lime-arc {
            position: absolute;
            bottom: -260pt;
            left: -120pt;
            width: 520pt;
            height: 520pt;
            border-radius: 50%;
            background: transparent;
            border: 60pt solid var(--lime);
            z-index: 1;
        }

        .cover-inner {
            position: relative;
            z-index: 3;
            padding: 56pt 56pt;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .cover-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pill-tier {
            display: flex;
            align-items: center;
            gap: 10pt;
        }
        .dot-lime {
            width: 10pt; height: 10pt;
            background: var(--lime);
            border-radius: 50%;
            display: inline-block;
        }
        .tier-label {
            font-size: 10pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .cover-num {
            font-size: 10pt;
            letter-spacing: .2em;
            color: var(--muted);
        }

        .cover-eyebrow {
            font-size: 11pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--lime);
            font-weight: 600;
        }
        .cover-title {
            font-size: 132pt;
            font-weight: 900;
            line-height: .88;
            letter-spacing: -.06em;
            margin: 16pt 0 0;
            color: var(--ink);
        }
        .cover-title .lime { color: var(--lime); }

        .cover-desc {
            margin-top: 28pt;
            max-width: 540pt;
            font-size: 15pt;
            line-height: 1.5;
            color: var(--ink-2);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1pt;
            background: var(--line);
        }
        .meta-grid > div {
            background: var(--black);
            padding: 18pt 16pt;
        }
        .meta-grid .k {
            font-size: 9pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--lime);
            font-weight: 600;
        }
        .meta-grid .v {
            margin-top: 8pt;
            font-size: 15pt;
            font-weight: 500;
            color: var(--ink);
        }

        /* =========================================================
           CONTENT
           ========================================================= */
        .head-band {
            padding: 32pt 48pt 18pt;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .head-left { display: flex; align-items: center; gap: 10pt; }
        .head-left .dot-lime { width: 8pt; height: 8pt; }
        .head-label {
            font-size: 10pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .head-num {
            font-size: 10pt;
            letter-spacing: .2em;
            color: var(--muted);
        }

        .body { padding: 32pt 48pt 60pt; }

        .big-head {
            display: flex;
            align-items: baseline;
            gap: 16pt;
            margin-bottom: 18pt;
        }
        .big-num {
            font-size: 60pt;
            font-weight: 900;
            color: var(--lime);
            letter-spacing: -.04em;
            line-height: .9;
        }
        .big-title {
            margin: 0;
            font-size: 26pt;
            font-weight: 700;
            letter-spacing: -.02em;
            color: var(--ink);
        }

        .section { margin-bottom: 26pt; page-break-inside: avoid; }

        .cells-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; }
        .cells-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10pt; }

        .cell {
            padding: 14pt 16pt;
            background: var(--black-2);
            border-left: 2pt solid var(--lime);
        }
        .cell .k {
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
        }
        .cell .v {
            margin-top: 6pt;
            font-size: 12.5pt;
            color: var(--ink);
            line-height: 1.5;
        }
        .cell.wide { grid-column: 1 / -1; }

        .pill-row { display: flex; flex-wrap: wrap; gap: 6pt; margin-top: 8pt; }
        .pill {
            padding: 5pt 12pt;
            border: 1px solid var(--lime);
            color: var(--lime);
            font-size: 10.5pt;
            border-radius: 4pt;
            font-weight: 500;
            background: transparent;
        }
        .pill.pii {
            background: var(--lime);
            color: var(--black);
            font-weight: 600;
        }

        ul.lime-list { margin: 0; padding: 0; list-style: none; }
        ul.lime-list li {
            padding: 4pt 0 4pt 18pt;
            position: relative;
            font-size: 12pt;
            color: var(--ink);
            line-height: 1.4;
        }
        ul.lime-list li::before {
            content: "●";
            color: var(--lime);
            position: absolute;
            left: 0;
            font-size: 9pt;
            top: 7pt;
        }

        /* Onyx table */
        table.onyx {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--black-2);
            font-size: 11pt;
        }
        table.onyx th {
            background: transparent;
            color: var(--lime);
            text-align: left;
            padding: 10pt 14pt;
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 600;
            border-bottom: 1px solid var(--line);
        }
        table.onyx td {
            padding: 10pt 14pt;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            line-height: 1.5;
            color: var(--ink);
        }
        table.onyx tr:last-child td { border-bottom: none; }
        table.onyx .seq { width: 30pt; color: var(--lime); font-weight: 700; }

        .risk-badge {
            display: inline-block;
            padding: 8pt 18pt;
            font-size: 11pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            font-weight: 700;
            border-radius: 4pt;
        }
        .risk-HIGH { background: #ff4757; color: #fff; }
        .risk-MEDIUM { background: var(--lime); color: var(--black); }
        .risk-LOW { background: transparent; color: var(--lime); border: 1pt solid var(--lime); }

        .page-footer {
            position: absolute;
            bottom: 24pt;
            left: 48pt; right: 48pt;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--muted-2);
        }
        .page-footer .right { color: var(--lime); }
        .page-footer .right::before { content: "● "; }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $pad = function($n){ return str_pad((string)$n, 2, '0', STR_PAD_LEFT); };
@endphp

{{-- PAGE 1 — COVER --}}
<section class="page cover">
    <div class="watermark-02">02</div>
    <div class="lime-arc"></div>

    <div class="cover-inner">
        <div class="cover-top">
            <div class="pill-tier">
                <span class="dot-lime"></span>
                <span class="tier-label">Ropa Export · Premium Tier</span>
            </div>
            <div class="cover-num">{{ $r['number'] ?? '-' }}</div>
        </div>

        <div>
            <div class="cover-eyebrow">Record of Processing</div>
            <h1 class="cover-title">
                ropa.<br>
                <span class="lime">export</span>
            </h1>
            <div class="cover-desc">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="meta-grid">
            <div>
                <div class="k">Document</div>
                <div class="v">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div>
                <div class="k">Effective</div>
                <div class="v">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div>
                <div class="k">Owner</div>
                <div class="v">{{ $r['pic']['name'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</section>

{{-- PAGE 2 — Sec I + II --}}
<section class="page">
    <div class="head-band">
        <div class="head-left">
            <span class="dot-lime"></span>
            <span class="head-label">Ropa Export · Page {{ $pad(2) }}</span>
        </div>
        <div class="head-num">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="section">
            <div class="big-head">
                <span class="big-num">01</span>
                <h2 class="big-title">Deskripsi Pemrosesan</h2>
            </div>
            <div class="cells-2">
                <div class="cell"><div class="k">Nomor ROPA</div><div class="v">{{ $r['number'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Nama Pemrosesan</div><div class="v">{{ $r['name'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Divisi</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Unit Kerja</div><div class="v">{{ $r['unit'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Entitas</div><div class="v">{{ $orgName }}</div></div>
                <div class="cell"><div class="k">Kategori</div><div class="v">{{ $r['category'] ?? '-' }}</div></div>
                <div class="cell wide"><div class="k">Deskripsi Singkat</div><div class="v">{{ $r['description'] ?? '-' }}</div></div>
            </div>
        </div>

        <div class="section">
            <div class="big-head">
                <span class="big-num">02</span>
                <h2 class="big-title">DPO &amp; Process Owner</h2>
            </div>
            <table class="onyx">
                <thead>
                    <tr>
                        <th style="width: 6%;">№</th>
                        <th>Pejabat PDP (DPO)</th>
                        <th>Email</th>
                        <th>Telepon</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="seq">{{ $pad(1) }}</td>
                        <td>{{ $r['dpo']['name'] ?? '-' }}</td>
                        <td>{{ $r['dpo']['email'] ?? '-' }}</td>
                        <td>{{ $r['dpo']['phone'] ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>
            <table class="onyx" style="margin-top: 12pt;">
                <thead>
                    <tr>
                        <th style="width: 6%;">№</th>
                        <th>Process Owner / PIC</th>
                        <th>Jabatan</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="seq">{{ $pad(1) }}</td>
                        <td>{{ $r['pic']['name'] ?? '-' }}</td>
                        <td>{{ $r['pic']['role'] ?? '-' }}</td>
                        <td>{{ $r['pic']['email'] ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="right">{{ $pad(2) }} / {{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 3 — Sec III + IV + V --}}
<section class="page">
    <div class="head-band">
        <div class="head-left">
            <span class="dot-lime"></span>
            <span class="head-label">Ropa Export · Page {{ $pad(3) }}</span>
        </div>
        <div class="head-num">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="section">
            <div class="big-head">
                <span class="big-num">03</span>
                <h2 class="big-title">Informasi Pemrosesan</h2>
            </div>
            <div class="cells-2">
                <div class="cell"><div class="k">Tujuan</div><div class="v">{{ $r['purpose'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Dasar Hukum</div><div class="v">{{ $r['legal_basis'] ?? '-' }}</div></div>
                <div class="cell wide"><div class="k">Aktivitas</div><div class="v">{{ $r['activity'] ?? '-' }}</div></div>
            </div>
            <div class="cell wide" style="margin-top: 10pt;">
                <div class="k">Kategori Pemrosesan</div>
                <div class="pill-row">
                    @foreach($r['categories'] ?? [] as $c)
                        <span class="pill">{{ $c }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="section">
            <div class="big-head">
                <span class="big-num">04</span>
                <h2 class="big-title">Sistem Informasi</h2>
            </div>
            <table class="onyx">
                <thead>
                    <tr>
                        <th style="width: 6%;">№</th>
                        <th>Nama Sistem</th>
                        <th>Lokasi Penyimpanan</th>
                        <th>Lokasi Penggunaan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['systems'] ?? [] as $i => $sys)
                        <tr>
                            <td class="seq">{{ $pad($i + 1) }}</td>
                            <td>{{ $sys['name'] ?? '-' }}</td>
                            <td>{{ $sys['loc'] ?? '-' }}</td>
                            <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="big-head">
                <span class="big-num">05</span>
                <h2 class="big-title">Teknologi &amp; Pemrofilan</h2>
            </div>
            <div class="cells-2">
                <div class="cell"><div class="k">Bantuan AI</div><div class="v">{{ $r['uses_ai'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Keputusan Otomatis</div><div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Teknologi Baru</div><div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Tujuan Pemrofilan</div><div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="right">{{ $pad(3) }} / {{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 4 — Sec VI --}}
<section class="page">
    <div class="head-band">
        <div class="head-left">
            <span class="dot-lime"></span>
            <span class="head-label">Ropa Export · Page {{ $pad(4) }}</span>
        </div>
        <div class="head-num">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="section">
            <div class="big-head">
                <span class="big-num">06</span>
                <h2 class="big-title">Pengumpulan Data</h2>
            </div>
            <div class="cells-2">
                <div class="cell wide">
                    <div class="k">Jenis Subjek Data</div>
                    <div class="v">
                        <ul class="lime-list">
                            @foreach($r['data_subjects'] ?? [] as $s)
                                <li>{{ $s }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="cell"><div class="k">Jumlah Subjek Data</div><div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Sumber Pengumpulan</div><div class="v">{{ $r['data_source'] ?? '-' }}</div></div>
            </div>

            <div class="cell wide" style="margin-top: 10pt;">
                <div class="k">Data Pribadi — Umum</div>
                <div class="pill-row">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="pill">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="cell wide" style="margin-top: 10pt;">
                <div class="k">Data Pribadi — Spesifik</div>
                <div class="pill-row">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <span class="pill">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="cell wide" style="margin-top: 10pt;">
                <div class="k">Data Pribadi — PII</div>
                <div class="pill-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="pill pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="right">{{ $pad(4) }} / {{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 5 — Sec VII + VIII --}}
<section class="page">
    <div class="head-band">
        <div class="head-left">
            <span class="dot-lime"></span>
            <span class="head-label">Ropa Export · Page {{ $pad(5) }}</span>
        </div>
        <div class="head-num">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="section">
            <div class="big-head">
                <span class="big-num">07</span>
                <h2 class="big-title">Penggunaan &amp; Penyimpanan</h2>
            </div>
            <div class="cells-2">
                <div class="cell wide">
                    <div class="k">Kategori Pihak Pemroses</div>
                    <div class="v">
                        <ul class="lime-list">
                            @foreach($r['processor_role'] ?? [] as $role)
                                <li>{{ $role }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="cell"><div class="k">Pemroses Utama</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Pihak Ketiga Terlibat</div><div class="v">{{ $r['has_third_party'] ?? '-' }}</div></div>
            </div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="section">
            <div class="big-head">
                <span class="big-num">08</span>
                <h2 class="big-title">Pihak Ketiga</h2>
            </div>
            <table class="onyx">
                <thead>
                    <tr>
                        <th style="width: 6%;">№</th>
                        <th>Nama Entitas</th>
                        <th>Alamat</th>
                        <th>PIC</th>
                        <th>Kontak</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['third_parties'] as $i => $tp)
                        <tr>
                            <td class="seq">{{ $pad($i + 1) }}</td>
                            <td>{{ $tp['name'] ?? '-' }}</td>
                            <td>{{ $tp['address'] ?? '-' }}</td>
                            <td>{{ $tp['pic_name'] ?? '-' }}</td>
                            <td>
                                {{ $tp['pic_email'] ?? '-' }}<br>
                                <span style="color: var(--muted); font-size: 9pt;">{{ $tp['pic_phone'] ?? '' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="right">{{ $pad(5) }} / {{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 6 — Sec IX + X --}}
<section class="page">
    <div class="head-band">
        <div class="head-left">
            <span class="dot-lime"></span>
            <span class="head-label">Ropa Export · Page {{ $pad(6) }}</span>
        </div>
        <div class="head-num">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="section">
            <div class="big-head">
                <span class="big-num">09</span>
                <h2 class="big-title">Pengiriman Data</h2>
            </div>
            <div class="cells-2">
                <div class="cell"><div class="k">Penerima Internal</div><div class="v">{{ $r['recipients_internal'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Penerima Eksternal</div><div class="v">{{ $r['recipients_external'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Transfer Lintas Negara</div><div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Negara Tujuan</div><div class="v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
            </div>
        </div>

        <div class="section">
            <div class="big-head">
                <span class="big-num">10</span>
                <h2 class="big-title">Jenis Data yang Dikirim</h2>
            </div>
            <div class="cell wide">
                <div class="k">Umum</div>
                <div class="pill-row">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="pill">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="cell wide" style="margin-top: 10pt;">
                <div class="k">Spesifik</div>
                <div class="pill-row">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <span class="pill">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="cell wide" style="margin-top: 10pt;">
                <div class="k">PII</div>
                <div class="pill-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="pill pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="right">{{ $pad(6) }} / {{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 7 — Sec XI + XII + XIII --}}
<section class="page">
    <div class="head-band">
        <div class="head-left">
            <span class="dot-lime"></span>
            <span class="head-label">Ropa Export · Page {{ $pad(7) }}</span>
        </div>
        <div class="head-num">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="section">
            <div class="big-head">
                <span class="big-num">11</span>
                <h2 class="big-title">Retensi Data</h2>
            </div>
            <div class="cells-2">
                <div class="cell wide"><div class="k">Nama Dokumen Terkait</div><div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Masa Retensi</div><div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Aktivitas Penghapusan</div><div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Tanggal Berlaku</div><div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }}</div></div>
                <div class="cell"><div class="k">Tanggal Berakhir</div><div class="v">{{ $r['retention_end_date'] ?? '-' }}</div></div>
            </div>
        </div>

        <div class="section">
            <div class="big-head">
                <span class="big-num">12</span>
                <h2 class="big-title">Keamanan Data</h2>
            </div>
            <div class="cells-2">
                <div class="cell wide">
                    <div class="k">Kontrol Keamanan</div>
                    <div class="v">
                        <ul class="lime-list">
                            @foreach($r['controls'] ?? [] as $ctrl)
                                <li>{{ $ctrl }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="cell wide"><div class="k">Riwayat Insiden</div><div class="v">{{ $r['has_past_incident'] ?? '-' }}</div></div>
            </div>
        </div>

        <div class="section">
            <div class="big-head">
                <span class="big-num">13</span>
                <h2 class="big-title">Klasifikasi Risiko</h2>
            </div>
            <div class="cells-2">
                <div class="cell">
                    <div class="k">Level Risiko</div>
                    <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
                </div>
                <div class="cell"><div class="k">Justifikasi</div><div class="v">{{ $r['risk_justification'] ?? '-' }}</div></div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="right">{{ $pad(7) }} / {{ $pad($totalPages) }}</span>
    </div>
</section>

</body>
</html>
