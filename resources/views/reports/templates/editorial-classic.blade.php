<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'EB Garamond', 'Cormorant Garamond', serif;
            color: #2b2418;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        :root {
            --ivory: #f5efe2;
            --ink: #2b2418;
            --brown: #7a4f1d;
            --muted: #7a6b4f;
            --soft: #5a4a30;
            --rule: #d9cfb9;
            --dash: #c89878;
            --bg-tint: #ead7bc;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: var(--ivory);
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        .sans { font-family: 'Inter', sans-serif; }

        /* ============================================================
           COVER — masthead, double-rule, huge Ropa, diamond ornaments
           ============================================================ */
        .masthead {
            padding: 36pt 64pt 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Inter', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 3.2pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }
        .double-rule-top {
            border-top: 3px double var(--ink);
            margin: 18pt 64pt 0;
        }
        .cover-eyebrow {
            margin-top: 24pt;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 9.5pt;
            letter-spacing: 4.8pt;
            color: var(--muted);
            font-weight: 500;
        }
        .cover-title {
            text-align: center;
            font-size: 100pt;
            margin: 32pt 0 8pt;
            font-weight: 400;
            line-height: .95;
            letter-spacing: -.025em;
            color: var(--ink);
        }
        .cover-title em {
            font-style: italic;
            font-weight: 400;
        }
        .cover-tagline {
            text-align: center;
            font-style: italic;
            font-size: 19pt;
            color: var(--soft);
        }
        .cover-divider {
            margin: 24pt auto 0;
            width: 60pt;
            height: 1px;
            background: var(--ink);
        }
        .cover-quote {
            margin: 24pt auto 0;
            max-width: 360pt;
            text-align: center;
            font-size: 16pt;
            line-height: 1.5;
            color: #3a2f1f;
        }
        .cover-quote em { font-style: italic; }
        .cover-org {
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            margin-top: 12pt;
            color: var(--muted);
            font-weight: 500;
            text-align: center;
        }

        .ornaments {
            display: flex;
            justify-content: center;
            margin-top: 60pt;
            gap: 12pt;
        }
        .diamond {
            display: inline-block;
            width: 6pt;
            height: 6pt;
            background: var(--muted);
            transform: rotate(45deg);
        }
        .diamond.big { width: 9pt; height: 9pt; }

        .cover-meta {
            position: absolute;
            bottom: 88pt;
            left: 64pt;
            right: 64pt;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 24pt;
            padding-top: 18pt;
            border-top: 1px solid var(--ink);
        }
        .cover-meta-item { text-align: center; }
        .cover-meta-key {
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 3.2pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }
        .cover-meta-val {
            font-size: 16pt;
            font-style: italic;
            margin-top: 4pt;
        }

        .cover-footer {
            position: absolute;
            bottom: 36pt;
            left: 0; right: 0;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 4.2pt;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 500;
        }

        /* ============================================================
           CONTENT — double-rule heading, drop cap intro
           ============================================================ */
        .content-head {
            padding: 38pt 64pt 24pt;
            border-bottom: 3px double var(--ink);
        }
        .content-head-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 3.2pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }
        .content-head h2 {
            font-size: 38pt;
            margin: 8pt 0 0;
            font-weight: 400;
            letter-spacing: -.02em;
            color: var(--ink);
        }
        .content-head h2 em { font-style: italic; }

        .content-body {
            padding: 22pt 64pt 64pt;
            font-size: 12.5pt;
            line-height: 1.6;
        }
        .content-body p { margin: 0 0 10pt; }
        .drop {
            float: left;
            font-family: 'EB Garamond', serif;
            font-size: 56pt;
            line-height: .85;
            margin-right: 8pt;
            margin-top: 2pt;
            color: var(--brown);
            font-weight: 500;
        }

        .pull-quote {
            margin: 12pt 64pt 18pt;
            padding: 18pt 24pt;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            font-style: italic;
            font-size: 15pt;
            text-align: center;
            color: var(--soft);
        }

        .two-col {
            padding: 0 64pt 32pt;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24pt;
            font-family: 'Inter', sans-serif;
            font-size: 10.5pt;
            line-height: 1.55;
        }
        .col-label {
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-size: 8.5pt;
            margin-bottom: 6pt;
            font-weight: 600;
        }

        /* Field tables for tabular content */
        .field-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8pt 0 16pt;
        }
        .field-table td {
            padding: 8pt 0;
            vertical-align: top;
            border-bottom: 1px solid var(--rule);
            font-size: 11pt;
        }
        .field-table tr:last-child td { border-bottom: 1px solid var(--ink); }
        .field-table .ft-label {
            width: 32%;
            font-style: italic;
            color: var(--brown);
            padding-right: 14pt;
        }

        /* Section heading reused below cover-style head */
        .sec {
            margin-top: 18pt;
        }
        .sec-mast {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            border-top: 1px solid var(--ink);
            padding-top: 12pt;
        }
        .sec-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }
        .sec-title {
            font-size: 22pt;
            font-weight: 400;
            margin: 6pt 0 10pt;
            letter-spacing: -.015em;
        }
        .sec-title em { font-style: italic; }

        /* Diamond list */
        ul.diamonds { margin: 0; padding: 0; list-style: none; font-size: 11pt; }
        ul.diamonds li {
            display: flex;
            align-items: baseline;
            gap: 8pt;
            padding: 2pt 0;
        }
        ul.diamonds li::before {
            content: "";
            display: inline-block;
            width: 5pt; height: 5pt;
            background: var(--brown);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        /* Data table - serif with classical feel */
        table.serif-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5pt;
            margin-top: 8pt;
        }
        table.serif-table th {
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--muted);
            text-align: left;
            padding: 8pt 8pt;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            font-weight: 600;
        }
        table.serif-table td {
            padding: 8pt 8pt;
            border-bottom: 1px solid var(--rule);
            vertical-align: top;
            line-height: 1.5;
        }
        table.serif-table .seq {
            font-style: italic;
            color: var(--brown);
            width: 24pt;
        }
        table.serif-table tr:last-child td { border-bottom: 1px solid var(--ink); }

        /* Q/A cards */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12pt;
            margin-top: 8pt;
        }
        .qa-item {
            padding: 12pt 14pt;
            border: 1px solid var(--rule);
            background: rgba(122,79,29,.04);
        }
        .qa-q {
            font-family: 'Inter', sans-serif;
            font-size: 8pt;
            letter-spacing: 2.2pt;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 4pt;
            font-weight: 600;
        }
        .qa-a {
            font-size: 12pt;
            font-style: italic;
            color: var(--ink);
        }

        /* Pills (data category) */
        .pill-row { margin-top: 4pt; }
        .pill {
            display: inline-block;
            padding: 3pt 10pt;
            border: 1px solid var(--brown);
            color: var(--brown);
            font-style: italic;
            font-size: 10.5pt;
            margin: 2pt 3pt 2pt 0;
            border-radius: 1pt;
            background: rgba(122,79,29,.04);
        }
        .pill.pii {
            border-color: #8a2828;
            color: #8a2828;
            background: rgba(138,40,40,.05);
        }

        .risk-badge {
            display: inline-block;
            padding: 5pt 16pt;
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 2.5pt;
            text-transform: uppercase;
            font-weight: 700;
        }
        .risk-HIGH { background: #8a2828; color: var(--ivory); }
        .risk-MEDIUM { background: #b07a1d; color: var(--ivory); }
        .risk-LOW { background: #4a6d3f; color: var(--ivory); }

        .page-footer {
            position: absolute;
            bottom: 26pt;
            left: 64pt; right: 64pt;
            display: flex;
            justify-content: space-between;
            font-family: 'Inter', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 3.2pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }
        .folio-italic {
            font-family: 'EB Garamond', serif;
            font-style: italic;
            letter-spacing: 0;
            text-transform: none;
            font-size: 11pt;
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
<section class="page">
    <div class="masthead">
        <span>Vol. I · No. 02</span>
        <span>Hak Cipta dilindungi</span>
    </div>
    <div class="double-rule-top"></div>
    <div class="cover-eyebrow">RECORD OF PROCESSING ACTIVITIES</div>

    <h1 class="cover-title">R<em>opa</em></h1>
    <div class="cover-tagline">A document on personal data processing</div>
    <div class="cover-divider"></div>
    <div class="cover-quote">
        <em>&ldquo;{{ $r['name'] ?? '-' }}&rdquo;</em>
    </div>
    <div class="cover-org">{{ $orgName }}</div>

    <div class="ornaments">
        <span class="diamond"></span>
        <span class="diamond big"></span>
        <span class="diamond"></span>
    </div>

    <div class="cover-meta">
        <div class="cover-meta-item">
            <div class="cover-meta-key">Nomor</div>
            <div class="cover-meta-val">{{ $r['number'] ?? '-' }}</div>
        </div>
        <div class="cover-meta-item">
            <div class="cover-meta-key">Divisi</div>
            <div class="cover-meta-val">{{ $r['division'] ?? '-' }}</div>
        </div>
        <div class="cover-meta-item">
            <div class="cover-meta-key">Berlaku</div>
            <div class="cover-meta-val">{{ $r['date'] ?? '-' }}</div>
        </div>
    </div>

    <div class="cover-footer">
        &#9670; Confidential &amp; Internal Use Only &#9670;
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi Pemrosesan (drop cap + 2-col prose) + DPO/PIC
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <div class="content-head-row">
            <span>Bagian I</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2>Deskripsi <em>Pemrosesan</em></h2>
    </div>

    <div class="content-body" style="column-count: 2; column-gap: 32pt; padding-bottom: 8pt;">
        <p>
            <span class="drop">P</span>roses pemrosesan data pribadi yang dijelaskan dalam dokumen ini mengacu pada aktivitas <em>{{ $r['name'] ?? '-' }}</em> yang dijalankan oleh divisi <strong>{{ $r['division'] ?? '-' }}</strong>, khususnya unit <strong>{{ $r['unit'] ?? '-' }}</strong>.
        </p>
        <p>
            Aktivitas ini dilaksanakan oleh <strong>{{ $orgName }}</strong> dalam kapasitas sebagai <em>{{ $r['category'] ?? '-' }}</em>. Dokumen ini disusun guna memenuhi kewajiban hukum sebagaimana diatur dalam Undang-Undang Pelindungan Data Pribadi.
        </p>
        <p>
            <strong>Tujuan.</strong> {{ $r['purpose'] ?? '-' }}. {{ $r['activity'] ?? '' }}
        </p>
        <p>
            <strong>Dasar Hukum.</strong> <em>{{ $r['legal_basis'] ?? '-' }}</em>.
        </p>
    </div>

    <div class="pull-quote">
        &ldquo;{{ $r['description'] ?? '-' }}&rdquo;
    </div>

    <div class="two-col">
        <div>
            <div class="col-label">Pejabat PDP (DPO)</div>
            <div style="font-size: 11pt; margin-bottom: 2pt;"><em>{{ $r['dpo']['name'] ?? '-' }}</em></div>
            <div style="font-size: 9.5pt; color: var(--muted);">{{ $r['dpo']['email'] ?? '-' }}</div>
            @if(!empty($r['dpo']['phone']))
                <div style="font-size: 9.5pt; color: var(--muted);">{{ $r['dpo']['phone'] }}</div>
            @endif
        </div>
        <div>
            <div class="col-label">Process Owner / PIC</div>
            <div style="font-size: 11pt; margin-bottom: 2pt;"><em>{{ $r['pic']['name'] ?? '-' }}</em> &mdash; {{ $r['pic']['role'] ?? '' }}</div>
            <div style="font-size: 9.5pt; color: var(--muted);">{{ $r['pic']['email'] ?? '-' }}</div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="folio-italic">~ 2 ~</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan + Sistem + Teknologi
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <div class="content-head-row">
            <span>Bagian II &mdash; IV</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2>Informasi &amp; <em>Sistem</em></h2>
    </div>

    <div class="content-body" style="padding-top: 16pt;">
        <div class="sec" style="margin-top: 0;">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian III</span>
                <span class="sec-eyebrow">{{ $r['number'] ?? '-' }}</span>
            </div>
            <h3 class="sec-title">Informasi <em>Pemrosesan</em></h3>

            <table class="field-table">
                <tr><td class="ft-label">Tujuan Pemrosesan</td><td>{{ $r['purpose'] ?? '-' }}</td></tr>
                <tr><td class="ft-label">Aktivitas Pemrosesan</td><td>{{ $r['activity'] ?? '-' }}</td></tr>
                <tr><td class="ft-label">Dasar Hukum</td><td><em>{{ $r['legal_basis'] ?? '-' }}</em></td></tr>
                <tr><td class="ft-label">Kategori Pemrosesan</td><td>
                    <ul class="diamonds">
                        @foreach($r['categories'] ?? [] as $cat)
                            <li>{{ $cat }}</li>
                        @endforeach
                    </ul>
                </td></tr>
            </table>
        </div>

        <div class="sec">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian IV</span>
                <span class="sec-eyebrow">Sistem Informasi</span>
            </div>
            <h3 class="sec-title">Sistem Informasi <em>Terkait</em></h3>
            <table class="serif-table">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
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
        </div>

        <div class="sec">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian V</span>
                <span class="sec-eyebrow">Teknologi &amp; Pemrofilan</span>
            </div>
            <h3 class="sec-title">Teknologi &amp; <em>Pemrofilan</em></h3>
            <div class="qa-grid">
                <div class="qa-item">
                    <div class="qa-q">Bantuan AI</div>
                    <div class="qa-a">{{ $r['uses_ai'] ?? '-' }}</div>
                </div>
                <div class="qa-item">
                    <div class="qa-q">Keputusan Otomatis</div>
                    <div class="qa-a">{{ $r['uses_automated_decision'] ?? '-' }}</div>
                </div>
                <div class="qa-item">
                    <div class="qa-q">Teknologi Baru</div>
                    <div class="qa-a">{{ $r['uses_new_tech'] ?? '-' }}</div>
                </div>
                <div class="qa-item">
                    <div class="qa-q">Tujuan Pemrofilan</div>
                    <div class="qa-a">{{ $r['profiling_purpose'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="folio-italic">~ 3 ~</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <div class="content-head-row">
            <span>Bagian VI</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2>Pengumpulan <em>Data</em></h2>
    </div>

    <div class="content-body" style="padding-top: 18pt;">
        <table class="field-table">
            <tr><td class="ft-label">Jenis Subjek Data</td><td>
                <ul class="diamonds">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </td></tr>
            <tr><td class="ft-label">Jumlah Subjek Data</td><td>{{ $r['data_subjects_volume'] ?? '-' }}</td></tr>
            <tr><td class="ft-label">Sumber Pengumpulan</td><td>{{ $r['data_source'] ?? '-' }}</td></tr>
        </table>

        <div class="sec" style="margin-top: 16pt;">
            <div class="sec-mast">
                <span class="sec-eyebrow">Klasifikasi Data Pribadi</span>
                <span class="sec-eyebrow">Umum &middot; Spesifik &middot; PII</span>
            </div>
            <h3 class="sec-title">Kategori <em>Data Pribadi</em></h3>

            <table class="field-table">
                <tr><td class="ft-label">Data Umum</td><td>
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </td></tr>
                <tr><td class="ft-label">Data Spesifik</td><td>
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </td></tr>
                <tr><td class="ft-label">Data PII (Sensitif)</td><td>
                    <div class="pill-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </td></tr>
            </table>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="folio-italic">~ 4 ~</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Penyimpanan + Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <div class="content-head-row">
            <span>Bagian VII &mdash; VIII</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2>Penyimpanan &amp; <em>Pihak Ketiga</em></h2>
    </div>

    <div class="content-body" style="padding-top: 18pt;">
        <div class="sec" style="margin-top: 0;">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian VII</span>
                <span class="sec-eyebrow">Penggunaan Data</span>
            </div>
            <h3 class="sec-title">Penggunaan &amp; <em>Penyimpanan</em></h3>
            <table class="field-table">
                <tr><td class="ft-label">Kategori Pihak Pemroses</td><td>
                    <ul class="diamonds">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </td></tr>
                <tr><td class="ft-label">Pihak Pemroses Utama</td><td>{{ $r['processor_entity'] ?? '-' }}</td></tr>
                <tr><td class="ft-label">Pihak Ketiga Terlibat</td><td><em>{{ $r['has_third_party'] ?? '-' }}</em></td></tr>
            </table>
        </div>

        @if(!empty($r['third_parties']))
        <div class="sec">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian VIII</span>
                <span class="sec-eyebrow">Daftar Pihak Ketiga</span>
            </div>
            <h3 class="sec-title">Pihak <em>Ketiga</em></h3>
            <table class="serif-table">
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
                            <td><em>{{ $tp['name'] ?? '-' }}</em></td>
                            <td>{{ $tp['address'] ?? '-' }}</td>
                            <td>{{ $tp['pic_name'] ?? '-' }}</td>
                            <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: var(--muted); font-size: 9pt;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="folio-italic">~ 5 ~</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data + Cross-border
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <div class="content-head-row">
            <span>Bagian IX &mdash; X</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2>Pengiriman <em>Data</em></h2>
    </div>

    <div class="content-body" style="padding-top: 18pt;">
        <div class="sec" style="margin-top: 0;">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian IX</span>
                <span class="sec-eyebrow">Penerima &amp; Transfer</span>
            </div>
            <h3 class="sec-title">Penerima &amp; <em>Transfer</em></h3>
            <div class="qa-grid">
                <div class="qa-item">
                    <div class="qa-q">Penerima Internal</div>
                    <div class="qa-a">{{ $r['recipients_internal'] ?? '-' }}</div>
                </div>
                <div class="qa-item">
                    <div class="qa-q">Penerima Eksternal</div>
                    <div class="qa-a">{{ $r['recipients_external'] ?? '-' }}</div>
                </div>
                <div class="qa-item">
                    <div class="qa-q">Transfer Lintas Negara</div>
                    <div class="qa-a">{{ $r['cross_border_transfer'] ?? '-' }}</div>
                </div>
                <div class="qa-item">
                    <div class="qa-q">Negara Tujuan</div>
                    <div class="qa-a">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
                </div>
            </div>
        </div>

        <div class="sec">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian X</span>
                <span class="sec-eyebrow">Klasifikasi yang Dikirim</span>
            </div>
            <h3 class="sec-title">Jenis Data <em>yang Dikirim</em></h3>
            <table class="field-table">
                <tr><td class="ft-label">Umum</td><td>
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </td></tr>
                <tr><td class="ft-label">Spesifik</td><td>
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </td></tr>
                <tr><td class="ft-label">PII</td><td>
                    <div class="pill-row">
                        @foreach($r['data_pii'] ?? [] as $d)
                            <span class="pill pii">{{ $d }}</span>
                        @endforeach
                    </div>
                </td></tr>
            </table>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="folio-italic">~ 6 ~</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi, Keamanan, Klasifikasi Risiko
     ============================================================ --}}
<section class="page">
    <div class="content-head">
        <div class="content-head-row">
            <span>Bagian XI &mdash; XIII</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <h2>Retensi, Keamanan<br>&amp; <em>Risiko</em></h2>
    </div>

    <div class="content-body" style="padding-top: 18pt;">
        <div class="sec" style="margin-top: 0;">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian XI</span>
                <span class="sec-eyebrow">Retensi Data</span>
            </div>
            <h3 class="sec-title">Retensi <em>Data</em></h3>
            <table class="field-table">
                <tr><td class="ft-label">Nama Dokumen Terkait</td><td>{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</td></tr>
                <tr><td class="ft-label">Masa Retensi</td><td>{{ $r['retention_period'] ?? '-' }}</td></tr>
                <tr><td class="ft-label">Tanggal Berlaku</td><td>{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} s/d {{ $r['retention_end_date'] ?? '-' }}</td></tr>
                <tr><td class="ft-label">Aktivitas Penghapusan</td><td>{{ $r['has_deletion_activity'] ?? '-' }}</td></tr>
            </table>
        </div>

        <div class="sec">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian XII</span>
                <span class="sec-eyebrow">Keamanan Data</span>
            </div>
            <h3 class="sec-title">Keamanan <em>Data</em></h3>
            <table class="field-table">
                <tr><td class="ft-label">Kontrol Keamanan</td><td>
                    <ul class="diamonds">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ul>
                </td></tr>
                <tr><td class="ft-label">Riwayat Insiden</td><td><em>{{ $r['has_past_incident'] ?? '-' }}</em></td></tr>
            </table>
        </div>

        <div class="sec">
            <div class="sec-mast">
                <span class="sec-eyebrow">Bagian XIII</span>
                <span class="sec-eyebrow">Klasifikasi Risiko</span>
            </div>
            <h3 class="sec-title">Klasifikasi <em>Risiko</em></h3>
            <table class="field-table">
                <tr><td class="ft-label">Level Risiko</td><td><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></td></tr>
                <tr><td class="ft-label">Justifikasi</td><td>{{ $r['risk_justification'] ?? '-' }}</td></tr>
            </table>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span class="folio-italic">~ 7 ~</span>
    </div>
</section>

</body>
</html>
