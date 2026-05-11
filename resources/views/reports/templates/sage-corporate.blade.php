<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500;1,600&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            color: #2a3528;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        :root {
            --sage: #4a5b3e;
            --sage-light: #7a8a6e;
            --cream-light: #f8f6ee;
            --cream: #f0ede2;
            --cream-mid: #e6e3d4;
            --rule: #d8d4c2;
            --pale-sage: #c7d1bd;
            --ink: #2a3528;
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
           COVER — sage sidebar full-height, vertical ROPA letters
           ============================================================ */
        .sidebar {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 280pt;
            background: var(--sage);
            color: var(--cream);
            padding: 64pt 32pt;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sidebar-vertical {
            font-size: 10pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            color: var(--pale-sage);
            margin-bottom: 50pt;
            /* Simulated vertical orientation via transform */
            transform: rotate(-90deg);
            transform-origin: left top;
            width: 200pt;
            position: relative;
            left: 6pt;
            top: 200pt;
            white-space: nowrap;
            font-weight: 500;
        }
        .sidebar-letters {
            font-size: 60pt;
            font-weight: 300;
            line-height: 1;
            letter-spacing: -.04em;
            color: var(--cream);
            margin-top: 280pt;
        }
        .sidebar-letters span { display: block; }
        .sidebar-bottom-rule {
            width: 40pt;
            height: 1px;
            background: var(--pale-sage);
            margin-bottom: 16pt;
        }
        .sb-label {
            font-size: 9.5pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--pale-sage);
            font-weight: 500;
        }
        .sb-italic {
            margin-top: 6pt;
            font-size: 13pt;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            color: var(--cream);
        }

        .cover-main {
            margin-left: 280pt;
            padding: 80pt 56pt;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cover-eyebrow {
            font-size: 10pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--sage-light);
            font-weight: 600;
        }
        .cover-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 54pt;
            font-weight: 400;
            line-height: 1.05;
            margin: 20pt 0 0;
            letter-spacing: -.02em;
            color: var(--ink);
        }
        .cover-bar {
            width: 80pt; height: 2px;
            background: var(--sage);
            margin: 28pt 0;
        }
        .cover-name {
            font-size: 14pt;
            line-height: 1.6;
            color: var(--sage);
            max-width: 380pt;
        }
        .cover-org {
            margin-top: 8pt;
            font-size: 12pt;
            color: var(--sage-light);
        }
        .cover-grid {
            border-top: 1px solid var(--sage);
            padding-top: 18pt;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16pt;
        }
        .cgk {
            font-size: 9pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--sage-light);
            font-weight: 600;
        }
        .cgv {
            margin-top: 4pt;
            font-size: 13pt;
            font-weight: 500;
            color: var(--ink);
        }

        /* ============================================================
           CONTENT — sage section header, cream body
           ============================================================ */
        .page.content { background: var(--cream-light); }

        .section-band {
            background: var(--sage);
            color: var(--cream);
            padding: 20pt 56pt;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sb-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 22pt;
            font-style: italic;
            letter-spacing: -.01em;
        }
        .sb-pill {
            font-size: 10pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--pale-sage);
            font-weight: 500;
        }

        .section-band-soft {
            background: var(--cream-mid);
            color: var(--ink);
            padding: 18pt 56pt;
        }
        .section-band-soft .sb-title { color: var(--ink); }

        .body {
            padding: 32pt 56pt;
        }

        /* Table — institutional look with sage uppercase labels */
        table.inst {
            width: 100%;
            border-collapse: collapse;
            font-size: 13pt;
        }
        table.inst tr {
            border-bottom: 1px solid var(--rule);
        }
        table.inst .lk {
            padding: 12pt 16pt 12pt 0;
            color: var(--sage-light);
            font-size: 10pt;
            letter-spacing: 1.4pt;
            text-transform: uppercase;
            vertical-align: top;
            width: 200pt;
            font-weight: 600;
        }
        table.inst .lv {
            padding: 12pt 0;
            line-height: 1.55;
            color: var(--ink);
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24pt;
        }

        .field-block .fk {
            font-size: 10pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--sage-light);
            margin-bottom: 8pt;
            font-weight: 600;
        }
        .field-block .fv {
            font-size: 13pt;
            line-height: 1.55;
            color: var(--ink);
        }
        .field-block + .field-block { margin-top: 18pt; }

        /* Bullet list - institutional */
        ul.inst-list { margin: 0; padding-left: 18pt; font-size: 12.5pt; line-height: 1.7; }
        ul.inst-list li { color: var(--ink); margin-bottom: 2pt; }
        ul.inst-list li::marker { color: var(--sage); }

        /* Data table for systems / 3rd parties */
        table.data {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5pt;
            margin-top: 4pt;
        }
        table.data th {
            background: var(--sage);
            color: var(--cream);
            text-align: left;
            padding: 10pt 12pt;
            font-size: 10pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            font-weight: 600;
        }
        table.data td {
            padding: 10pt 12pt;
            border-bottom: 1px solid var(--rule);
            vertical-align: top;
            line-height: 1.5;
        }
        table.data tr:nth-child(even) td { background: var(--cream); }
        table.data .seq {
            color: var(--sage-light);
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: 13pt;
            width: 28pt;
        }

        /* Pills */
        .pill-row { margin-top: 4pt; }
        .pill {
            display: inline-block;
            padding: 4pt 12pt;
            background: var(--cream);
            border: 1px solid var(--sage-light);
            color: var(--ink);
            font-size: 11pt;
            margin: 2pt 3pt 2pt 0;
        }
        .pill.pii { border-color: #8a2828; color: #8a2828; background: #f8e9e9; }

        /* QA cards */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14pt;
            margin-top: 4pt;
        }
        .qa {
            background: var(--cream);
            padding: 12pt 14pt;
            border-left: 3pt solid var(--sage);
        }
        .qa-k {
            font-size: 9.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--sage-light);
            margin-bottom: 6pt;
            font-weight: 600;
        }
        .qa-v {
            font-size: 13pt;
            color: var(--ink);
            font-weight: 500;
        }

        .risk-badge {
            display: inline-block;
            padding: 6pt 16pt;
            font-size: 10pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 600;
            color: #fff;
        }
        .risk-HIGH { background: #8a2828; }
        .risk-MEDIUM { background: #a06a1d; }
        .risk-LOW { background: var(--sage); }

        .page-footer {
            position: absolute;
            bottom: 28pt;
            left: 56pt; right: 56pt;
            display: flex;
            justify-content: space-between;
            border-top: 1px solid var(--rule);
            padding-top: 12pt;
            font-size: 10pt;
            color: var(--sage-light);
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
    <div class="sidebar">
        <div>
            <div class="sidebar-letters">
                <span>R</span><span>O</span><span>P</span><span>A</span>
            </div>
        </div>
        <div>
            <div class="sidebar-bottom-rule"></div>
            <div class="sb-label">Sertifikasi PDP</div>
            <div class="sb-italic">UU PDP 27/2022</div>
            <div class="sb-label" style="margin-top: 18pt;">Confidential</div>
            <div class="sb-italic">Internal Use Only</div>
        </div>
    </div>

    <div class="cover-main">
        <div>
            <div class="cover-eyebrow">Record of Processing</div>
            <h1 class="cover-title serif">Aktivitas<br>Pemrosesan<br>Data Pribadi</h1>
            <div class="cover-bar"></div>
            <div class="cover-name">{{ $r['name'] ?? '-' }}</div>
            <div class="cover-org">{{ $orgName }}</div>
        </div>
        <div class="cover-grid">
            <div><div class="cgk">Nomor Dokumen</div><div class="cgv">{{ $r['number'] ?? '-' }}</div></div>
            <div><div class="cgk">Tanggal Berlaku</div><div class="cgv">{{ $r['date'] ?? '-' }}</div></div>
            <div><div class="cgk">Divisi</div><div class="cgv">{{ $r['division'] ?? '-' }}</div></div>
            <div><div class="cgk">Kategori</div><div class="cgv">{{ $r['category'] ?? '-' }}</div></div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi + DPO/PIC
     ============================================================ --}}
<section class="page content">
    <div class="section-band">
        <div class="sb-title">Deskripsi Pemrosesan</div>
        <div class="sb-pill">&sect; 01</div>
    </div>
    <div class="body">
        <table class="inst">
            <tr><td class="lk">Nomor ROPA</td><td class="lv">{{ $r['number'] ?? '-' }}</td></tr>
            <tr><td class="lk">Nama Pemrosesan</td><td class="lv">{{ $r['name'] ?? '-' }}</td></tr>
            <tr><td class="lk">Divisi</td><td class="lv">{{ $r['division'] ?? '-' }}</td></tr>
            <tr><td class="lk">Unit Kerja</td><td class="lv">{{ $r['unit'] ?? '-' }}</td></tr>
            <tr><td class="lk">Entitas</td><td class="lv">{{ $orgName }}</td></tr>
            <tr><td class="lk">Kategori Perusahaan</td><td class="lv">{{ $r['category'] ?? '-' }}</td></tr>
            <tr><td class="lk">Deskripsi</td><td class="lv">{{ $r['description'] ?? '-' }}</td></tr>
        </table>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Pejabat PDP &amp; PIC</div>
    </div>
    <div class="body">
        <div class="two-col">
            <div class="field-block">
                <div class="fk">Data Protection Officer</div>
                <div class="fv">
                    <strong>{{ $r['dpo']['name'] ?? '-' }}</strong><br>
                    <span style="color: var(--sage-light); font-size: 11.5pt;">{{ $r['dpo']['email'] ?? '-' }}</span>
                    @if(!empty($r['dpo']['phone']))<br><span style="color: var(--sage-light); font-size: 11.5pt;">{{ $r['dpo']['phone'] }}</span>@endif
                </div>
            </div>
            <div class="field-block">
                <div class="fk">Process Owner / PIC</div>
                <div class="fv">
                    <strong>{{ $r['pic']['name'] ?? '-' }}</strong><br>
                    <span style="color: var(--sage-light); font-size: 11.5pt;">{{ $r['pic']['role'] ?? '-' }}</span><br>
                    <span style="color: var(--sage-light); font-size: 11.5pt;">{{ $r['pic']['email'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }} &middot; 02/0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan + Sistem + Teknologi
     ============================================================ --}}
<section class="page content">
    <div class="section-band">
        <div class="sb-title">Informasi Pemrosesan</div>
        <div class="sb-pill">&sect; 02</div>
    </div>
    <div class="body">
        <div class="two-col">
            <div>
                <div class="field-block">
                    <div class="fk">Tujuan</div>
                    <div class="fv">{{ $r['purpose'] ?? '-' }}</div>
                </div>
                <div class="field-block">
                    <div class="fk">Aktivitas Pemrosesan</div>
                    <div class="fv">{{ $r['activity'] ?? '-' }}</div>
                </div>
                <div class="field-block">
                    <div class="fk">Dasar Hukum</div>
                    <div class="fv">{{ $r['legal_basis'] ?? '-' }}</div>
                </div>
            </div>
            <div>
                <div class="field-block">
                    <div class="fk">Kategori Pemrosesan</div>
                    <ul class="inst-list">
                        @foreach($r['categories'] ?? [] as $c)
                            <li>{{ $c }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Sistem Informasi Terkait</div>
    </div>
    <div class="body" style="padding-top: 14pt;">
        <table class="data">
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
                        <td class="seq">{{ $i + 1 }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Teknologi &amp; Pemrofilan</div>
    </div>
    <div class="body" style="padding-top: 14pt;">
        <div class="qa-grid">
            <div class="qa"><div class="qa-k">Bantuan AI</div><div class="qa-v">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Keputusan Otomatis</div><div class="qa-v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Teknologi Baru</div><div class="qa-v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Tujuan Pemrofilan</div><div class="qa-v">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }} &middot; 03/0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page content">
    <div class="section-band">
        <div class="sb-title">Pengumpulan Data</div>
        <div class="sb-pill">&sect; 03</div>
    </div>
    <div class="body">
        <table class="inst">
            <tr><td class="lk">Jenis Subjek Data</td><td class="lv">
                <ul class="inst-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </td></tr>
            <tr><td class="lk">Jumlah Subjek Data</td><td class="lv">{{ $r['data_subjects_volume'] ?? '-' }}</td></tr>
            <tr><td class="lk">Sumber Pengumpulan</td><td class="lv">{{ $r['data_source'] ?? '-' }}</td></tr>
        </table>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Klasifikasi Data Pribadi</div>
    </div>
    <div class="body">
        <div class="field-block">
            <div class="fk">Data Umum</div>
            <div class="pill-row">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="field-block">
            <div class="fk">Data Spesifik</div>
            <div class="pill-row">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="field-block">
            <div class="fk">Data PII (Sensitif)</div>
            <div class="pill-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="pill pii">{{ $d }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }} &middot; 04/0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Pihak Ketiga
     ============================================================ --}}
<section class="page content">
    <div class="section-band">
        <div class="sb-title">Penggunaan &amp; Penyimpanan</div>
        <div class="sb-pill">&sect; 04</div>
    </div>
    <div class="body">
        <table class="inst">
            <tr><td class="lk">Kategori Pihak Pemroses</td><td class="lv">
                <ul class="inst-list">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <li>{{ $role }}</li>
                    @endforeach
                </ul>
            </td></tr>
            <tr><td class="lk">Pihak Pemroses Utama</td><td class="lv">{{ $r['processor_entity'] ?? '-' }}</td></tr>
            <tr><td class="lk">Pihak Ketiga Terlibat</td><td class="lv">{{ $r['has_third_party'] ?? '-' }}</td></tr>
        </table>
    </div>

    @if(!empty($r['third_parties']))
    <div class="section-band-soft">
        <div class="sb-title serif">Daftar Pihak Ketiga</div>
    </div>
    <div class="body" style="padding-top: 14pt;">
        <table class="data">
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
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: var(--sage-light); font-size: 9.5pt;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }} &middot; 05/0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data
     ============================================================ --}}
<section class="page content">
    <div class="section-band">
        <div class="sb-title">Pengiriman Data</div>
        <div class="sb-pill">&sect; 05</div>
    </div>
    <div class="body">
        <div class="qa-grid">
            <div class="qa"><div class="qa-k">Penerima Internal</div><div class="qa-v">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Penerima Eksternal</div><div class="qa-v">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Transfer Lintas Negara</div><div class="qa-v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Negara Tujuan</div><div class="qa-v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Jenis Data yang Dikirim</div>
    </div>
    <div class="body">
        <div class="field-block">
            <div class="fk">Umum</div>
            <div class="pill-row">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="field-block">
            <div class="fk">Spesifik</div>
            <div class="pill-row">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="pill">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="field-block">
            <div class="fk">PII</div>
            <div class="pill-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="pill pii">{{ $d }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }} &middot; 06/0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi, Keamanan, Risiko
     ============================================================ --}}
<section class="page content">
    <div class="section-band">
        <div class="sb-title">Retensi, Keamanan &amp; Risiko</div>
        <div class="sb-pill">&sect; 06</div>
    </div>
    <div class="body">
        <table class="inst">
            <tr><td class="lk">Nama Dokumen Terkait</td><td class="lv">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</td></tr>
            <tr><td class="lk">Masa Retensi</td><td class="lv">{{ $r['retention_period'] ?? '-' }}</td></tr>
            <tr><td class="lk">Tanggal Berlaku</td><td class="lv">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} &mdash; {{ $r['retention_end_date'] ?? '-' }}</td></tr>
            <tr><td class="lk">Aktivitas Penghapusan</td><td class="lv">{{ $r['has_deletion_activity'] ?? '-' }}</td></tr>
        </table>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Keamanan Data</div>
    </div>
    <div class="body">
        <div class="field-block">
            <div class="fk">Kontrol Keamanan</div>
            <ul class="inst-list">
                @foreach($r['controls'] ?? [] as $ctrl)
                    <li>{{ $ctrl }}</li>
                @endforeach
            </ul>
        </div>
        <div class="field-block">
            <div class="fk">Riwayat Insiden</div>
            <div class="fv" style="font-size: 13pt;">{{ $r['has_past_incident'] ?? '-' }}</div>
        </div>
    </div>

    <div class="section-band-soft">
        <div class="sb-title serif">Klasifikasi Risiko</div>
    </div>
    <div class="body">
        <div class="field-block">
            <div class="fk">Level Risiko</div>
            <div><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
        </div>
        <div class="field-block">
            <div class="fk">Justifikasi</div>
            <div class="fv" style="font-size: 13pt;">{{ $r['risk_justification'] ?? '-' }}</div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }} &middot; 07/0{{ $totalPages }}</span>
    </div>
</section>

</body>
</html>
