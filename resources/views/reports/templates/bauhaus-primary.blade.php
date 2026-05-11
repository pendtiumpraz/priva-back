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
            --cream: #f3eed8;
            --cream-2: #ede7c8;
            --ink: #0a0a0a;
            --muted: #5a5a55;
            --red: #e63946;
            --blue: #1d3557;
            --yellow: #f1c40f;
            --white: #ffffff;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: var(--cream);
            color: var(--ink);
        }
        .page:last-child { page-break-after: auto; }

        /* =========================================================
           COVER — Bauhaus 1925 geometric composition
           ========================================================= */
        .cover {}

        /* Top-right: red square overlaid by blue triangle (clip-path) */
        .sq-red {
            position: absolute;
            top: 0; right: 0;
            width: 360pt; height: 360pt;
            background: var(--red);
            z-index: 1;
        }
        .tri-blue {
            position: absolute;
            top: 0; right: 0;
            width: 360pt; height: 360pt;
            background: var(--blue);
            clip-path: polygon(0 100%, 100% 0, 100% 100%);
            z-index: 2;
        }
        /* Left circle (yellow) */
        .circle-yellow {
            position: absolute;
            top: 360pt; left: 0;
            width: 200pt; height: 200pt;
            border-radius: 50%;
            background: var(--yellow);
            z-index: 1;
        }
        /* Bottom three stripes */
        .stripe-1 {
            position: absolute;
            bottom: 0; left: 0;
            width: 480pt; height: 12pt;
            background: var(--ink);
            z-index: 3;
        }
        .stripe-2 {
            position: absolute;
            bottom: 12pt; left: 0;
            width: 320pt; height: 6pt;
            background: var(--red);
            z-index: 3;
        }
        .stripe-3 {
            position: absolute;
            bottom: 22pt; left: 0;
            width: 200pt; height: 3pt;
            background: var(--blue);
            z-index: 3;
        }

        .cover-inner {
            position: relative;
            z-index: 4;
            padding: 64pt 56pt;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .cover-top-label {
            font-size: 11pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--ink);
        }
        .cover-top-sub {
            font-size: 11pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            margin-top: 4pt;
            color: var(--muted);
        }

        .cover-title {
            font-size: 108pt;
            font-weight: 900;
            line-height: .85;
            letter-spacing: -.05em;
            margin: 0;
            text-transform: lowercase;
            color: var(--ink);
        }
        .cover-title .red { color: var(--red); }

        .cover-meta-name {
            margin-top: 28pt;
            max-width: 460pt;
            font-size: 14pt;
            line-height: 1.5;
            color: var(--ink);
        }
        .cover-meta-org {
            margin-top: 6pt;
            font-size: 12pt;
            color: var(--muted);
        }

        /* Bottom 3-shape composition */
        .shape-row {
            display: flex;
            gap: 16pt;
            align-items: center;
            padding-top: 40pt;
        }
        .shape {
            width: 60pt; height: 60pt;
        }
        .shape-sq { background: var(--blue); }
        .shape-circle { background: var(--yellow); border-radius: 50%; }
        .shape-tri {
            background: var(--red);
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
        }
        .shape-meta {
            margin-left: 12pt;
            font-size: 11pt;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--muted);
            line-height: 1.4;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .top-bars {
            height: 14pt;
            position: relative;
        }
        .top-bars .b1 { background: var(--red); height: 8pt; }
        .top-bars .b2 { background: var(--blue); height: 4pt; }
        .top-bars .b3 { background: var(--yellow); height: 2pt; }

        .bottom-bar {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 8pt;
            background: var(--red);
        }

        .body {
            padding: 28pt 48pt 50pt;
        }

        .sec-head {
            display: flex;
            align-items: center;
            gap: 14pt;
            margin: 12pt 0 20pt;
        }
        .sec-head .shape-icon {
            width: 32pt; height: 32pt;
            flex-shrink: 0;
        }
        .sec-head .si-sq-red { background: var(--red); }
        .sec-head .si-circle-blue { background: var(--blue); border-radius: 50%; }
        .sec-head .si-tri-yellow {
            background: var(--yellow);
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
        }
        .sec-head .si-sq-yellow { background: var(--yellow); }
        .sec-head .si-circle-red { background: var(--red); border-radius: 50%; }
        .sec-head .si-sq-blue { background: var(--blue); }
        .sec-head .si-tri-red {
            background: var(--red);
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
        }
        .sec-head .si-circle-yellow { background: var(--yellow); border-radius: 50%; }

        .sec-title {
            font-size: 34pt;
            font-weight: 900;
            margin: 0;
            letter-spacing: -.03em;
            text-transform: lowercase;
            color: var(--ink);
        }

        /* 2-col field grid w/ thick black borders */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 2pt solid var(--ink);
        }
        .grid-2 .field {
            padding: 14pt 16pt;
        }
        .grid-2 .field.bd-r { border-right: 2pt solid var(--ink); }
        .grid-2 .field.bd-b { border-bottom: 2pt solid var(--ink); }

        .field .k {
            font-size: 10pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 700;
            opacity: .7;
        }
        .field .v {
            margin-top: 6pt;
            font-size: 13.5pt;
            font-weight: 600;
            line-height: 1.4;
        }
        .field.bg-yellow { background: var(--yellow); color: var(--ink); }
        .field.bg-blue { background: var(--blue); color: var(--white); }
        .field.bg-red { background: var(--red); color: var(--white); }
        .field.bg-white { background: var(--white); }
        .field.bg-ink { background: var(--ink); color: var(--cream); }
        .field.bg-ink .k { color: var(--yellow); opacity: 1; }

        .card-white {
            margin-top: 18pt;
            padding: 16pt 18pt;
            border: 2pt solid var(--ink);
            background: var(--white);
        }
        .card-white .k {
            font-size: 10pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .card-white .v {
            margin-top: 6pt;
            font-size: 13pt;
            line-height: 1.55;
        }

        .card-ink {
            margin-top: 18pt;
            padding: 16pt 18pt;
            background: var(--ink);
            color: var(--cream);
        }
        .card-ink .k {
            font-size: 10pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--yellow);
            font-weight: 700;
        }
        .card-ink .v {
            margin-top: 6pt;
            font-size: 13.5pt;
            line-height: 1.55;
        }

        .pill-row { display: flex; flex-wrap: wrap; gap: 6pt; margin-top: 14pt; }
        .pill {
            padding: 6pt 14pt;
            background: var(--white);
            border: 2pt solid var(--ink);
            font-size: 11.5pt;
            font-weight: 600;
            color: var(--ink);
        }
        .pill.bg-yellow { background: var(--yellow); }
        .pill.bg-blue { background: var(--blue); color: var(--white); }
        .pill.pii { background: var(--red); color: var(--white); }

        ul.geo-list { margin: 0; padding: 0; list-style: none; }
        ul.geo-list li {
            padding: 5pt 0 5pt 24pt;
            position: relative;
            font-size: 12.5pt;
            font-weight: 500;
            line-height: 1.4;
        }
        ul.geo-list li::before {
            content: "";
            position: absolute;
            left: 0; top: 9pt;
            width: 10pt; height: 10pt;
            background: var(--red);
        }
        ul.geo-list li:nth-child(3n+2)::before { background: var(--blue); border-radius: 50%; }
        ul.geo-list li:nth-child(3n)::before {
            background: var(--yellow);
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
        }

        /* Table */
        table.bh {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5pt;
            border: 2pt solid var(--ink);
        }
        table.bh th {
            background: var(--ink);
            color: var(--cream);
            text-align: left;
            padding: 9pt 12pt;
            font-size: 10pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 700;
            border-right: 1pt solid var(--cream);
        }
        table.bh th:last-child { border-right: none; }
        table.bh td {
            padding: 9pt 12pt;
            border-bottom: 1pt solid var(--ink);
            border-right: 1pt solid var(--ink);
            vertical-align: top;
            line-height: 1.45;
            background: var(--white);
            color: var(--ink);
        }
        table.bh td:last-child { border-right: none; }
        table.bh tr:last-child td { border-bottom: none; }
        table.bh .seq {
            width: 30pt;
            background: var(--yellow);
            font-weight: 700;
        }

        .risk-badge {
            display: inline-block;
            padding: 8pt 18pt;
            font-size: 11pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            font-weight: 800;
            border: 2pt solid var(--ink);
        }
        .risk-HIGH { background: var(--red); color: var(--white); }
        .risk-MEDIUM { background: var(--yellow); color: var(--ink); }
        .risk-LOW { background: var(--blue); color: var(--white); }

        .page-footer {
            position: absolute;
            bottom: 14pt;
            left: 48pt; right: 48pt;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700;
            z-index: 5;
        }
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
    <div class="sq-red"></div>
    <div class="tri-blue"></div>
    <div class="circle-yellow"></div>
    <div class="stripe-1"></div>
    <div class="stripe-2"></div>
    <div class="stripe-3"></div>

    <div class="cover-inner">
        <div>
            <div class="cover-top-label">BAUHAUS / 1925</div>
            <div class="cover-top-sub">ROPA · {{ $r['number'] ?? '-' }}</div>
        </div>
        <div>
            <h1 class="cover-title">
                record<br>of<br>
                <span class="red">processing.</span>
            </h1>
            <div class="cover-meta-name">{{ $r['name'] ?? '-' }}</div>
            <div class="cover-meta-org">{{ $orgName }}</div>
        </div>
        <div class="shape-row">
            <div class="shape shape-sq"></div>
            <div class="shape shape-circle"></div>
            <div class="shape shape-tri"></div>
            <div class="shape-meta">
                {{ $r['date'] ?? '-' }}<br>Effective Date
            </div>
        </div>
    </div>
</section>

{{-- PAGE 2 — Sec 01 + 02 --}}
<section class="page">
    <div class="top-bars">
        <div class="b1"></div>
        <div class="b2"></div>
        <div class="b3"></div>
    </div>
    <div class="body">
        <div class="sec-head">
            <div class="shape-icon si-sq-red"></div>
            <h2 class="sec-title">01 / deskripsi</h2>
        </div>
        <div class="grid-2">
            <div class="field bg-yellow bd-r bd-b"><div class="k">No.</div><div class="v">{{ $r['number'] ?? '-' }}</div></div>
            <div class="field bg-white bd-b"><div class="k">Divisi</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
            <div class="field bg-white bd-r"><div class="k">Unit Kerja</div><div class="v">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="field bg-blue"><div class="k">Kategori</div><div class="v">{{ $r['category'] ?? '-' }}</div></div>
        </div>
        <div class="card-white">
            <div class="k">Nama Pemrosesan</div>
            <div class="v">{{ $r['name'] ?? '-' }}</div>
        </div>
        <div class="card-white">
            <div class="k">Entitas</div>
            <div class="v">{{ $orgName }}</div>
        </div>
        <div class="card-white">
            <div class="k">Deskripsi Singkat</div>
            <div class="v">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="sec-head" style="margin-top: 28pt;">
            <div class="shape-icon si-circle-blue"></div>
            <h2 class="sec-title">02 / dpo &amp; pic</h2>
        </div>
        <table class="bh">
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
        <table class="bh" style="margin-top: 14pt;">
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
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $pad(2) }} / {{ $pad($totalPages) }}</span>
    </div>
    <div class="bottom-bar"></div>
</section>

{{-- PAGE 3 — Sec 03 + 04 + 05 --}}
<section class="page">
    <div class="top-bars">
        <div class="b1"></div>
        <div class="b2"></div>
        <div class="b3"></div>
    </div>
    <div class="body">
        <div class="sec-head">
            <div class="shape-icon si-tri-yellow"></div>
            <h2 class="sec-title">03 / informasi</h2>
        </div>
        <div class="card-ink">
            <div class="k">Tujuan</div>
            <div class="v">{{ $r['purpose'] ?? '-' }}</div>
        </div>
        <div class="card-white">
            <div class="k">Aktivitas Pemrosesan</div>
            <div class="v">{{ $r['activity'] ?? '-' }}</div>
        </div>
        <div class="card-white">
            <div class="k">Dasar Hukum</div>
            <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
        </div>
        <div class="pill-row">
            @foreach($r['categories'] ?? [] as $i => $c)
                <span class="pill {{ ['', 'bg-yellow', 'bg-blue'][$i % 3] }}">{{ $c }}</span>
            @endforeach
        </div>

        <div class="sec-head" style="margin-top: 28pt;">
            <div class="shape-icon si-sq-blue"></div>
            <h2 class="sec-title">04 / sistem informasi</h2>
        </div>
        <table class="bh">
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

        <div class="sec-head" style="margin-top: 24pt;">
            <div class="shape-icon si-circle-red"></div>
            <h2 class="sec-title">05 / teknologi</h2>
        </div>
        <div class="grid-2">
            <div class="field bg-white bd-r bd-b"><div class="k">Bantuan AI</div><div class="v">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="field bg-yellow bd-b"><div class="k">Keputusan Otomatis</div><div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="field bg-red bd-r"><div class="k">Teknologi Baru</div><div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="field bg-white"><div class="k">Tujuan Pemrofilan</div><div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $pad(3) }} / {{ $pad($totalPages) }}</span>
    </div>
    <div class="bottom-bar"></div>
</section>

{{-- PAGE 4 — Sec 06 Pengumpulan Data --}}
<section class="page">
    <div class="top-bars">
        <div class="b1"></div>
        <div class="b2"></div>
        <div class="b3"></div>
    </div>
    <div class="body">
        <div class="sec-head">
            <div class="shape-icon si-sq-yellow"></div>
            <h2 class="sec-title">06 / pengumpulan data</h2>
        </div>
        <div class="card-white">
            <div class="k">Jenis Subjek Data</div>
            <div class="v">
                <ul class="geo-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="grid-2" style="margin-top: 18pt;">
            <div class="field bg-blue bd-r"><div class="k">Jumlah Subjek Data</div><div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
            <div class="field bg-yellow"><div class="k">Sumber Pengumpulan</div><div class="v">{{ $r['data_source'] ?? '-' }}</div></div>
        </div>

        <div class="card-white">
            <div class="k">Data Pribadi — Umum</div>
            <div class="pill-row">
                @foreach($r['data_general'] ?? [] as $i => $d)
                    <span class="pill {{ ['', 'bg-yellow'][$i % 2] }}">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="card-white">
            <div class="k">Data Pribadi — Spesifik</div>
            <div class="pill-row">
                @foreach($r['data_specific'] ?? [] as $i => $d)
                    <span class="pill {{ ['', 'bg-blue'][$i % 2] }}">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="card-ink">
            <div class="k">Data Pribadi — PII</div>
            <div class="pill-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="pill pii">{{ $d }}</span>
                @endforeach
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $pad(4) }} / {{ $pad($totalPages) }}</span>
    </div>
    <div class="bottom-bar"></div>
</section>

{{-- PAGE 5 — Sec 07 + 08 --}}
<section class="page">
    <div class="top-bars">
        <div class="b1"></div>
        <div class="b2"></div>
        <div class="b3"></div>
    </div>
    <div class="body">
        <div class="sec-head">
            <div class="shape-icon si-tri-red"></div>
            <h2 class="sec-title">07 / penyimpanan</h2>
        </div>
        <div class="card-white">
            <div class="k">Kategori Pihak Pemroses</div>
            <div class="v">
                <ul class="geo-list">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <li>{{ $role }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="grid-2" style="margin-top: 18pt;">
            <div class="field bg-white bd-r"><div class="k">Pemroses Utama</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="field bg-red"><div class="k">Pihak Ketiga Terlibat</div><div class="v">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="sec-head" style="margin-top: 26pt;">
            <div class="shape-icon si-circle-yellow"></div>
            <h2 class="sec-title">08 / pihak ketiga</h2>
        </div>
        <table class="bh">
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
        @endif
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $pad(5) }} / {{ $pad($totalPages) }}</span>
    </div>
    <div class="bottom-bar"></div>
</section>

{{-- PAGE 6 — Sec 09 + 10 --}}
<section class="page">
    <div class="top-bars">
        <div class="b1"></div>
        <div class="b2"></div>
        <div class="b3"></div>
    </div>
    <div class="body">
        <div class="sec-head">
            <div class="shape-icon si-sq-red"></div>
            <h2 class="sec-title">09 / pengiriman</h2>
        </div>
        <div class="grid-2">
            <div class="field bg-yellow bd-r bd-b"><div class="k">Penerima Internal</div><div class="v">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="field bg-white bd-b"><div class="k">Penerima Eksternal</div><div class="v">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="field bg-blue bd-r"><div class="k">Transfer Lintas Negara</div><div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="field bg-white"><div class="k">Negara Tujuan</div><div class="v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>

        <div class="sec-head" style="margin-top: 28pt;">
            <div class="shape-icon si-circle-blue"></div>
            <h2 class="sec-title">10 / jenis data dikirim</h2>
        </div>
        <div class="card-white">
            <div class="k">Umum</div>
            <div class="pill-row">
                @foreach($r['data_general'] ?? [] as $i => $d)
                    <span class="pill {{ ['', 'bg-yellow'][$i % 2] }}">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="card-white">
            <div class="k">Spesifik</div>
            <div class="pill-row">
                @foreach($r['data_specific'] ?? [] as $i => $d)
                    <span class="pill {{ ['', 'bg-blue'][$i % 2] }}">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="card-ink">
            <div class="k">PII</div>
            <div class="pill-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="pill pii">{{ $d }}</span>
                @endforeach
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $pad(6) }} / {{ $pad($totalPages) }}</span>
    </div>
    <div class="bottom-bar"></div>
</section>

{{-- PAGE 7 — Sec 11 + 12 + 13 --}}
<section class="page">
    <div class="top-bars">
        <div class="b1"></div>
        <div class="b2"></div>
        <div class="b3"></div>
    </div>
    <div class="body">
        <div class="sec-head">
            <div class="shape-icon si-tri-yellow"></div>
            <h2 class="sec-title">11 / retensi data</h2>
        </div>
        <div class="grid-2">
            <div class="field bg-white bd-r bd-b"><div class="k">Nama Dokumen</div><div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
            <div class="field bg-yellow bd-b"><div class="k">Masa Retensi</div><div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div></div>
            <div class="field bg-blue bd-r bd-b"><div class="k">Berlaku</div><div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }}</div></div>
            <div class="field bg-white bd-b"><div class="k">Berakhir</div><div class="v">{{ $r['retention_end_date'] ?? '-' }}</div></div>
            <div class="field bg-red bd-r"><div class="k">Aktivitas Penghapusan</div><div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
            <div class="field bg-white"><div class="k">Riwayat Insiden</div><div class="v">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>

        <div class="sec-head" style="margin-top: 24pt;">
            <div class="shape-icon si-sq-blue"></div>
            <h2 class="sec-title">12 / keamanan</h2>
        </div>
        <div class="card-white">
            <div class="k">Kontrol Keamanan</div>
            <div class="v">
                <ul class="geo-list">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <li>{{ $ctrl }}</li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="sec-head" style="margin-top: 22pt;">
            <div class="shape-icon si-circle-red"></div>
            <h2 class="sec-title">13 / risiko</h2>
        </div>
        <div class="grid-2">
            <div class="field bg-white bd-r">
                <div class="k">Level Risiko</div>
                <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="field bg-yellow"><div class="k">Justifikasi</div><div class="v">{{ $r['risk_justification'] ?? '-' }}</div></div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $pad(7) }} / {{ $pad($totalPages) }}</span>
    </div>
    <div class="bottom-bar"></div>
</section>

</body>
</html>
