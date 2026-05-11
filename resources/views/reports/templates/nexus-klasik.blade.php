<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Roboto:wght@300;400;500;700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Roboto', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #0F172A;
        }

        /* ============================================================
           Palette BUMN — match DOCX reference user
           navy=#16284C lav=#F4F2FE ink=#0F172A gray=#767171 rule=#D9D9D9
           ============================================================ */
        :root {
            --navy: #16284C;
            --navy-deep: #0F1E3C;
            --navy-soft: #2A3F6E;
            --lav: #F4F2FE;
            --ink: #0F172A;
            --gray: #767171;
            --rule: #D9D9D9;
            --white: #FFFFFF;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* ============================================================
           COVER PAGE — navy hero, full-bleed
           ============================================================ */
        .cover {
            background: var(--navy);
            color: var(--white);
            font-family: 'Poppins', sans-serif;
        }
        .cover-hero {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 6cm;
            padding: 3.5cm 2cm 1.5cm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            text-align: center;
        }
        .cover-org-name {
            font-size: 16pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2.5pt;
            color: var(--white);
            margin: 0 0 8pt;
        }
        .cover-org-website {
            font-family: 'Roboto', sans-serif;
            font-style: italic;
            font-size: 9pt;
            color: #D9D9D9;
            margin: 0 0 32pt;
        }
        .doc-type-chip {
            display: inline-block;
            background: var(--white);
            color: var(--navy);
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 10pt;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            padding: 6pt 18pt;
            border-radius: 2pt;
            margin: 0 0 36pt;
        }
        .cover-title {
            font-family: 'Poppins', sans-serif;
            font-size: 32pt;
            font-weight: 700;
            line-height: 1.15;
            color: var(--white);
            margin: 0 0 14pt;
            max-width: 14cm;
        }
        .cover-reg {
            font-family: 'Roboto', sans-serif;
            font-size: 12pt;
            color: #D9D9D9;
            letter-spacing: 0.5pt;
        }
        .cover-divider {
            width: 60pt;
            height: 1.5pt;
            background: var(--white);
            margin: 36pt auto;
        }

        /* 4-column meta strip */
        .cover-meta {
            position: absolute;
            bottom: 4cm;
            left: 2cm;
            right: 2cm;
        }
        .cover-meta table {
            width: 100%;
            border-collapse: collapse;
        }
        .cover-meta td {
            text-align: center;
            color: var(--white);
            font-family: 'Roboto', sans-serif;
            font-size: 8.5pt;
            padding: 6pt;
            border-right: 1px solid rgba(255,255,255,0.2);
        }
        .cover-meta td:last-child { border-right: none; }
        .cover-meta .meta-label {
            font-size: 7.5pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: rgba(255,255,255,0.65);
            margin-bottom: 3pt;
        }
        .cover-meta .meta-value {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 11pt;
            color: var(--white);
        }

        /* Bottom white band */
        .cover-foot {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2.4cm;
            background: var(--white);
            color: var(--ink);
            padding: 18pt 2cm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cover-foot-left,
        .cover-foot-right {
            font-family: 'Roboto', sans-serif;
        }
        .cover-foot-label {
            font-size: 7.5pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: var(--gray);
            margin-bottom: 2pt;
        }
        .cover-foot-value {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 11pt;
            color: var(--navy);
        }

        /* ============================================================
           CONTENT PAGES — white bg, navy headers, lavender table cells
           ============================================================ */
        .content {
            background: var(--white);
            color: var(--ink);
            font-family: 'Roboto', sans-serif;
        }
        .content-header {
            padding: 1.4cm 2cm 14pt;
            border-bottom: 2pt solid var(--navy);
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .content-header-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 11pt;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: 1.5pt;
        }
        .content-header-ref {
            font-family: 'Roboto', sans-serif;
            font-size: 9pt;
            color: var(--gray);
        }

        .content-body {
            padding: 22pt 2cm 60pt;
        }

        .section {
            margin-bottom: 22pt;
            page-break-inside: avoid;
        }
        .section-head {
            margin-bottom: 10pt;
        }
        .section-num-label {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 9pt;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            color: var(--navy-soft);
            margin-bottom: 4pt;
        }
        .section-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 16pt;
            color: var(--navy);
            margin: 0;
            line-height: 1.2;
        }
        .section-title-rule {
            width: 40pt;
            height: 2pt;
            background: var(--navy);
            margin-top: 6pt;
        }

        /* Info table — label kiri lavender, value kanan putih */
        table.info {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6pt;
            border: 1px solid var(--rule);
            font-size: 9.5pt;
        }
        table.info tr td {
            padding: 7pt 11pt;
            border-bottom: 1px solid var(--rule);
            vertical-align: top;
            line-height: 1.55;
        }
        table.info tr:last-child td { border-bottom: none; }
        table.info td.lbl {
            background: var(--lav);
            color: var(--navy);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 9pt;
            width: 35%;
            border-right: 1px solid var(--rule);
        }
        table.info td.val {
            color: var(--ink);
        }

        /* Tabel data (DPO, PIC, Sistem, Pihak Ketiga) — navy header */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6pt;
            font-size: 9pt;
            border: 1px solid var(--rule);
        }
        table.data th {
            background: var(--navy);
            color: var(--white);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.8pt;
            text-align: left;
            padding: 7pt 11pt;
        }
        table.data td {
            border-bottom: 1px solid var(--rule);
            padding: 7pt 11pt;
            vertical-align: top;
            line-height: 1.5;
        }
        table.data tr:last-child td { border-bottom: none; }
        table.data tr:nth-child(even) td { background: #FAFAFC; }
        table.data .seq {
            width: 26pt;
            font-family: 'Poppins', sans-serif;
            color: var(--navy-soft);
            font-weight: 600;
        }

        ul.clean { margin: 0; padding: 0; list-style: none; }
        ul.clean li {
            padding: 2pt 0;
            padding-left: 14pt;
            position: relative;
            font-size: 9.5pt;
            line-height: 1.5;
        }
        ul.clean li::before {
            content: "";
            position: absolute;
            left: 0; top: 8pt;
            width: 5pt; height: 5pt;
            background: var(--navy);
            border-radius: 50%;
        }

        /* Q/A grid 2-kolom (untuk pertanyaan AI/profiling) */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8pt;
            margin-top: 6pt;
        }
        .qa-card {
            background: var(--lav);
            padding: 10pt 12pt;
            border-left: 3pt solid var(--navy);
        }
        .qa-q {
            font-family: 'Poppins', sans-serif;
            font-size: 7.5pt;
            letter-spacing: 1.2pt;
            text-transform: uppercase;
            color: var(--navy-soft);
            font-weight: 600;
            margin-bottom: 3pt;
        }
        .qa-a {
            font-family: 'Roboto', sans-serif;
            font-size: 10pt;
            color: var(--ink);
            font-weight: 500;
        }

        /* Pill data kategori */
        .pill-row { display: flex; flex-wrap: wrap; gap: 5pt; margin-top: 4pt; }
        .pill {
            background: var(--lav);
            color: var(--navy);
            border: 1px solid #DAD3FB;
            padding: 3pt 9pt;
            border-radius: 12pt;
            font-size: 8.5pt;
            font-weight: 500;
            font-family: 'Roboto', sans-serif;
        }
        .pill.pii {
            background: #FEEDED;
            color: #8A1D1D;
            border-color: #F5C9C9;
        }

        .risk-badge {
            display: inline-block;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 8.5pt;
            letter-spacing: 1.4pt;
            padding: 4pt 14pt;
            border-radius: 2pt;
            text-transform: uppercase;
        }
        .risk-HIGH { background: #C53030; color: #fff; }
        .risk-MEDIUM { background: #D69E2E; color: #fff; }
        .risk-LOW { background: #2F855A; color: #fff; }

        .page-foot {
            position: absolute;
            bottom: 18pt;
            left: 2cm; right: 2cm;
            display: flex;
            justify-content: space-between;
            font-family: 'Roboto', sans-serif;
            font-size: 7.5pt;
            color: var(--gray);
            border-top: 1px solid var(--rule);
            padding-top: 8pt;
        }
        .page-foot .org { font-weight: 500; }
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
    <div class="cover-hero">
        <div class="cover-org-name">{{ $orgName }}</div>
        @if(!empty($orgWebsite))
            <div class="cover-org-website">{{ $orgWebsite }}</div>
        @else
            <div class="cover-org-website">&nbsp;</div>
        @endif

        <div class="doc-type-chip">Record of Processing Activities</div>

        <h1 class="cover-title">{{ $r['name'] ?? 'Untitled Record' }}</h1>
        <div class="cover-reg">{{ $r['number'] ?? '-' }}</div>
        <div class="cover-divider"></div>
    </div>

    <div class="cover-meta">
        <table>
            <tr>
                <td>
                    <div class="meta-label">Divisi</div>
                    <div class="meta-value">{{ $r['division'] ?? '-' }}</div>
                </td>
                <td>
                    <div class="meta-label">Unit Kerja</div>
                    <div class="meta-value">{{ $r['unit'] ?? '-' }}</div>
                </td>
                <td>
                    <div class="meta-label">Kategori</div>
                    <div class="meta-value">{{ $r['category'] ?? '-' }}</div>
                </td>
                <td>
                    <div class="meta-label">Risiko</div>
                    <div class="meta-value">{{ $r['risk_level'] ?? '-' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="cover-foot">
        <div class="cover-foot-left">
            <div class="cover-foot-label">Sifat Dokumen</div>
            <div class="cover-foot-value">Rahasia · Internal</div>
        </div>
        <div class="cover-foot-right" style="text-align: right;">
            <div class="cover-foot-label">Tanggal Berlaku</div>
            <div class="cover-foot-value">{{ $r['date'] ?? '-' }}</div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi Pemrosesan + Pejabat PDP
     ============================================================ --}}
<section class="page content">
    <div class="content-header">
        <div class="content-header-title">Record of Processing Activities</div>
        <div class="content-header-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian I</div>
                <h3 class="section-title">Deskripsi Pemrosesan</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr><td class="lbl">Nomor ROPA</td><td class="val">{{ $r['number'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Nama Pemrosesan</td><td class="val">{{ $r['name'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Divisi</td><td class="val">{{ $r['division'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Unit Kerja</td><td class="val">{{ $r['unit'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Entitas</td><td class="val">{{ $orgName }}</td></tr>
                <tr><td class="lbl">Kategori Perusahaan</td><td class="val">{{ $r['category'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Deskripsi Singkat</td><td class="val">{{ $r['description'] ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian II</div>
                <h3 class="section-title">Data Protection Officer &amp; PIC</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="data">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
                        <th style="width: 30%;">Pejabat PDP (DPO)</th>
                        <th>Email</th>
                        <th style="width: 22%;">Telepon</th>
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
            <table class="data" style="margin-top: 10pt;">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
                        <th style="width: 26%;">Process Owner / PIC</th>
                        <th style="width: 25%;">Jabatan</th>
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
    <div class="page-foot">
        <span class="org">{{ $orgName }}</span>
        <span>Halaman 02 dari {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan
     ============================================================ --}}
<section class="page content">
    <div class="content-header">
        <div class="content-header-title">Record of Processing Activities</div>
        <div class="content-header-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian III</div>
                <h3 class="section-title">Informasi Pemrosesan</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr><td class="lbl">Tujuan Pemrosesan</td><td class="val">{{ $r['purpose'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Aktivitas Pemrosesan</td><td class="val">{{ $r['activity'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Dasar Hukum</td><td class="val">{{ $r['legal_basis'] ?? '-' }}</td></tr>
                <tr>
                    <td class="lbl">Kategori Pemrosesan</td>
                    <td class="val">
                        <ul class="clean">
                            @foreach($r['categories'] ?? [] as $cat)
                                <li>{{ $cat }}</li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian IV</div>
                <h3 class="section-title">Sistem Informasi Terkait</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="data">
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
                <div class="section-num-label">Bagian V</div>
                <h3 class="section-title">Teknologi &amp; Pemrofilan</h3>
                <div class="section-title-rule"></div>
            </div>
            <div class="qa-grid">
                <div class="qa-card">
                    <div class="qa-q">Bantuan AI (ML/NN/LLM)</div>
                    <div class="qa-a">{{ $r['uses_ai'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-q">Pengambilan Keputusan Otomatis</div>
                    <div class="qa-a">{{ $r['uses_automated_decision'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-q">Teknologi Baru</div>
                    <div class="qa-a">{{ $r['uses_new_tech'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-q">Tujuan Pemrofilan</div>
                    <div class="qa-a">{{ $r['profiling_purpose'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-foot">
        <span class="org">{{ $orgName }}</span>
        <span>Halaman 03 dari {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page content">
    <div class="content-header">
        <div class="content-header-title">Record of Processing Activities</div>
        <div class="content-header-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian VI</div>
                <h3 class="section-title">Pengumpulan Data</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr>
                    <td class="lbl">Jenis Subjek Data</td>
                    <td class="val">
                        <ul class="clean">
                            @foreach($r['data_subjects'] ?? [] as $s)
                                <li>{{ $s }}</li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
                <tr><td class="lbl">Jumlah Subjek Data</td><td class="val">{{ $r['data_subjects_volume'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Sumber Pengumpulan</td><td class="val">{{ $r['data_source'] ?? '-' }}</td></tr>
                <tr>
                    <td class="lbl">Data Pribadi — Umum</td>
                    <td class="val">
                        <div class="pill-row">
                            @foreach($r['data_general'] ?? [] as $d)
                                <span class="pill">{{ $d }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="lbl">Data Pribadi — Spesifik</td>
                    <td class="val">
                        <div class="pill-row">
                            @foreach($r['data_specific'] ?? [] as $d)
                                <span class="pill">{{ $d }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="lbl">Data Pribadi — PII</td>
                    <td class="val">
                        <div class="pill-row">
                            @foreach($r['data_pii'] ?? [] as $d)
                                <span class="pill pii">{{ $d }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <div class="page-foot">
        <span class="org">{{ $orgName }}</span>
        <span>Halaman 04 dari {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Penyimpanan
     ============================================================ --}}
<section class="page content">
    <div class="content-header">
        <div class="content-header-title">Record of Processing Activities</div>
        <div class="content-header-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian VII</div>
                <h3 class="section-title">Penggunaan &amp; Penyimpanan Data</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr>
                    <td class="lbl">Kategori Pihak Pemroses</td>
                    <td class="val">
                        <ul class="clean">
                            @foreach($r['processor_role'] ?? [] as $role)
                                <li>{{ $role }}</li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
                <tr><td class="lbl">Pihak Pemroses Utama</td><td class="val">{{ $r['processor_entity'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Pihak Ketiga Terlibat</td><td class="val">{{ $r['has_third_party'] ?? '-' }}</td></tr>
            </table>
        </div>

        @if(!empty($r['third_parties']))
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian VIII</div>
                <h3 class="section-title">Pihak Ketiga</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="data">
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
                            <td>
                                {{ $tp['pic_email'] ?? '-' }}<br>
                                <span style="color: var(--gray); font-size: 8pt;">{{ $tp['pic_phone'] ?? '' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="page-foot">
        <span class="org">{{ $orgName }}</span>
        <span>Halaman 05 dari {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data
     ============================================================ --}}
<section class="page content">
    <div class="content-header">
        <div class="content-header-title">Record of Processing Activities</div>
        <div class="content-header-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian IX</div>
                <h3 class="section-title">Pengiriman Data</h3>
                <div class="section-title-rule"></div>
            </div>
            <div class="qa-grid">
                <div class="qa-card">
                    <div class="qa-q">Penerima Data Internal</div>
                    <div class="qa-a">{{ $r['recipients_internal'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-q">Penerima Data Eksternal</div>
                    <div class="qa-a">{{ $r['recipients_external'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-q">Transfer ke Luar Indonesia</div>
                    <div class="qa-a">{{ $r['cross_border_transfer'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="qa-q">Negara Tujuan</div>
                    <div class="qa-a">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian X</div>
                <h3 class="section-title">Jenis Data yang Dikirim</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr>
                    <td class="lbl">Umum</td>
                    <td class="val">
                        <div class="pill-row">
                            @foreach($r['data_general'] ?? [] as $d)
                                <span class="pill">{{ $d }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="lbl">Spesifik</td>
                    <td class="val">
                        <div class="pill-row">
                            @foreach($r['data_specific'] ?? [] as $d)
                                <span class="pill">{{ $d }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="lbl">PII</td>
                    <td class="val">
                        <div class="pill-row">
                            @foreach($r['data_pii'] ?? [] as $d)
                                <span class="pill pii">{{ $d }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <div class="page-foot">
        <span class="org">{{ $orgName }}</span>
        <span>Halaman 06 dari {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi, Keamanan, Klasifikasi Risiko
     ============================================================ --}}
<section class="page content">
    <div class="content-header">
        <div class="content-header-title">Record of Processing Activities</div>
        <div class="content-header-ref">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-body">
        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian XI</div>
                <h3 class="section-title">Retensi Data</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr><td class="lbl">Nama Dokumen Terkait</td><td class="val">{{ $r['retention_doc_name'] ?? ($r['name'] ?? '-') }}</td></tr>
                <tr><td class="lbl">Masa Retensi</td><td class="val">{{ $r['retention_period'] ?? ($r['retention'] ?? '-') }}</td></tr>
                <tr><td class="lbl">Tanggal Berlaku</td><td class="val">{{ $r['retention_effective_date'] ?? ($r['date'] ?? '-') }} s/d {{ $r['retention_end_date'] ?? '-' }}</td></tr>
                <tr><td class="lbl">Aktivitas Penghapusan</td><td class="val">{{ $r['has_deletion_activity'] ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian XII</div>
                <h3 class="section-title">Keamanan Data</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr>
                    <td class="lbl">Kontrol Keamanan</td>
                    <td class="val">
                        <ul class="clean">
                            @foreach($r['controls'] ?? [] as $ctrl)
                                <li>{{ $ctrl }}</li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
                <tr><td class="lbl">Riwayat Insiden</td><td class="val">{{ $r['has_past_incident'] ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="section">
            <div class="section-head">
                <div class="section-num-label">Bagian XIII</div>
                <h3 class="section-title">Klasifikasi Risiko</h3>
                <div class="section-title-rule"></div>
            </div>
            <table class="info">
                <tr>
                    <td class="lbl">Level Risiko</td>
                    <td class="val"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></td>
                </tr>
                <tr><td class="lbl">Justifikasi</td><td class="val">{{ $r['risk_justification'] ?? '-' }}</td></tr>
            </table>
        </div>
    </div>
    <div class="page-foot">
        <span class="org">{{ $orgName }}</span>
        <span>Halaman 07 dari {{ $totalPages }}</span>
    </div>
</section>

</body>
</html>
