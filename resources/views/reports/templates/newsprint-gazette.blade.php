<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,700;0,800;0,900;1,400;1,700;1,900&family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --cream: #ede4d0;
            --cream-deep: #e3d8be;
            --ink: #1a1612;
            --rust: #7a6b4f;
            --rule: #b5a98a;
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

        .serif { font-family: 'Playfair Display', 'Cormorant Garamond', serif; }
        .corm { font-family: 'Cormorant Garamond', serif; }

        /* =========================================================
           COVER — Newspaper Masthead
           ========================================================= */
        .cover-strip {
            padding: 32pt 48pt 12pt;
            display: flex;
            justify-content: space-between;
            font-size: 9.5pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--ink);
            font-weight: 500;
        }
        .masthead {
            padding: 20pt 48pt 14pt;
            text-align: center;
            border-bottom: 4pt double var(--ink);
        }
        .masthead-title {
            font-family: 'Playfair Display', serif;
            font-size: 110pt;
            font-weight: 900;
            line-height: .9;
            letter-spacing: -.04em;
            margin: 0;
            color: var(--ink);
        }
        .masthead-sub {
            font-style: italic;
            font-size: 13pt;
            margin-top: 8pt;
            font-family: 'Cormorant Garamond', serif;
            color: var(--ink);
        }

        .cover-cols {
            padding: 18pt 48pt 0;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20pt;
        }
        .cover-cols > div { padding-right: 18pt; }
        .cover-cols > div:not(:last-child) { border-right: 1px solid var(--rule); }
        .col-eyebrow {
            font-size: 9.5pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--rust);
            font-weight: 700;
        }
        .col-headline {
            font-family: 'Playfair Display', serif;
            font-size: 24pt;
            font-weight: 700;
            line-height: 1.05;
            margin: 10pt 0 12pt;
            letter-spacing: -.01em;
            color: var(--ink);
        }
        .col-lead {
            font-size: 11.5pt;
            line-height: 1.55;
            color: var(--ink);
        }
        .col-lead strong { font-weight: 700; }

        .key-facts {
            margin-top: 10pt;
            font-size: 11.5pt;
            line-height: 1.6;
        }
        .key-facts .kf {
            border-bottom: 1px dashed var(--rule);
            padding: 4pt 0;
        }
        .key-facts .kf:last-child { border-bottom: none; }

        .editor-quote {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 14pt;
            line-height: 1.55;
            margin-top: 10pt;
            color: var(--ink);
        }
        .editor-credit {
            margin-top: 12pt;
            font-size: 10.5pt;
            color: var(--rust);
        }

        /* Bottom black bar */
        .gazette-bar {
            position: absolute;
            bottom: 0;
            left: 0; right: 0;
            padding: 14pt 48pt;
            background: var(--ink);
            color: var(--cream);
            display: flex;
            justify-content: space-between;
            font-size: 9.5pt;
            letter-spacing: .24em;
            text-transform: uppercase;
        }

        /* Giant ghost R */
        .ghost-R {
            position: absolute;
            bottom: 30pt;
            left: 30pt;
            font-family: 'Playfair Display', serif;
            font-size: 280pt;
            font-weight: 900;
            line-height: .75;
            color: rgba(26,22,18,.06);
            pointer-events: none;
            z-index: 0;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .sec-head {
            padding: 32pt 48pt 14pt;
            border-bottom: 4pt double var(--ink);
        }
        .sec-head-meta {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--rust);
        }
        .sec-head-title {
            font-family: 'Playfair Display', serif;
            font-size: 50pt;
            font-weight: 900;
            margin: 8pt 0 0;
            line-height: 1;
            letter-spacing: -.02em;
            color: var(--ink);
        }
        .sec-head-title em { font-weight: 400; font-style: italic; }
        .sec-head-sub {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 13pt;
            margin-top: 8pt;
            color: var(--rust);
        }

        .body { padding: 22pt 48pt 60pt; }

        .lead-cols {
            column-count: 2;
            column-gap: 28pt;
            font-size: 12pt;
            line-height: 1.6;
            color: var(--ink);
        }
        .lead-cols p { margin: 0 0 10pt; }
        .dropcap {
            float: left;
            font-family: 'Playfair Display', serif;
            font-size: 52pt;
            line-height: .85;
            margin-right: 6pt;
            color: var(--ink);
            font-weight: 700;
        }

        .fact-bar {
            margin: 18pt 0;
            padding: 14pt 0;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16pt;
        }
        .fact-bar .k {
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--rust);
            font-weight: 700;
        }
        .fact-bar .v {
            font-family: 'Playfair Display', serif;
            font-size: 14pt;
            margin-top: 4pt;
            font-weight: 500;
            color: var(--ink);
            line-height: 1.2;
        }

        .sub-h {
            font-family: 'Playfair Display', serif;
            font-size: 30pt;
            font-weight: 900;
            margin: 16pt 0 12pt;
            letter-spacing: -.02em;
            color: var(--ink);
        }
        .sub-h em { font-style: italic; font-weight: 400; }

        table.gz {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
            border-top: 2px solid var(--ink);
            border-bottom: 2px solid var(--ink);
        }
        table.gz th {
            background: transparent;
            color: var(--ink);
            text-align: left;
            padding: 8pt 10pt;
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 700;
            border-bottom: 1px solid var(--ink);
            font-family: 'Inter', sans-serif;
        }
        table.gz td {
            padding: 8pt 10pt;
            border-bottom: 1px dashed var(--rule);
            vertical-align: top;
            line-height: 1.5;
            color: var(--ink);
            font-family: 'Cormorant Garamond', serif;
            font-size: 12.5pt;
        }
        table.gz tr:last-child td { border-bottom: none; }
        table.gz .seq { width: 30pt; font-family: 'Playfair Display', serif; font-weight: 700; }

        .pill-row { display: flex; flex-wrap: wrap; gap: 8pt; }
        .pill {
            padding: 4pt 12pt;
            background: var(--cream-deep);
            border: 1px solid var(--ink);
            font-size: 10.5pt;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--ink);
        }
        .pill.pii {
            background: var(--ink);
            color: var(--cream);
            font-style: normal;
            font-weight: 600;
        }

        ul.serif-list { margin: 0; padding: 0; list-style: none; font-family: 'Cormorant Garamond', serif; font-size: 13pt; line-height: 1.6; }
        ul.serif-list li {
            padding: 3pt 0 3pt 18pt;
            position: relative;
        }
        ul.serif-list li::before {
            content: "§";
            position: absolute;
            left: 0;
            color: var(--rust);
            font-family: 'Playfair Display', serif;
        }

        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid var(--ink);
        }
        .qa-cell {
            padding: 12pt 14pt;
            border-right: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
        }
        .qa-cell:nth-child(2n) { border-right: none; }
        .qa-cell:nth-last-child(-n+2) { border-bottom: none; }
        .qa-cell .q {
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--rust);
            font-weight: 700;
        }
        .qa-cell .a {
            font-family: 'Playfair Display', serif;
            font-size: 16pt;
            margin-top: 6pt;
            color: var(--ink);
            line-height: 1.25;
        }

        .row-line {
            display: grid;
            grid-template-columns: 180pt 1fr;
            padding: 8pt 0;
            border-bottom: 1px dashed var(--rule);
            font-size: 12pt;
        }
        .row-line:last-child { border-bottom: none; }
        .row-line .k {
            font-size: 9pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--rust);
            font-weight: 700;
            padding-top: 4pt;
        }
        .row-line .v {
            font-family: 'Cormorant Garamond', serif;
            font-size: 13pt;
            line-height: 1.5;
            color: var(--ink);
        }

        .risk-badge {
            display: inline-block;
            padding: 6pt 16pt;
            font-family: 'Playfair Display', serif;
            font-size: 18pt;
            font-weight: 900;
            font-style: italic;
            letter-spacing: -.01em;
        }
        .risk-HIGH { background: var(--ink); color: var(--cream); }
        .risk-MEDIUM { background: var(--cream-deep); color: var(--ink); border: 2pt solid var(--ink); }
        .risk-LOW { background: transparent; color: var(--rust); border: 1pt solid var(--rust); }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
@endphp

{{-- PAGE 1 — COVER GAZETTE --}}
<section class="page">
    <div class="cover-strip">
        <span>Edisi {{ $r['number'] ?? '-' }}</span>
        <span>★ ★ ★</span>
        <span>{{ $r['date'] ?? '-' }}</span>
    </div>
    <div class="masthead">
        <h1 class="masthead-title">The Ropa Gazette</h1>
        <div class="masthead-sub">“All the data processing fit to print” · {{ $orgName }}</div>
    </div>

    <div class="cover-cols">
        <div>
            <div class="col-eyebrow">Headlines</div>
            <h2 class="col-headline">{{ $r['name'] ?? '-' }}</h2>
            <div class="col-lead">
                <strong>JAKARTA</strong> — {{ $r['description'] ?? '-' }}
            </div>
        </div>
        <div>
            <div class="col-eyebrow">Key Facts</div>
            <div class="key-facts">
                <div class="kf"><strong>No.</strong> {{ $r['number'] ?? '-' }}</div>
                <div class="kf"><strong>Divisi.</strong> {{ $r['division'] ?? '-' }}</div>
                <div class="kf"><strong>Unit.</strong> {{ $r['unit'] ?? '-' }}</div>
                <div class="kf"><strong>DPO.</strong> {{ $r['dpo']['name'] ?? '-' }}</div>
                <div class="kf"><strong>PIC.</strong> {{ $r['pic']['name'] ?? '-' }}</div>
            </div>
        </div>
        <div>
            <div class="col-eyebrow">Editor's Note</div>
            <div class="editor-quote">
                “Dokumen ini menyatakan komitmen kami terhadap perlindungan data pribadi sesuai UU PDP 27/2022.”
            </div>
            <div class="editor-credit">— Data Protection Officer</div>
        </div>
    </div>

    <div class="ghost-R">R</div>

    <div class="gazette-bar">
        <span>Pemrosesan Resmi</span>
        <span>Halaman 1</span>
        <span>{{ \Illuminate\Support\Str::limit($r['legal_basis'] ?? '-', 40) }}</span>
    </div>
</section>

{{-- PAGE 2 — Sec I + II — Deskripsi & Pejabat --}}
<section class="page">
    <div class="sec-head">
        <div class="sec-head-meta">
            <span>The Ropa Gazette · p.02</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2 class="sec-head-title">Deskripsi <em>Pemrosesan</em></h2>
        <div class="sec-head-sub">Section I — Identity &amp; Scope of the Processing</div>
    </div>
    <div class="body">
        <div class="lead-cols">
            <p>
                <span class="dropcap">D</span>okumen ini mencatat aktivitas pemrosesan data pribadi berjudul <strong>{{ $r['name'] ?? '-' }}</strong>, yang dilaksanakan oleh divisi <strong>{{ $r['division'] ?? '-' }}</strong>, khususnya unit <strong>{{ $r['unit'] ?? '-' }}</strong>.
            </p>
            <p>Pemrosesan tersebut dilaksanakan oleh <strong>{{ $orgName }}</strong> dalam kapasitas sebagai <em>{{ $r['category'] ?? '-' }}</em>. Tujuan utama pemrosesan adalah {{ \Illuminate\Support\Str::lower($r['purpose'] ?? '-') }}.</p>
            <p>{{ $r['activity'] ?? '-' }}</p>
            <p>Dasar hukum yang menjadi landasan pemrosesan adalah <strong>{{ $r['legal_basis'] ?? '-' }}</strong>, sebagaimana diatur dalam ketentuan perundang-undangan yang berlaku.</p>
        </div>
        <div class="fact-bar">
            <div><div class="k">No.</div><div class="v">{{ $r['number'] ?? '-' }}</div></div>
            <div><div class="k">Divisi</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
            <div><div class="k">Tanggal</div><div class="v">{{ $r['date'] ?? '-' }}</div></div>
            <div><div class="k">Dasar</div><div class="v">{{ \Illuminate\Support\Str::limit($r['legal_basis'] ?? '-', 24) }}</div></div>
        </div>

        <h3 class="sub-h">Pejabat <em>Pelindungan Data</em></h3>
        <table class="gz">
            <thead>
                <tr>
                    <th style="width: 8%;">№</th>
                    <th>Pejabat PDP (DPO)</th>
                    <th>Email</th>
                    <th>Telepon</th>
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
        <table class="gz" style="margin-top: 14pt;">
            <thead>
                <tr>
                    <th style="width: 8%;">№</th>
                    <th>Process Owner / PIC</th>
                    <th>Jabatan</th>
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

    <div class="gazette-bar">
        <span>{{ $orgName }}</span>
        <span>Halaman 2 / {{ $totalPages }}</span>
        <span>Confidential</span>
    </div>
</section>

{{-- PAGE 3 — Sec III + IV + V --}}
<section class="page">
    <div class="sec-head">
        <div class="sec-head-meta">
            <span>The Ropa Gazette · p.03</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2 class="sec-head-title">Informasi <em>Pemrosesan</em></h2>
        <div class="sec-head-sub">Section III — Purpose, Activity &amp; Legal Basis</div>
    </div>
    <div class="body">
        <div class="row-line"><div class="k">Tujuan</div><div class="v">{{ $r['purpose'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Aktivitas</div><div class="v">{{ $r['activity'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Dasar Hukum</div><div class="v">{{ $r['legal_basis'] ?? '-' }}</div></div>

        <h3 class="sub-h">Kategori <em>Pemrosesan</em></h3>
        <ul class="serif-list" style="column-count: 2; column-gap: 28pt;">
            @foreach($r['categories'] ?? [] as $c)
                <li>{{ $c }}</li>
            @endforeach
        </ul>

        <h3 class="sub-h">Sistem <em>Informasi</em></h3>
        <table class="gz">
            <thead>
                <tr>
                    <th style="width: 8%;">№</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Penyimpanan</th>
                    <th>Lokasi Penggunaan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ $i + 1 }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3 class="sub-h">Teknologi &amp; <em>Pemrofilan</em></h3>
        <div class="qa-grid">
            <div class="qa-cell">
                <div class="q">Bantuan AI</div>
                <div class="a">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="q">Keputusan Otomatis</div>
                <div class="a">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="q">Teknologi Baru</div>
                <div class="a">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="q">Tujuan Pemrofilan</div>
                <div class="a">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="gazette-bar">
        <span>{{ $orgName }}</span>
        <span>Halaman 3 / {{ $totalPages }}</span>
        <span>Confidential</span>
    </div>
</section>

{{-- PAGE 4 — Sec VI Pengumpulan Data --}}
<section class="page">
    <div class="sec-head">
        <div class="sec-head-meta">
            <span>The Ropa Gazette · p.04</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2 class="sec-head-title">Pengumpulan <em>Data</em></h2>
        <div class="sec-head-sub">Section VI — Data Subjects, Volume &amp; Sources</div>
    </div>
    <div class="body">
        <div class="row-line">
            <div class="k">Jenis Subjek Data</div>
            <div class="v">
                <ul class="serif-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="row-line"><div class="k">Jumlah Subjek Data</div><div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Sumber Pengumpulan</div><div class="v">{{ $r['data_source'] ?? '-' }}</div></div>

        <h3 class="sub-h">Data Pribadi — <em>Umum</em></h3>
        <div class="pill-row">
            @foreach($r['data_general'] ?? [] as $d)
                <span class="pill">{{ $d }}</span>
            @endforeach
        </div>

        <h3 class="sub-h">Data Pribadi — <em>Spesifik</em></h3>
        <div class="pill-row">
            @foreach($r['data_specific'] ?? [] as $d)
                <span class="pill">{{ $d }}</span>
            @endforeach
        </div>

        <h3 class="sub-h">Data Pribadi — <em>PII</em></h3>
        <div class="pill-row">
            @foreach($r['data_pii'] ?? [] as $d)
                <span class="pill pii">{{ $d }}</span>
            @endforeach
        </div>
    </div>

    <div class="gazette-bar">
        <span>{{ $orgName }}</span>
        <span>Halaman 4 / {{ $totalPages }}</span>
        <span>Confidential</span>
    </div>
</section>

{{-- PAGE 5 — Sec VII + VIII --}}
<section class="page">
    <div class="sec-head">
        <div class="sec-head-meta">
            <span>The Ropa Gazette · p.05</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2 class="sec-head-title">Penggunaan &amp; <em>Penyimpanan</em></h2>
        <div class="sec-head-sub">Section VII — Processors &amp; Third Parties</div>
    </div>
    <div class="body">
        <div class="row-line">
            <div class="k">Kategori Pemroses</div>
            <div class="v">
                <ul class="serif-list">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <li>{{ $role }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="row-line"><div class="k">Pemroses Utama</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Pihak Ketiga Terlibat</div><div class="v">{{ $r['has_third_party'] ?? '-' }}</div></div>

        @if(!empty($r['third_parties']))
        <h3 class="sub-h">Pihak <em>Ketiga</em></h3>
        <table class="gz">
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
                        <td class="seq">{{ $i + 1 }}</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>
                            {{ $tp['pic_email'] ?? '-' }}<br>
                            <span style="color: var(--rust); font-size: 10pt; font-family: 'Inter', sans-serif;">{{ $tp['pic_phone'] ?? '' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div class="gazette-bar">
        <span>{{ $orgName }}</span>
        <span>Halaman 5 / {{ $totalPages }}</span>
        <span>Confidential</span>
    </div>
</section>

{{-- PAGE 6 — Sec IX + X --}}
<section class="page">
    <div class="sec-head">
        <div class="sec-head-meta">
            <span>The Ropa Gazette · p.06</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2 class="sec-head-title">Pengiriman <em>Data</em></h2>
        <div class="sec-head-sub">Section IX — Recipients &amp; Cross-Border Transfer</div>
    </div>
    <div class="body">
        <div class="qa-grid">
            <div class="qa-cell">
                <div class="q">Penerima Internal</div>
                <div class="a">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="q">Penerima Eksternal</div>
                <div class="a">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="q">Transfer Lintas Negara</div>
                <div class="a">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="q">Negara Tujuan</div>
                <div class="a">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
            </div>
        </div>

        <h3 class="sub-h">Jenis Data — <em>Umum</em></h3>
        <div class="pill-row">
            @foreach($r['data_general'] ?? [] as $d)
                <span class="pill">{{ $d }}</span>
            @endforeach
        </div>
        <h3 class="sub-h">Jenis Data — <em>Spesifik</em></h3>
        <div class="pill-row">
            @foreach($r['data_specific'] ?? [] as $d)
                <span class="pill">{{ $d }}</span>
            @endforeach
        </div>
        <h3 class="sub-h">Jenis Data — <em>PII</em></h3>
        <div class="pill-row">
            @foreach($r['data_pii'] ?? [] as $d)
                <span class="pill pii">{{ $d }}</span>
            @endforeach
        </div>
    </div>

    <div class="gazette-bar">
        <span>{{ $orgName }}</span>
        <span>Halaman 6 / {{ $totalPages }}</span>
        <span>Confidential</span>
    </div>
</section>

{{-- PAGE 7 — Sec XI + XII + XIII --}}
<section class="page">
    <div class="sec-head">
        <div class="sec-head-meta">
            <span>The Ropa Gazette · p.07</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2 class="sec-head-title">Retensi, Keamanan &amp; <em>Risiko</em></h2>
        <div class="sec-head-sub">Final Section — Retention, Safeguards &amp; Risk Classification</div>
    </div>
    <div class="body">
        <h3 class="sub-h">Retensi <em>Data</em></h3>
        <div class="row-line"><div class="k">Nama Dokumen</div><div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Masa Retensi</div><div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Tanggal Berlaku</div><div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} s/d {{ $r['retention_end_date'] ?? '-' }}</div></div>
        <div class="row-line"><div class="k">Aktivitas Penghapusan</div><div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>

        <h3 class="sub-h">Keamanan <em>Data</em></h3>
        <div class="row-line">
            <div class="k">Kontrol Keamanan</div>
            <div class="v">
                <ul class="serif-list">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <li>{{ $ctrl }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="row-line"><div class="k">Riwayat Insiden</div><div class="v">{{ $r['has_past_incident'] ?? '-' }}</div></div>

        <h3 class="sub-h">Klasifikasi <em>Risiko</em></h3>
        <div class="row-line">
            <div class="k">Level Risiko</div>
            <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
        </div>
        <div class="row-line"><div class="k">Justifikasi</div><div class="v">{{ $r['risk_justification'] ?? '-' }}</div></div>
    </div>

    <div class="gazette-bar">
        <span>{{ $orgName }}</span>
        <span>Halaman 7 / {{ $totalPages }}</span>
        <span>— Fin —</span>
    </div>
</section>

</body>
</html>
