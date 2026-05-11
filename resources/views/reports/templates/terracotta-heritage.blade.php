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
            font-family: 'Inter', sans-serif;
            color: #3a2418;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        :root {
            --terracotta: #b85b3c;
            --terracotta-dark: #7a4f1d;
            --cream: #f3e8d8;
            --cream-mid: #ead7bc;
            --dash: #c89878;
            --ink: #3a2418;
            --pii: #8a2828;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: var(--cream);
        }
        .page:last-child { page-break-after: auto; }

        .serif { font-family: 'Cormorant Garamond', serif; }

        /* ============================================================
           COVER — arch top + stamp circle + heritage feel
           ============================================================ */
        .arch {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 520pt;
            background: var(--terracotta);
            border-bottom-left-radius: 400pt;
            border-bottom-right-radius: 400pt;
        }
        .cover-top {
            position: relative;
            padding: 72pt 64pt;
            color: var(--cream);
            z-index: 2;
        }
        .anno-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            font-weight: 600;
        }
        .anno-row .right {
            display: flex;
            align-items: center;
            gap: 8pt;
            font-size: 11pt;
            letter-spacing: 2.2pt;
        }
        .anno-row .diamond {
            display: inline-block;
            width: 6pt; height: 6pt;
            background: var(--cream);
            transform: rotate(45deg);
        }

        .cover-hero {
            margin-top: 60pt;
            text-align: center;
        }
        .cover-h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 110pt;
            line-height: .9;
            font-weight: 400;
            letter-spacing: -.03em;
            font-style: italic;
            margin: 0;
            color: var(--cream);
        }
        .cover-eyebrow {
            font-size: 11pt;
            letter-spacing: 4.8pt;
            text-transform: uppercase;
            margin-top: 16pt;
            font-weight: 500;
            color: var(--cream);
        }
        .cover-quote {
            margin-top: 22pt;
            font-family: 'Cormorant Garamond', serif;
            font-size: 22pt;
            font-style: italic;
            max-width: 480pt;
            margin-left: auto;
            margin-right: auto;
            color: var(--cream);
        }

        /* Stamp circle */
        .stamp {
            position: absolute;
            top: 460pt;
            left: 50%;
            transform: translateX(-50%);
            width: 130pt; height: 130pt;
            border-radius: 50%;
            border: 2px solid var(--terracotta);
            background: var(--cream);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--terracotta);
            text-align: center;
            z-index: 3;
        }
        .stamp-num {
            font-family: 'Cormorant Garamond', serif;
            font-size: 28pt;
            font-style: italic;
            line-height: 1;
            font-weight: 500;
        }
        .stamp-code {
            font-size: 8pt;
            letter-spacing: 2.8pt;
            text-transform: uppercase;
            margin-top: 4pt;
        }
        .stamp-rule {
            width: 24pt;
            height: 1px;
            background: var(--terracotta);
            margin: 6pt auto;
        }
        .stamp-tag {
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
        }

        .cover-bottom {
            position: absolute;
            bottom: 64pt;
            left: 64pt; right: 64pt;
        }
        .disusun {
            text-align: center;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 18pt;
            color: var(--terracotta-dark);
        }
        .org-name {
            text-align: center;
            font-size: 17pt;
            font-weight: 600;
            margin-top: 6pt;
            color: var(--ink);
        }
        .cover-tri {
            margin-top: 28pt;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            border-top: 1px solid var(--terracotta);
            padding-top: 14pt;
            gap: 12pt;
        }
        .ct-item { text-align: center; }
        .ct-k {
            font-size: 9pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--terracotta);
            font-weight: 600;
        }
        .ct-v {
            margin-top: 4pt;
            font-size: 12pt;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--ink);
        }

        /* ============================================================
           CONTENT — dashed dividers, roman numerals, classical italic
           ============================================================ */
        .content-head {
            padding: 44pt 56pt 24pt;
            border-bottom: 2px solid var(--terracotta);
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .ch-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 36pt;
            font-style: italic;
            letter-spacing: -.01em;
            margin: 0;
            color: var(--ink);
        }
        .ch-ref {
            font-size: 10pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--terracotta);
            font-weight: 600;
        }

        .body {
            padding: 28pt 56pt;
        }

        /* Section subheader within page */
        .subsec {
            font-family: 'Cormorant Garamond', serif;
            font-size: 28pt;
            font-style: italic;
            border-bottom: 2px solid var(--terracotta);
            padding-bottom: 10pt;
            margin: 24pt 0 18pt;
            color: var(--ink);
        }

        /* Field rows with dashed dividers */
        .field {
            display: grid;
            grid-template-columns: 200pt 1fr;
            gap: 16pt;
            padding: 10pt 0;
            border-bottom: 1px dashed var(--dash);
        }
        .field:last-child { border-bottom: none; }
        .field-k {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 14pt;
            color: var(--terracotta-dark);
        }
        .field-v {
            font-size: 13pt;
            line-height: 1.55;
            color: var(--ink);
        }

        /* Description box */
        .desc-box {
            margin-top: 18pt;
            padding: 18pt 22pt;
            background: var(--cream-mid);
            border-left: 4pt solid var(--terracotta);
        }
        .desc-box-k {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 14pt;
            color: var(--terracotta-dark);
            margin-bottom: 6pt;
        }
        .desc-box-v {
            font-size: 13pt;
            line-height: 1.6;
            color: var(--ink);
        }

        /* Roman numeral list */
        .roman-list { margin-top: 10pt; }
        .roman-list .row {
            display: flex;
            gap: 10pt;
            padding: 5pt 0;
        }
        .roman-list .row .num {
            color: var(--terracotta);
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 14pt;
            min-width: 24pt;
        }
        .roman-list .row .txt {
            font-size: 13pt;
            color: var(--ink);
            line-height: 1.5;
        }

        /* Two-col layout for tujuan / dasar hukum */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18pt;
        }
        .two-col-k {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--terracotta-dark);
            font-size: 14pt;
        }
        .two-col-v {
            font-size: 13pt;
            margin-top: 4pt;
            line-height: 1.55;
            color: var(--ink);
        }

        /* Table - heritage feel */
        table.h-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12pt;
            margin-top: 10pt;
        }
        table.h-table th {
            background: var(--terracotta);
            color: var(--cream);
            text-align: left;
            padding: 10pt 12pt;
            font-size: 10pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
        }
        table.h-table td {
            padding: 10pt 12pt;
            border-bottom: 1px dashed var(--dash);
            vertical-align: top;
            line-height: 1.5;
        }
        table.h-table tr:last-child td { border-bottom: 2px solid var(--terracotta); }
        table.h-table .seq {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--terracotta);
            font-size: 14pt;
            width: 24pt;
        }

        /* Pills */
        .pill-row { margin-top: 4pt; }
        .pill {
            display: inline-block;
            padding: 4pt 12pt;
            background: var(--cream-mid);
            color: var(--terracotta-dark);
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 12.5pt;
            margin: 2pt 3pt 2pt 0;
            border: 1px solid var(--dash);
        }
        .pill.pii {
            background: #f3d6d6;
            color: var(--pii);
            border-color: var(--pii);
        }

        /* QA item */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14pt;
        }
        .qa {
            padding: 12pt 16pt;
            border: 1px dashed var(--dash);
            background: rgba(184,91,60,.04);
        }
        .qa-k {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--terracotta-dark);
            font-size: 13pt;
        }
        .qa-v {
            margin-top: 4pt;
            font-size: 13pt;
            color: var(--ink);
        }

        .risk-badge {
            display: inline-block;
            padding: 6pt 16pt;
            font-size: 10pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--cream);
        }
        .risk-HIGH { background: var(--pii); }
        .risk-MEDIUM { background: var(--terracotta); }
        .risk-LOW { background: #4a6d3f; }

        .page-footer {
            position: absolute;
            bottom: 28pt;
            left: 56pt; right: 56pt;
            text-align: center;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 12pt;
            color: var(--terracotta);
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $numberParts = explode('-', (string)($r['number'] ?? ''));
    $stampNum = end($numberParts) ?: '—';
    $romans = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page">
    <div class="arch"></div>
    <div class="cover-top">
        <div class="anno-row">
            <span>Anno &middot; {{ date('Y') }}</span>
            <span class="right"><span class="diamond"></span> Ropa Export</span>
        </div>
        <div class="cover-hero">
            <h1 class="cover-h1">Ropa</h1>
            <div class="cover-eyebrow">Record of Processing Activities</div>
            <div class="cover-quote">&ldquo;{{ $r['name'] ?? '-' }}&rdquo;</div>
        </div>
    </div>

    <div class="stamp">
        <div class="stamp-num">{{ $stampNum }}</div>
        <div class="stamp-code">{{ $r['number'] ?? '-' }}</div>
        <div class="stamp-rule"></div>
        <div class="stamp-tag">Sealed</div>
    </div>

    <div class="cover-bottom">
        <div class="disusun">Disusun oleh</div>
        <div class="org-name">{{ $orgName }}</div>
        <div class="cover-tri">
            <div class="ct-item">
                <div class="ct-k">Divisi</div>
                <div class="ct-v">{{ $r['division'] ?? '-' }}</div>
            </div>
            <div class="ct-item">
                <div class="ct-k">Tanggal</div>
                <div class="ct-v">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div class="ct-item">
                <div class="ct-k">Dasar</div>
                <div class="ct-v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi + DPO/PIC
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <h2 class="ch-title">I. Deskripsi Pemrosesan</h2>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="field"><div class="field-k">Nomor ROPA</div><div class="field-v">{{ $r['number'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Nama Pemrosesan</div><div class="field-v">{{ $r['name'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Divisi &middot; Unit Kerja</div><div class="field-v">{{ $r['division'] ?? '-' }} &middot; {{ $r['unit'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Entitas</div><div class="field-v">{{ $orgName }}</div></div>
        <div class="field"><div class="field-k">Kategori Perusahaan</div><div class="field-v">{{ $r['category'] ?? '-' }}</div></div>

        <div class="desc-box">
            <div class="desc-box-k">Deskripsi Singkat</div>
            <div class="desc-box-v">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="subsec">II. Pejabat PDP &amp; PIC</div>
        <div class="two-col">
            <div>
                <div class="two-col-k">Data Protection Officer</div>
                <div class="two-col-v">
                    {{ $r['dpo']['name'] ?? '-' }}<br>
                    <em style="color: var(--terracotta-dark);">{{ $r['dpo']['email'] ?? '-' }}</em>
                    @if(!empty($r['dpo']['phone']))<br><em style="color: var(--terracotta-dark);">{{ $r['dpo']['phone'] }}</em>@endif
                </div>
            </div>
            <div>
                <div class="two-col-k">Process Owner / PIC</div>
                <div class="two-col-v">
                    {{ $r['pic']['name'] ?? '-' }} &mdash; {{ $r['pic']['role'] ?? '' }}<br>
                    <em style="color: var(--terracotta-dark);">{{ $r['pic']['email'] ?? '-' }}</em>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        ~ {{ $orgName }} &middot; {{ $r['number'] ?? '-' }} &middot; 02 ~
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan + Sistem + Teknologi
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <h2 class="ch-title">III. Informasi Pemrosesan</h2>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="two-col">
            <div>
                <div class="two-col-k">Tujuan</div>
                <div class="two-col-v">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div>
                <div class="two-col-k">Dasar Hukum</div>
                <div class="two-col-v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
        </div>

        <div style="margin-top: 16pt;">
            <div class="two-col-k">Aktivitas Pemrosesan</div>
            <div class="two-col-v">{{ $r['activity'] ?? '-' }}</div>
        </div>

        <div style="margin-top: 16pt;">
            <div class="two-col-k" style="margin-bottom: 4pt;">Kategori Pemrosesan</div>
            <div class="roman-list">
                @foreach($r['categories'] ?? [] as $i => $c)
                    <div class="row">
                        <span class="num">{{ $romans[$i] ?? ($i + 1) }}.</span>
                        <span class="txt">{{ $c }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="subsec">IV. Sistem Informasi Terkait</div>
        <table class="h-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Penyimpanan</th>
                    <th>Lokasi Penggunaan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ $romans[$i] ?? ($i + 1) }}.</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="subsec">V. Teknologi &amp; Pemrofilan</div>
        <div class="qa-grid">
            <div class="qa"><div class="qa-k">Bantuan AI</div><div class="qa-v">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Keputusan Otomatis</div><div class="qa-v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Teknologi Baru</div><div class="qa-v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Tujuan Pemrofilan</div><div class="qa-v">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="page-footer">
        ~ {{ $orgName }} &middot; {{ $r['number'] ?? '-' }} &middot; 03 ~
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <h2 class="ch-title">VI. Pengumpulan Data</h2>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="field"><div class="field-k">Jenis Subjek Data</div><div class="field-v">
            <div class="roman-list">
                @foreach($r['data_subjects'] ?? [] as $i => $s)
                    <div class="row">
                        <span class="num">{{ $romans[$i] ?? ($i + 1) }}.</span>
                        <span class="txt">{{ $s }}</span>
                    </div>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Jumlah Subjek Data</div><div class="field-v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Sumber Pengumpulan</div><div class="field-v">{{ $r['data_source'] ?? '-' }}</div></div>

        <div class="subsec">Klasifikasi Data Pribadi</div>
        <div class="field"><div class="field-k">Data Umum</div><div class="field-v">
            <div class="pill-row">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Data Spesifik</div><div class="field-v">
            <div class="pill-row">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Data PII (Sensitif)</div><div class="field-v">
            <div class="pill-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="pill pii">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
    </div>

    <div class="page-footer">
        ~ {{ $orgName }} &middot; {{ $r['number'] ?? '-' }} &middot; 04 ~
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <h2 class="ch-title">VII. Penyimpanan &amp; Pihak Ketiga</h2>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="field"><div class="field-k">Kategori Pemroses</div><div class="field-v">
            <div class="roman-list">
                @foreach($r['processor_role'] ?? [] as $i => $role)
                    <div class="row">
                        <span class="num">{{ $romans[$i] ?? ($i + 1) }}.</span>
                        <span class="txt">{{ $role }}</span>
                    </div>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Pemroses Utama</div><div class="field-v">{{ $r['processor_entity'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Pihak Ketiga Terlibat</div><div class="field-v"><em>{{ $r['has_third_party'] ?? '-' }}</em></div></div>

        @if(!empty($r['third_parties']))
        <div class="subsec">VIII. Daftar Pihak Ketiga</div>
        <table class="h-table">
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
                        <td class="seq">{{ $romans[$i] ?? ($i + 1) }}.</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: var(--terracotta-dark); font-size: 10pt;"><em>{{ $tp['pic_phone'] ?? '' }}</em></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div class="page-footer">
        ~ {{ $orgName }} &middot; {{ $r['number'] ?? '-' }} &middot; 05 ~
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <h2 class="ch-title">IX. Pengiriman Data</h2>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="qa-grid">
            <div class="qa"><div class="qa-k">Penerima Internal</div><div class="qa-v">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Penerima Eksternal</div><div class="qa-v">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Transfer Lintas Negara</div><div class="qa-v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Negara Tujuan</div><div class="qa-v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>

        <div class="subsec">X. Jenis Data yang Dikirim</div>
        <div class="field"><div class="field-k">Umum</div><div class="field-v">
            <div class="pill-row">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Spesifik</div><div class="field-v">
            <div class="pill-row">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">PII</div><div class="field-v">
            <div class="pill-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="pill pii">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
    </div>

    <div class="page-footer">
        ~ {{ $orgName }} &middot; {{ $r['number'] ?? '-' }} &middot; 06 ~
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi, Keamanan, Risiko
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <h2 class="ch-title">XI. Retensi &amp; Keamanan</h2>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="body">
        <div class="field"><div class="field-k">Nama Dokumen Terkait</div><div class="field-v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Masa Retensi</div><div class="field-v">{{ $r['retention_period'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Tanggal Berlaku</div><div class="field-v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} s/d {{ $r['retention_end_date'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Aktivitas Penghapusan</div><div class="field-v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>

        <div class="subsec">XII. Keamanan Data</div>
        <div class="field"><div class="field-k">Kontrol Keamanan</div><div class="field-v">
            <div class="roman-list">
                @foreach($r['controls'] ?? [] as $i => $ctrl)
                    <div class="row">
                        <span class="num">{{ $romans[$i] ?? ($i + 1) }}.</span>
                        <span class="txt">{{ $ctrl }}</span>
                    </div>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Riwayat Insiden</div><div class="field-v"><em>{{ $r['has_past_incident'] ?? '-' }}</em></div></div>

        <div class="subsec">XIII. Klasifikasi Risiko</div>
        <div class="field"><div class="field-k">Level Risiko</div><div class="field-v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div></div>
        <div class="field"><div class="field-k">Justifikasi</div><div class="field-v">{{ $r['risk_justification'] ?? '-' }}</div></div>
    </div>

    <div class="page-footer">
        ~ {{ $orgName }} &middot; {{ $r['number'] ?? '-' }} &middot; 07 ~
    </div>
</section>

</body>
</html>
