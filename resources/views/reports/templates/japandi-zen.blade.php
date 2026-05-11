<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400;1,500;1,600&family=Inter:wght@300;400;500;600;700&family=Noto+Serif+JP:wght@400;500&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #2a2620;
        }

        /* =========================================================
           Variabel palette Japandi Zen
           ========================================================= */
        :root {
            --paper: #ebe5d6;
            --paper-2: #e3dcc7;
            --ink: #2a2620;
            --ink-soft: #5a4f3e;
            --muted: #7a6f5e;
            --rule: #c4bba6;
            --rule-soft: #d5cdb6;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: var(--paper);
            color: var(--ink);
        }
        .page:last-child { page-break-after: auto; }

        /* =========================================================
           COVER PAGE — Japandi minimal, enso circle, kanji
           ========================================================= */
        .cover { padding: 28mm 26mm; }

        /* Enso circle — broken open at top, rotated */
        .enso-outer {
            position: absolute;
            top: 56mm; right: 24mm;
            width: 72mm; height: 72mm;
            border-radius: 50%;
            border: 1.4pt solid var(--ink);
            border-top-color: transparent;
            transform: rotate(18deg);
            z-index: 1;
        }
        .enso-inner {
            position: absolute;
            top: 60mm; right: 28mm;
            width: 66mm; height: 66mm;
            border-radius: 50%;
            border: 0.6pt solid var(--ink);
            opacity: .14;
            z-index: 1;
        }

        .cover-inner {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .kanji-eyebrow {
            font-family: 'Noto Serif JP', serif;
            font-size: 10pt;
            letter-spacing: 8pt;
            color: var(--muted);
        }
        .kanji-eyebrow .romaji {
            display: block;
            margin-top: 6pt;
            font-family: 'Inter', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            font-weight: 500;
        }

        .cover-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 70pt;
            font-weight: 300;
            line-height: 1.04;
            letter-spacing: -0.02em;
            margin: 0;
            color: var(--ink);
        }
        .cover-title em {
            font-style: italic;
            font-weight: 300;
            color: var(--ink-soft);
        }

        .cover-rule {
            width: 36pt;
            height: 1px;
            background: var(--ink);
            margin: 24pt 0 18pt;
        }

        .cover-quote {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 18pt;
            color: var(--ink-soft);
            max-width: 360pt;
            line-height: 1.4;
        }

        .cover-org {
            margin-top: 14pt;
            font-size: 9pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }

        .cover-meta {
            border-top: 1px solid var(--rule);
            padding-top: 24pt;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 28pt;
        }
        .cover-meta-item .lbl {
            font-size: 8pt;
            letter-spacing: 3.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }
        .cover-meta-item .val {
            margin-top: 6pt;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 18pt;
            font-weight: 400;
        }

        /* =========================================================
           CONTENT PAGES — Japanese kanji section numbers
           ========================================================= */
        .content-pad {
            padding: 22mm 24mm 18mm;
            position: relative;
            height: 100%;
        }

        .section {
            margin-bottom: 14pt;
            page-break-inside: avoid;
        }
        .section + .section { margin-top: 0; }

        .section-eyebrow {
            font-family: 'Noto Serif JP', serif;
            font-size: 10pt;
            letter-spacing: 6pt;
            color: var(--muted);
            margin-bottom: 4pt;
        }
        .section-eyebrow .romaji {
            font-family: 'Inter', sans-serif;
            letter-spacing: 3pt;
            font-size: 8pt;
            text-transform: uppercase;
            margin-left: 14pt;
            font-weight: 500;
        }

        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 32pt;
            font-weight: 300;
            margin: 0 0 6pt;
            letter-spacing: -0.02em;
            color: var(--ink);
        }
        .section-title em { font-style: italic; color: var(--ink-soft); }

        .section-rule {
            width: 28pt;
            height: 1px;
            background: var(--ink);
            margin: 8pt 0 14pt;
        }

        /* Field row */
        .row {
            display: grid;
            grid-template-columns: 130pt 1fr;
            gap: 22pt;
            padding: 9pt 0;
            border-bottom: 1px solid var(--rule-soft);
        }
        .row-label {
            font-size: 8.5pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
            padding-top: 3pt;
        }
        .row-value {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 14pt;
            font-weight: 400;
            color: var(--ink);
            line-height: 1.5;
        }
        .row-value.body {
            font-family: 'Inter', sans-serif;
            font-style: normal;
            font-size: 10pt;
            line-height: 1.7;
        }

        /* Inline pair (Japandi prose-style: "Tujuan — value") */
        .prose-pair {
            margin: 6pt 0;
            font-size: 11pt;
            line-height: 1.7;
        }
        .prose-pair .lbl {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--muted);
            font-size: 13pt;
        }

        ul.zen-list { list-style: none; margin: 0; padding: 0; }
        ul.zen-list li {
            padding: 3pt 0;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 13pt;
            display: flex;
            gap: 10pt;
        }
        ul.zen-list li::before {
            content: "—";
            color: var(--muted);
            flex-shrink: 0;
        }

        /* Minimal data table */
        table.zen-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8pt;
        }
        table.zen-table th {
            text-align: left;
            font-family: 'Inter', sans-serif;
            font-size: 7.5pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
            padding: 8pt 8pt 8pt 0;
            border-bottom: 1px solid var(--ink);
        }
        table.zen-table td {
            padding: 10pt 8pt 10pt 0;
            border-bottom: 1px solid var(--rule-soft);
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 12pt;
            vertical-align: top;
            line-height: 1.5;
        }
        table.zen-table td.seq {
            font-family: 'Inter', sans-serif;
            font-style: normal;
            font-size: 9pt;
            color: var(--muted);
            width: 24pt;
        }

        /* Q/A pair */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18pt 28pt;
            margin-top: 6pt;
        }
        .qa-item {
            padding: 8pt 0;
            border-top: 1px solid var(--rule-soft);
        }
        .qa-item .q {
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
            margin-bottom: 4pt;
        }
        .qa-item .a {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 14pt;
        }

        /* Pill chips for data categories — minimal outline only */
        .chip-row { display: flex; flex-wrap: wrap; gap: 6pt; margin-top: 6pt; }
        .chip {
            padding: 3pt 11pt;
            border: 0.6pt solid var(--ink);
            border-radius: 999pt;
            font-size: 9pt;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--ink);
            background: transparent;
        }
        .chip.pii {
            background: var(--ink);
            color: var(--paper);
            border-color: var(--ink);
        }

        .risk-badge {
            display: inline-block;
            padding: 4pt 16pt;
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            font-weight: 600;
            border: 1px solid var(--ink);
            border-radius: 999pt;
        }
        .risk-HIGH { background: var(--ink); color: var(--paper); }
        .risk-MEDIUM { background: transparent; color: var(--ink); }
        .risk-LOW { background: transparent; color: var(--muted); border-color: var(--muted); }

        /* Page footer with kanji center mark */
        .page-footer {
            position: absolute;
            bottom: 14mm;
            left: 24mm; right: 24mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            color: var(--muted);
        }
        .page-footer .center {
            font-family: 'Noto Serif JP', serif;
            font-size: 11pt;
            letter-spacing: 0;
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $kanji = ['一', '二', '三', '四', '五', '六', '七'];
    $romaji = ['Ichi', 'Ni', 'San', 'Shi', 'Go', 'Roku', 'Shichi'];
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page cover">
    <div class="enso-outer"></div>
    <div class="enso-inner"></div>

    <div class="cover-inner">
        <div class="kanji-eyebrow">
            記 録
            <span class="romaji">Record · Kiroku</span>
        </div>

        <div>
            <h1 class="cover-title">
                Catatan<br>
                <em>Pemrosesan</em><br>
                Data Pribadi
            </h1>
            <div class="cover-rule"></div>
            <div class="cover-quote">&ldquo;{{ $r['name'] ?? '-' }}&rdquo;</div>
            <div class="cover-org">{{ $orgName }}</div>
        </div>

        <div class="cover-meta">
            <div class="cover-meta-item">
                <div class="lbl">Nomor</div>
                <div class="val">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="cover-meta-item">
                <div class="lbl">Divisi</div>
                <div class="val">{{ $r['division'] ?? '-' }}</div>
            </div>
            <div class="cover-meta-item">
                <div class="lbl">Tanggal</div>
                <div class="val">{{ $r['date'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — I Deskripsi + II Pejabat PDP
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="section">
            <div class="section-eyebrow">{{ $kanji[0] }} <span class="romaji">Bagian Pertama</span></div>
            <h2 class="section-title">Deskripsi <em>Pemrosesan</em></h2>
            <div class="section-rule"></div>
            <div class="row"><div class="row-label">Nomor ROPA</div><div class="row-value">{{ $r['number'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Nama Pemrosesan</div><div class="row-value">{{ $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Divisi</div><div class="row-value">{{ $r['division'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Unit Kerja</div><div class="row-value">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Entitas</div><div class="row-value">{{ $orgName }}</div></div>
            <div class="row"><div class="row-label">Kategori Perusahaan</div><div class="row-value">{{ $r['category'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Deskripsi Singkat</div><div class="row-value body">{{ $r['description'] ?? '-' }}</div></div>
        </div>

        <div class="section" style="margin-top: 22pt;">
            <div class="section-eyebrow">{{ $kanji[1] }} <span class="romaji">Bagian Kedua</span></div>
            <h2 class="section-title">Pejabat PDP &amp; <em>PIC</em></h2>
            <div class="section-rule"></div>
            <table class="zen-table">
                <thead>
                    <tr>
                        <th style="width: 6%;">No.</th>
                        <th style="width: 28%;">Pejabat PDP (DPO)</th>
                        <th>Email</th>
                        <th style="width: 24%;">Telepon</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="seq">1</td>
                        <td>{{ $r['dpo']['name'] ?? '-' }}</td>
                        <td>{{ $r['dpo']['email'] ?? '-' }}</td>
                        <td>{{ $r['dpo']['phone'] ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>
            <table class="zen-table" style="margin-top: 10pt;">
                <thead>
                    <tr>
                        <th style="width: 6%;">No.</th>
                        <th style="width: 26%;">PIC / Process Owner</th>
                        <th>Jabatan</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="seq">1</td>
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
        <span class="center">{{ $kanji[1] }}</span>
        <span>02 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — III Informasi + IV Sistem + V Teknologi
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="section">
            <div class="section-eyebrow">{{ $kanji[2] }} <span class="romaji">Bagian Ketiga</span></div>
            <h2 class="section-title">Informasi <em>Pemrosesan</em></h2>
            <div class="section-rule"></div>
            <div class="prose-pair"><span class="lbl">Tujuan &mdash; </span>{{ $r['purpose'] ?? '-' }}</div>
            <div class="prose-pair"><span class="lbl">Aktivitas &mdash; </span>{{ $r['activity'] ?? '-' }}</div>
            <div class="prose-pair"><span class="lbl">Dasar Hukum &mdash; </span>{{ $r['legal_basis'] ?? '-' }}</div>
            <div class="prose-pair"><span class="lbl">Kategori &mdash; </span></div>
            <ul class="zen-list">
                @foreach($r['categories'] ?? [] as $cat)
                    <li>{{ $cat }}</li>
                @endforeach
            </ul>
        </div>

        <div class="section" style="margin-top: 18pt;">
            <div class="section-eyebrow">{{ $kanji[3] }} <span class="romaji">Bagian Keempat</span></div>
            <h2 class="section-title">Sistem <em>Informasi</em></h2>
            <div class="section-rule"></div>
            <table class="zen-table">
                <thead>
                    <tr>
                        <th style="width: 6%;">No.</th>
                        <th>Nama Sistem</th>
                        <th>Lokasi Penyimpanan</th>
                        <th>Lokasi Penggunaan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($r['systems'] ?? [] as $i => $sys)
                        <tr>
                            <td class="seq">{{ $i + 1 }}</td>
                            <td>{{ $sys['name'] ?? '-' }}</td>
                            <td>{{ $sys['loc'] ?? '-' }}</td>
                            <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center; color: var(--muted);">&mdash;</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="section" style="margin-top: 18pt;">
            <div class="section-eyebrow">{{ $kanji[4] }} <span class="romaji">Bagian Kelima</span></div>
            <h2 class="section-title">Teknologi &amp; <em>Pemrofilan</em></h2>
            <div class="section-rule"></div>
            <div class="qa-grid">
                <div class="qa-item"><div class="q">Bantuan AI</div><div class="a">{{ $r['uses_ai'] ?? '-' }}</div></div>
                <div class="qa-item"><div class="q">Keputusan Otomatis</div><div class="a">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
                <div class="qa-item"><div class="q">Teknologi Baru</div><div class="a">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
                <div class="qa-item"><div class="q">Tujuan Pemrofilan</div><div class="a">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="center">{{ $kanji[2] }}</span>
        <span>03 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — VI Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="section">
            <div class="section-eyebrow">{{ $kanji[5] }} <span class="romaji">Bagian Keenam</span></div>
            <h2 class="section-title">Pengumpulan <em>Data</em></h2>
            <div class="section-rule"></div>

            <div class="row">
                <div class="row-label">Jenis Subjek Data</div>
                <div class="row-value">
                    <ul class="zen-list">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <li>{{ $s }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Jumlah Subjek</div><div class="row-value">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Sumber Pengumpulan</div><div class="row-value">{{ $r['data_source'] ?? '-' }}</div></div>

            <div class="row" style="border-bottom: none;">
                <div class="row-label">Data Pribadi — Umum</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Data Pribadi — Spesifik</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="row-label">Data Pribadi — PII</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="chip pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="center">{{ $kanji[3] }}</span>
        <span>04 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — VII Penggunaan + VIII Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="section">
            <div class="section-eyebrow">{{ $kanji[6] }} <span class="romaji">Bagian Ketujuh</span></div>
            <h2 class="section-title">Penggunaan &amp; <em>Penyimpanan</em></h2>
            <div class="section-rule"></div>
            <div class="row">
                <div class="row-label">Kategori Pemroses</div>
                <div class="row-value">
                    <ul class="zen-list">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Pemroses Utama</div><div class="row-value">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Pihak Ketiga</div><div class="row-value">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="section" style="margin-top: 22pt;">
            <div class="section-eyebrow">八 <span class="romaji">Bagian Kedelapan</span></div>
            <h2 class="section-title">Pihak <em>Ketiga</em></h2>
            <div class="section-rule"></div>
            <table class="zen-table">
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
                            <td>{{ $tp['name'] ?? '-' }}</td>
                            <td>{{ $tp['address'] ?? '-' }}</td>
                            <td>{{ $tp['pic_name'] ?? '-' }}</td>
                            <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="font-family: 'Inter', sans-serif; font-style: normal; font-size: 8pt; color: var(--muted);">{{ $tp['pic_phone'] ?? '' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="center">{{ $kanji[4] }}</span>
        <span>05 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — IX Pengiriman + X Jenis Data Dikirim
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="section">
            <div class="section-eyebrow">九 <span class="romaji">Bagian Kesembilan</span></div>
            <h2 class="section-title">Pengiriman <em>Data</em></h2>
            <div class="section-rule"></div>
            <div class="qa-grid">
                <div class="qa-item"><div class="q">Penerima Internal</div><div class="a">{{ $r['recipients_internal'] ?? '-' }}</div></div>
                <div class="qa-item"><div class="q">Penerima Eksternal</div><div class="a">{{ $r['recipients_external'] ?? '-' }}</div></div>
                <div class="qa-item"><div class="q">Transfer Lintas Batas</div><div class="a">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
                <div class="qa-item"><div class="q">Negara Tujuan</div><div class="a">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
            </div>
        </div>

        <div class="section" style="margin-top: 22pt;">
            <div class="section-eyebrow">十 <span class="romaji">Bagian Kesepuluh</span></div>
            <h2 class="section-title">Jenis Data <em>Dikirim</em></h2>
            <div class="section-rule"></div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Umum</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Spesifik</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="row-label">PII</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="chip pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="center">{{ $kanji[5] }}</span>
        <span>06 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — XI Retensi + XII Keamanan + XIII Risiko
     ============================================================ --}}
<section class="page">
    <div class="content-pad">
        <div class="section">
            <div class="section-eyebrow">十一 <span class="romaji">Bagian Kesebelas</span></div>
            <h2 class="section-title">Retensi <em>Data</em></h2>
            <div class="section-rule"></div>
            <div class="row"><div class="row-label">Dokumen Terkait</div><div class="row-value">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Masa Retensi</div><div class="row-value">{{ $r['retention_period'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Tanggal Berlaku</div><div class="row-value">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} &mdash; {{ $r['retention_end_date'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Aktivitas Penghapusan</div><div class="row-value">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
        </div>

        <div class="section" style="margin-top: 18pt;">
            <div class="section-eyebrow">十二 <span class="romaji">Bagian Keduabelas</span></div>
            <h2 class="section-title">Keamanan <em>Data</em></h2>
            <div class="section-rule"></div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Kontrol Keamanan</div>
                <div class="row-value">
                    <ul class="zen-list">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Riwayat Insiden</div><div class="row-value">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>

        <div class="section" style="margin-top: 18pt;">
            <div class="section-eyebrow">十三 <span class="romaji">Bagian Ketigabelas</span></div>
            <h2 class="section-title">Klasifikasi <em>Risiko</em></h2>
            <div class="section-rule"></div>
            <div class="row">
                <div class="row-label">Level Risiko</div>
                <div class="row-value"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="row"><div class="row-label">Justifikasi</div><div class="row-value body">{{ $r['risk_justification'] ?? '-' }}</div></div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="center">終</span>
        <span>07 / {{ $totalPages }}</span>
    </div>
</section>

</body>
</html>
