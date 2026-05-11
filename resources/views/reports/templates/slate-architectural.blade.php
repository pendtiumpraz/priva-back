<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600;700&display=swap" rel="stylesheet">
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
            --bg-cover: #e9eaec;
            --bg-sheet: #f4f5f6;
            --ink: #1d2025;
            --muted: #5a6068;
            --hair: #d4d6da;
            --hair-strong: #b5b8bd;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: var(--bg-sheet);
            color: var(--ink);
        }
        .page:last-child { page-break-after: auto; }
        .cover { background: var(--bg-cover); }

        /* =========================================================
           Corner tick marks
           ========================================================= */
        .tick { position: absolute; background: var(--ink); }
        .tick-tl-h { top: 40px; left: 40px; width: 24px; height: 1px; }
        .tick-tl-v { top: 40px; left: 40px; width: 1px; height: 24px; }
        .tick-tr-h { top: 40px; right: 40px; width: 24px; height: 1px; }
        .tick-tr-v { top: 40px; right: 40px; width: 1px; height: 24px; }
        .tick-bl-h { bottom: 40px; left: 40px; width: 24px; height: 1px; }
        .tick-bl-v { bottom: 40px; left: 40px; width: 1px; height: 24px; }
        .tick-br-h { bottom: 40px; right: 40px; width: 24px; height: 1px; }
        .tick-br-v { bottom: 40px; right: 40px; width: 1px; height: 24px; }

        .frame {
            position: absolute;
            top: 56px; right: 56px; bottom: 56px; left: 56px;
            border: 1px solid var(--hair-strong);
        }

        .cover-inner {
            position: absolute;
            top: 80px; right: 80px; bottom: 80px; left: 80px;
        }

        .label-strip {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
            padding-bottom: 12pt;
            border-bottom: 1px solid var(--hair-strong);
        }

        .title-block {
            padding: 60pt 0;
        }
        .title-eyebrow {
            font-size: 10pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .title-main {
            font-size: 56pt;
            font-weight: 300;
            letter-spacing: -.03em;
            margin: 12pt 0 0;
            line-height: 1.02;
            color: var(--ink);
        }
        .title-ref {
            margin-top: 28pt;
            display: flex;
            gap: 4pt;
            align-items: baseline;
            font-size: 12pt;
        }
        .title-ref .lbl { color: var(--muted); letter-spacing: .05em; }
        .title-ref .val { font-weight: 600; }

        .title-meta {
            margin-top: 28pt;
            padding: 16pt 0;
            border-top: 1px solid var(--hair-strong);
            border-bottom: 1px solid var(--hair-strong);
            font-size: 13pt;
            line-height: 1.5;
        }
        .title-meta .org { color: var(--muted); font-size: 12pt; }

        .draft-block {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            border: 1px solid var(--ink);
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            font-size: 11pt;
            background: var(--bg-cover);
        }
        .draft-block > div {
            padding: 10pt 12pt;
            border-right: 1px solid var(--ink);
        }
        .draft-block > div:last-child { border-right: none; }
        .draft-block .k {
            font-size: 8pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .draft-block .v { margin-top: 4pt; font-weight: 500; }

        /* =========================================================
           CONTENT
           ========================================================= */
        .sheet-head {
            padding: 30pt 48pt 16pt;
            border-bottom: 2px solid var(--ink);
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .sheet-head-left .eyebrow {
            font-size: 9pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .sheet-head-left .title {
            font-size: 26pt;
            font-weight: 300;
            letter-spacing: -.02em;
            margin-top: 6pt;
            color: var(--ink);
        }
        .sheet-head-left .title .num { font-weight: 500; margin-right: 6pt; }
        .sheet-head-left .title .name { font-weight: 500; }
        .sheet-head-right {
            font-size: 11pt;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .section { padding: 18pt 48pt; }

        /* 3-col grid drafting layout */
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            border: 1px solid var(--hair);
            border-right: none;
            border-bottom: none;
        }
        .grid-3 .field {
            padding: 12pt 14pt;
            border-right: 1px solid var(--hair);
            border-bottom: 1px solid var(--hair);
        }
        .grid-3 .span-2 { grid-column: span 2; }
        .grid-3 .span-3 { grid-column: span 3; }

        .field .k {
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .field .v {
            margin-top: 6pt;
            font-size: 12pt;
            line-height: 1.5;
            color: var(--ink);
        }

        .sub-head {
            padding: 14pt 48pt 14pt;
            border-bottom: 2px solid var(--ink);
            border-top: 1px solid var(--hair);
            margin-top: 8pt;
            font-size: 26pt;
            font-weight: 300;
            letter-spacing: -.02em;
        }
        .sub-head .num { font-weight: 500; margin-right: 6pt; }
        .sub-head .name { font-weight: 500; }

        /* Table — drafting hairlines */
        table.dt {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
            border: 1px solid var(--hair-strong);
        }
        table.dt th {
            background: var(--bg-cover);
            color: var(--ink);
            text-align: left;
            padding: 8pt 12pt;
            font-size: 8.5pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 500;
            border-bottom: 1px solid var(--hair-strong);
            border-right: 1px solid var(--hair);
        }
        table.dt th:last-child { border-right: none; }
        table.dt td {
            padding: 8pt 12pt;
            border-bottom: 1px solid var(--hair);
            border-right: 1px solid var(--hair);
            vertical-align: top;
            line-height: 1.45;
            color: var(--ink);
        }
        table.dt td:last-child { border-right: none; }
        table.dt tr:last-child td { border-bottom: none; }
        table.dt .seq {
            width: 30pt;
            color: var(--muted);
            font-size: 10pt;
        }

        .pill-row { display: flex; flex-wrap: wrap; gap: 6pt; }
        .pill {
            padding: 4pt 10pt;
            border: 1px solid var(--ink);
            border-radius: 0;
            font-size: 10.5pt;
            color: var(--ink);
            background: #fff;
        }
        .pill.pii {
            background: var(--ink);
            color: #fff;
            border-color: var(--ink);
        }

        ul.dash { margin: 0; padding: 0; list-style: none; font-size: 11.5pt; }
        ul.dash li {
            padding: 3pt 0 3pt 18pt;
            position: relative;
            line-height: 1.45;
        }
        ul.dash li::before {
            content: "—";
            position: absolute;
            left: 0;
            color: var(--muted);
        }

        .risk-badge {
            display: inline-block;
            padding: 6pt 14pt;
            font-size: 9pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            font-weight: 600;
            border: 1px solid var(--ink);
        }
        .risk-HIGH { background: var(--ink); color: #fff; }
        .risk-MEDIUM { background: #fff; color: var(--ink); }
        .risk-LOW { background: #fff; color: var(--muted); border-color: var(--muted); }

        /* Title block at bottom of every content page */
        .sheet-titleblock {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            border-top: 1px solid var(--ink);
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            font-size: 10pt;
        }
        .sheet-titleblock > div {
            padding: 10pt 14pt;
            border-right: 1px solid var(--ink);
        }
        .sheet-titleblock > div:last-child { border-right: none; }
        .sheet-titleblock .k {
            font-size: 8pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .sheet-titleblock .v { margin-top: 3pt; font-weight: 500; }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
@endphp

{{-- PAGE 1 — COVER --}}
<section class="page cover">
    <div class="tick tick-tl-h"></div><div class="tick tick-tl-v"></div>
    <div class="tick tick-tr-h"></div><div class="tick tick-tr-v"></div>
    <div class="tick tick-bl-h"></div><div class="tick tick-bl-v"></div>
    <div class="tick tick-br-h"></div><div class="tick tick-br-v"></div>

    <div class="frame"></div>

    <div class="cover-inner">
        <div class="label-strip">
            <span>Drawing 01 / ROPA Series</span>
            <span>Scale 1:1</span>
            <span>Sheet A</span>
        </div>

        <div class="title-block">
            <div class="title-eyebrow">Title</div>
            <h1 class="title-main">Record of<br>Processing Activities</h1>
            <div class="title-ref">
                <span class="lbl">ref.</span>
                <span class="val">{{ $r['number'] ?? '-' }}</span>
            </div>
            <div class="title-meta">
                {{ $r['name'] ?? '-' }}<br>
                <span class="org">{{ $orgName }}</span>
            </div>
        </div>

        <div class="draft-block">
            <div>
                <div class="k">Project</div>
                <div class="v">{{ $orgName }}</div>
            </div>
            <div>
                <div class="k">Drawn</div>
                <div class="v">{{ $r['pic']['name'] ?? '-' }}</div>
            </div>
            <div>
                <div class="k">Date</div>
                <div class="v">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div>
                <div class="k">Sheet</div>
                <div class="v">A-01</div>
            </div>
        </div>
    </div>
</section>

{{-- PAGE 2 — Sheet A-02 — Sec I + II --}}
<section class="page">
    <div class="sheet-head">
        <div class="sheet-head-left">
            <div class="eyebrow">Sheet A-02 · Deskripsi Pemrosesan</div>
            <div class="title"><span class="num">01 ·</span><span class="name">Deskripsi Pemrosesan</span></div>
        </div>
        <div class="sheet-head-right">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="section">
        <div class="grid-3">
            <div class="field"><div class="k">Nomor ROPA</div><div class="v">{{ $r['number'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Divisi</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Unit Kerja</div><div class="v">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="field span-2"><div class="k">Nama Pemrosesan</div><div class="v">{{ $r['name'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Kategori</div><div class="v">{{ $r['category'] ?? '-' }}</div></div>
            <div class="field span-3"><div class="k">Entitas</div><div class="v">{{ $orgName }}</div></div>
            <div class="field span-3"><div class="k">Deskripsi Singkat</div><div class="v">{{ $r['description'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="sub-head"><span class="num">02 ·</span><span class="name">Data Protection Officer &amp; PIC</span></div>
    <div class="section">
        <table class="dt">
            <thead>
                <tr>
                    <th style="width: 6%;">No.</th>
                    <th style="width: 30%;">Pejabat PDP (DPO)</th>
                    <th>Email</th>
                    <th style="width: 25%;">Telepon</th>
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
        <table class="dt" style="margin-top: 14pt;">
            <thead>
                <tr>
                    <th style="width: 6%;">No.</th>
                    <th style="width: 25%;">Process Owner / PIC</th>
                    <th>Jabatan</th>
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

    <div class="sheet-titleblock">
        <div><div class="k">Project</div><div class="v">{{ $orgName }}</div></div>
        <div><div class="k">Drawn</div><div class="v">{{ $r['pic']['name'] ?? '-' }}</div></div>
        <div><div class="k">Date</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
        <div><div class="k">Sheet</div><div class="v">A-02</div></div>
    </div>
</section>

{{-- PAGE 3 — Sheet A-03 — Sec III + IV + V --}}
<section class="page">
    <div class="sheet-head">
        <div class="sheet-head-left">
            <div class="eyebrow">Sheet A-03 · Informasi Pemrosesan</div>
            <div class="title"><span class="num">03 ·</span><span class="name">Informasi Pemrosesan</span></div>
        </div>
        <div class="sheet-head-right">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="section">
        <div class="grid-3">
            <div class="field span-3"><div class="k">Tujuan</div><div class="v">{{ $r['purpose'] ?? '-' }}</div></div>
            <div class="field span-3"><div class="k">Aktivitas</div><div class="v">{{ $r['activity'] ?? '-' }}</div></div>
            <div class="field span-3"><div class="k">Dasar Hukum</div><div class="v">{{ $r['legal_basis'] ?? '-' }}</div></div>
            <div class="field span-3"><div class="k">Kategori Pemrosesan</div><div class="v">{{ !empty($r['categories']) ? implode(' · ', $r['categories']) : '-' }}</div></div>
        </div>
    </div>

    <div class="sub-head"><span class="num">04 ·</span><span class="name">Sistem Informasi Terkait</span></div>
    <div class="section">
        <table class="dt">
            <thead>
                <tr>
                    <th style="width: 6%;">No.</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Penyimpanan</th>
                    <th>Lokasi Penggunaan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sub-head"><span class="num">05 ·</span><span class="name">Teknologi &amp; Pemrofilan</span></div>
    <div class="section">
        <div class="grid-3" style="grid-template-columns: 1fr 1fr;">
            <div class="field"><div class="k">Bantuan AI</div><div class="v">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Keputusan Otomatis</div><div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Teknologi Baru</div><div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Tujuan Pemrofilan</div><div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="sheet-titleblock">
        <div><div class="k">Project</div><div class="v">{{ $orgName }}</div></div>
        <div><div class="k">Drawn</div><div class="v">{{ $r['pic']['name'] ?? '-' }}</div></div>
        <div><div class="k">Date</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
        <div><div class="k">Sheet</div><div class="v">A-03</div></div>
    </div>
</section>

{{-- PAGE 4 — Sheet A-04 — Sec VI --}}
<section class="page">
    <div class="sheet-head">
        <div class="sheet-head-left">
            <div class="eyebrow">Sheet A-04 · Pengumpulan Data</div>
            <div class="title"><span class="num">06 ·</span><span class="name">Pengumpulan Data</span></div>
        </div>
        <div class="sheet-head-right">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="section">
        <div class="grid-3">
            <div class="field span-3">
                <div class="k">Jenis Subjek Data</div>
                <div class="v">
                    <ul class="dash">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <li>{{ $s }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="field span-2"><div class="k">Sumber Pengumpulan</div><div class="v">{{ $r['data_source'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Jumlah Subjek Data</div><div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>

            <div class="field span-3">
                <div class="k">Data Pribadi — Umum</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field span-3">
                <div class="k">Data Pribadi — Spesifik</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field span-3">
                <div class="k">Data Pribadi — PII</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="sheet-titleblock">
        <div><div class="k">Project</div><div class="v">{{ $orgName }}</div></div>
        <div><div class="k">Drawn</div><div class="v">{{ $r['pic']['name'] ?? '-' }}</div></div>
        <div><div class="k">Date</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
        <div><div class="k">Sheet</div><div class="v">A-04</div></div>
    </div>
</section>

{{-- PAGE 5 — Sheet A-05 — Sec VII + VIII --}}
<section class="page">
    <div class="sheet-head">
        <div class="sheet-head-left">
            <div class="eyebrow">Sheet A-05 · Penggunaan &amp; Penyimpanan</div>
            <div class="title"><span class="num">07 ·</span><span class="name">Penggunaan &amp; Penyimpanan Data</span></div>
        </div>
        <div class="sheet-head-right">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="section">
        <div class="grid-3">
            <div class="field span-3">
                <div class="k">Kategori Pihak Pemroses</div>
                <div class="v">
                    <ul class="dash">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="field span-2"><div class="k">Pihak Pemroses Utama</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Pihak Ketiga Terlibat</div><div class="v">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>
    </div>

    @if(!empty($r['third_parties']))
    <div class="sub-head"><span class="num">08 ·</span><span class="name">Pihak Ketiga</span></div>
    <div class="section">
        <table class="dt">
            <thead>
                <tr>
                    <th style="width: 6%;">No.</th>
                    <th>Nama Entitas</th>
                    <th>Alamat</th>
                    <th>PIC</th>
                    <th>Kontak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</td>
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

    <div class="sheet-titleblock">
        <div><div class="k">Project</div><div class="v">{{ $orgName }}</div></div>
        <div><div class="k">Drawn</div><div class="v">{{ $r['pic']['name'] ?? '-' }}</div></div>
        <div><div class="k">Date</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
        <div><div class="k">Sheet</div><div class="v">A-05</div></div>
    </div>
</section>

{{-- PAGE 6 — Sheet A-06 — Sec IX + X --}}
<section class="page">
    <div class="sheet-head">
        <div class="sheet-head-left">
            <div class="eyebrow">Sheet A-06 · Pengiriman Data</div>
            <div class="title"><span class="num">09 ·</span><span class="name">Pengiriman Data</span></div>
        </div>
        <div class="sheet-head-right">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="section">
        <div class="grid-3" style="grid-template-columns: 1fr 1fr;">
            <div class="field"><div class="k">Penerima Data Internal</div><div class="v">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Penerima Data Eksternal</div><div class="v">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Transfer Lintas Negara</div><div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Negara Tujuan</div><div class="v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>
    </div>

    <div class="sub-head"><span class="num">10 ·</span><span class="name">Jenis Data yang Dikirim</span></div>
    <div class="section">
        <div class="grid-3">
            <div class="field span-3">
                <div class="k">Umum</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field span-3">
                <div class="k">Spesifik</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="field span-3">
                <div class="k">PII</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="sheet-titleblock">
        <div><div class="k">Project</div><div class="v">{{ $orgName }}</div></div>
        <div><div class="k">Drawn</div><div class="v">{{ $r['pic']['name'] ?? '-' }}</div></div>
        <div><div class="k">Date</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
        <div><div class="k">Sheet</div><div class="v">A-06</div></div>
    </div>
</section>

{{-- PAGE 7 — Sheet A-07 — Sec XI + XII + XIII --}}
<section class="page">
    <div class="sheet-head">
        <div class="sheet-head-left">
            <div class="eyebrow">Sheet A-07 · Retensi, Keamanan, Risiko</div>
            <div class="title"><span class="num">11 ·</span><span class="name">Retensi Data</span></div>
        </div>
        <div class="sheet-head-right">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="section">
        <div class="grid-3">
            <div class="field span-3"><div class="k">Nama Dokumen Terkait</div><div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Masa Retensi</div><div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Tanggal Berlaku</div><div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }}</div></div>
            <div class="field"><div class="k">Tanggal Berakhir</div><div class="v">{{ $r['retention_end_date'] ?? '-' }}</div></div>
            <div class="field span-3"><div class="k">Aktivitas Penghapusan</div><div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="sub-head"><span class="num">12 ·</span><span class="name">Keamanan Data</span></div>
    <div class="section">
        <div class="grid-3">
            <div class="field span-2">
                <div class="k">Kontrol Keamanan</div>
                <div class="v">
                    <ul class="dash">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="field"><div class="k">Riwayat Insiden</div><div class="v">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="sub-head"><span class="num">13 ·</span><span class="name">Klasifikasi Risiko</span></div>
    <div class="section">
        <div class="grid-3">
            <div class="field">
                <div class="k">Level Risiko</div>
                <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="field span-2"><div class="k">Justifikasi</div><div class="v">{{ $r['risk_justification'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="sheet-titleblock">
        <div><div class="k">Project</div><div class="v">{{ $orgName }}</div></div>
        <div><div class="k">Drawn</div><div class="v">{{ $r['pic']['name'] ?? '-' }}</div></div>
        <div><div class="k">Date</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
        <div><div class="k">Sheet</div><div class="v">A-07</div></div>
    </div>
</section>

</body>
</html>
