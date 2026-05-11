<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500;1,600&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* =========================================================
           Variabel warna palette Midnight Indigo
           ========================================================= */
        :root {
            --ink: #0f1530;
            --paper: #faf7ee;
            --gold: #c4a054;
            --gold-soft: rgba(196,160,84,.35);
            --gold-faint: rgba(196,160,84,.15);
            --ivory: #e9e3cf;
            --ivory-soft: rgba(233,227,207,.7);
            --ivory-faint: rgba(233,227,207,.55);
            --rule: rgba(15,21,48,.12);
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* =========================================================
           COVER PAGE
           ========================================================= */
        .cover {
            background: var(--ink);
            color: var(--ivory);
            font-family: 'Cormorant Garamond', serif;
        }
        .cover::before {
            content: "";
            position: absolute; inset: 0;
            background:
                radial-gradient(120% 80% at 80% 10%, rgba(196,160,84,.22), transparent 60%),
                radial-gradient(140% 100% at 0% 100%, rgba(57,68,124,.55), transparent 60%);
            z-index: 1;
        }
        .frame-outer { position: absolute; top: 16mm; right: 16mm; bottom: 16mm; left: 16mm; border: 1px solid var(--gold-soft); z-index: 2; }
        .frame-inner { position: absolute; top: 18mm; right: 18mm; bottom: 18mm; left: 18mm; border: 1px solid var(--gold-faint); z-index: 2; }

        .cover-inner {
            position: absolute;
            top: 30mm; left: 27mm; right: 27mm; bottom: 25mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 3;
        }

        .cover-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 4.5pt;
            text-transform: uppercase;
            color: var(--gold);
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 500;
        }
        .cover-eyebrow .dash {
            width: 28px; height: 1px;
            background: var(--gold);
            display: inline-block;
        }

        .cover-title {
            font-size: 64pt;
            line-height: 1.02;
            margin: 36pt 0 24pt;
            font-weight: 500;
            letter-spacing: -0.02em;
            color: var(--ivory);
        }
        .cover-title em {
            color: var(--gold);
            font-style: italic;
            font-weight: 500;
        }

        .cover-rule { width: 200px; height: 1px; background: rgba(196,160,84,.4); margin: 28pt 0 18pt; }

        .cover-subtitle {
            font-family: 'Inter', sans-serif;
            font-size: 11pt;
            line-height: 1.7;
            color: var(--ivory-soft);
            max-width: 420pt;
        }

        .cover-meta {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: var(--ivory-faint);
        }
        .cover-meta-num {
            color: var(--gold);
            font-family: 'Cormorant Garamond', serif;
            font-size: 18pt;
            letter-spacing: 0;
            text-transform: none;
            display: block;
            margin-bottom: 4pt;
            font-weight: 500;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .content {
            background: var(--paper);
            color: var(--ink);
            font-family: 'Inter', sans-serif;
        }

        .header-band {
            background: var(--ink);
            color: var(--ivory);
            padding: 14pt 22mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-band-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 14pt;
            letter-spacing: -0.01em;
            font-weight: 500;
        }
        .header-band-ref {
            font-size: 8pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            color: var(--gold);
        }

        .content-body {
            padding: 22pt 22mm 50pt 22mm;
        }

        .section {
            margin-bottom: 18pt;
            page-break-inside: avoid;
        }

        .section-head {
            display: flex;
            align-items: baseline;
            gap: 12pt;
            margin-bottom: 10pt;
        }
        .section-num {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--gold);
            font-size: 18pt;
            font-weight: 500;
            flex-shrink: 0;
        }
        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 18pt;
            font-weight: 500;
            margin: 0;
            color: var(--ink);
            letter-spacing: -0.01em;
        }
        .section-rule {
            flex: 1;
            height: 1px;
            background: var(--rule);
        }

        /* Field row: label kiri uppercase, value kanan body */
        .row {
            display: grid;
            grid-template-columns: 150pt 1fr;
            gap: 14pt;
            padding: 5pt 0;
            border-bottom: 1px solid rgba(15,21,48,.06);
        }
        .row:last-child { border-bottom: none; }
        .row-label {
            font-size: 7.5pt;
            letter-spacing: 1.7pt;
            text-transform: uppercase;
            color: rgba(15,21,48,.5);
            padding-top: 1pt;
            font-weight: 500;
        }
        .row-value {
            font-size: 10pt;
            line-height: 1.5;
            color: var(--ink);
        }

        ul.diamond { margin: 0; padding: 0; list-style: none; }
        ul.diamond li {
            padding: 1pt 0;
            display: flex;
            align-items: center;
            gap: 8pt;
            font-size: 10pt;
        }
        ul.diamond li::before {
            content: "";
            display: inline-block;
            width: 5pt; height: 5pt;
            background: var(--gold);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        /* Tabel sistem informasi / pihak ketiga */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6pt;
            font-size: 9pt;
        }
        table.data-table th {
            background: var(--ink);
            color: var(--gold);
            text-align: left;
            padding: 6pt 8pt;
            font-size: 7.5pt;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            font-weight: 600;
        }
        table.data-table td {
            border-bottom: 1px solid var(--rule);
            padding: 6pt 8pt;
            vertical-align: top;
            line-height: 1.45;
        }
        table.data-table tr:last-child td { border-bottom: 1px solid var(--ink); }
        table.data-table .seq { width: 24pt; color: var(--gold); font-weight: 600; }

        /* Card pernyataan singkat (Y/T pertanyaan) */
        .qa-card {
            background: rgba(196,160,84,.06);
            border-left: 2px solid var(--gold);
            padding: 8pt 12pt;
            margin: 4pt 0;
        }
        .qa-question {
            font-size: 7.5pt;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            color: rgba(15,21,48,.55);
            margin-bottom: 3pt;
            font-weight: 500;
        }
        .qa-answer {
            font-size: 10pt;
            color: var(--ink);
            font-weight: 500;
        }

        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8pt;
            margin-top: 6pt;
        }

        /* Pill kategori data (Umum/Spesifik/PII) */
        .pill-row { display: flex; flex-wrap: wrap; gap: 6pt; margin-top: 4pt; }
        .pill {
            border: 1px solid var(--gold-soft);
            background: rgba(196,160,84,.08);
            padding: 3pt 9pt;
            border-radius: 2pt;
            font-size: 9pt;
            color: var(--ink);
        }
        .pill.pii {
            background: rgba(255,80,80,.05);
            border-color: rgba(180,40,40,.35);
            color: #8a1d1d;
        }

        .risk-badge {
            display: inline-block;
            padding: 4pt 12pt;
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 700;
            border-radius: 2pt;
        }
        .risk-HIGH { background: #8a1d1d; color: #fff; }
        .risk-MEDIUM { background: #b07a1d; color: #fff; }
        .risk-LOW { background: #2a5d3a; color: #fff; }

        /* Footer per halaman */
        .page-footer {
            position: absolute;
            bottom: 18pt;
            left: 22mm; right: 22mm;
            display: flex;
            justify-content: space-between;
            font-family: 'Inter', sans-serif;
            font-size: 7pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: rgba(15,21,48,.45);
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page cover">
    <div class="frame-outer"></div>
    <div class="frame-inner"></div>
    <div class="cover-inner">
        <div>
            <div class="cover-eyebrow"><span class="dash"></span>Confidential · Record of Processing</div>
            <h1 class="cover-title">
                Record of<br>
                <em>Processing</em><br>
                Activities
            </h1>
            <div class="cover-rule"></div>
            <div class="cover-subtitle">
                {{ $r['name'] ?? '-' }}<br>
                {{ $orgName }}
            </div>
        </div>
        <div class="cover-meta">
            <div>
                <span class="cover-meta-num">{{ $r['number'] ?? '-' }}</span>
                Document Reference
            </div>
            <div style="text-align: right;">
                <span class="cover-meta-num">{{ $r['date'] ?? '-' }}</span>
                Effective Date
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi Pemrosesan + Pejabat PDP
     ============================================================ --}}
<section class="page content">
    <div class="header-band">
        <div class="header-band-title">Record of Processing Activities</div>
        <div class="header-band-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <span class="section-num">I.</span>
                <h3 class="section-title">Deskripsi Pemrosesan</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row"><div class="row-label">Nomor ROPA</div><div class="row-value">{{ $r['number'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Nama Pemrosesan</div><div class="row-value">{{ $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Divisi</div><div class="row-value">{{ $r['division'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Unit Kerja</div><div class="row-value">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Entitas</div><div class="row-value">{{ $orgName }}</div></div>
            <div class="row"><div class="row-label">Kategori Perusahaan</div><div class="row-value">{{ $r['category'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Deskripsi Singkat</div><div class="row-value">{{ $r['description'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="section-head">
                <span class="section-num">II.</span>
                <h3 class="section-title">Data Protection Officer &amp; PIC</h3>
                <div class="section-rule"></div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
                        <th style="width: 30%;">Pejabat PDP (DPO)</th>
                        <th>Email</th>
                        <th style="width: 25%;">Telepon</th>
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
            <table class="data-table" style="margin-top: 10pt;">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
                        <th style="width: 25%;">Process Owner / PIC</th>
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
        <span>Page 02 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan
     ============================================================ --}}
<section class="page content">
    <div class="header-band">
        <div class="header-band-title">Record of Processing Activities</div>
        <div class="header-band-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <span class="section-num">III.</span>
                <h3 class="section-title">Informasi Pemrosesan</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row"><div class="row-label">Tujuan Pemrosesan</div><div class="row-value">{{ $r['purpose'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Aktivitas Pemrosesan</div><div class="row-value">{{ $r['activity'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Dasar Hukum</div><div class="row-value">{{ $r['legal_basis'] ?? '-' }}</div></div>
            <div class="row">
                <div class="row-label">Kategori Pemrosesan</div>
                <div class="row-value">
                    <ul class="diamond">
                        @foreach($r['categories'] ?? [] as $cat)
                            <li>{{ $cat }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-head">
                <span class="section-num">IV.</span>
                <h3 class="section-title">Sistem Informasi Terkait</h3>
                <div class="section-rule"></div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
                        <th>Nama Sistem Informasi</th>
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
        </div>

        <div class="section">
            <div class="section-head">
                <span class="section-num">V.</span>
                <h3 class="section-title">Teknologi &amp; Pemrofilan</h3>
                <div class="section-rule"></div>
            </div>
            <div class="qa-grid">
                <div class="qa-card">
                    <div class="qa-question">Bantuan AI (ML/NN/LLM)</div>
                    <div class="qa-answer">{{ $r['uses_ai'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-question">Pengambilan Keputusan Otomatis</div>
                    <div class="qa-answer">{{ $r['uses_automated_decision'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-question">Teknologi Baru</div>
                    <div class="qa-answer">{{ $r['uses_new_tech'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-question">Tujuan Pemrofilan</div>
                    <div class="qa-answer">{{ $r['profiling_purpose'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>Page 03 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page content">
    <div class="header-band">
        <div class="header-band-title">Record of Processing Activities</div>
        <div class="header-band-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <span class="section-num">VI.</span>
                <h3 class="section-title">Pengumpulan Data</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row">
                <div class="row-label">Jenis Subjek Data</div>
                <div class="row-value">
                    <ul class="diamond">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <li>{{ $s }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Jumlah Subjek Data</div><div class="row-value">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Sumber Pengumpulan</div><div class="row-value">{{ $r['data_source'] ?? '-' }}</div></div>

            <div class="row" style="border-bottom: none; padding-top: 10pt;">
                <div class="row-label">Data Pribadi — Umum</div>
                <div class="row-value">
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Data Pribadi — Spesifik</div>
                <div class="row-value">
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="row-label">Data Pribadi — PII</div>
                <div class="row-value">
                    <div class="pill-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>Page 04 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Penyimpanan Data
     ============================================================ --}}
<section class="page content">
    <div class="header-band">
        <div class="header-band-title">Record of Processing Activities</div>
        <div class="header-band-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <span class="section-num">VII.</span>
                <h3 class="section-title">Penggunaan &amp; Penyimpanan Data</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row">
                <div class="row-label">Kategori Pihak Pemroses</div>
                <div class="row-value">
                    <ul class="diamond">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Pihak Pemroses Utama</div><div class="row-value">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Pihak Ketiga Terlibat</div><div class="row-value">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="section">
            <div class="section-head">
                <span class="section-num">VIII.</span>
                <h3 class="section-title">Pihak Ketiga</h3>
                <div class="section-rule"></div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
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
                            <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: rgba(15,21,48,.5); font-size: 8pt;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>Page 05 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data
     ============================================================ --}}
<section class="page content">
    <div class="header-band">
        <div class="header-band-title">Record of Processing Activities</div>
        <div class="header-band-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <span class="section-num">IX.</span>
                <h3 class="section-title">Pengiriman Data</h3>
                <div class="section-rule"></div>
            </div>
            <div class="qa-grid">
                <div class="qa-card">
                    <div class="qa-question">Penerima Data Internal</div>
                    <div class="qa-answer">{{ $r['recipients_internal'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-question">Penerima Data Eksternal</div>
                    <div class="qa-answer">{{ $r['recipients_external'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-question">Transfer ke Luar Indonesia</div>
                    <div class="qa-answer">{{ $r['cross_border_transfer'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-question">Negara Tujuan</div>
                    <div class="qa-answer">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-head">
                <span class="section-num">X.</span>
                <h3 class="section-title">Jenis Data yang Dikirim</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Umum</div>
                <div class="row-value">
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Spesifik</div>
                <div class="row-value">
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="row-label">PII</div>
                <div class="row-value">
                    <div class="pill-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>Page 06 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi & Keamanan + Klasifikasi Risiko
     ============================================================ --}}
<section class="page content">
    <div class="header-band">
        <div class="header-band-title">Record of Processing Activities</div>
        <div class="header-band-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <span class="section-num">XI.</span>
                <h3 class="section-title">Retensi Data</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row"><div class="row-label">Nama Dokumen Terkait</div><div class="row-value">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Masa Retensi</div><div class="row-value">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Tanggal Berlaku</div><div class="row-value">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} s/d {{ $r['retention_end_date'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Aktivitas Penghapusan</div><div class="row-value">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="section-head">
                <span class="section-num">XII.</span>
                <h3 class="section-title">Keamanan Data</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="row-label">Kontrol Keamanan</div>
                <div class="row-value">
                    <ul class="diamond">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Riwayat Insiden</div><div class="row-value">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="section-head">
                <span class="section-num">XIII.</span>
                <h3 class="section-title">Klasifikasi Risiko</h3>
                <div class="section-rule"></div>
            </div>
            <div class="row">
                <div class="row-label">Level Risiko</div>
                <div class="row-value"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="row"><div class="row-label">Justifikasi</div><div class="row-value">{{ $r['risk_justification'] ?? '-' }}</div></div>
        </div>
    </div>
    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>Page 07 / {{ $totalPages }}</span>
    </div>
</section>

</body>
</html>
