<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            color: #19124e;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            -webkit-font-smoothing: antialiased;
        }

        :root {
            --lilac: #f4f0ff;
            --indigo: #19124e;
            --violet: #4b3aa6;
            --purple: #6852c8;
            --chip: #e9e1ff;
            --soft: #f7f4ff;
            --border: #e3dbff;
            --muted: #9b8fd0;
            --text: #3d3373;
            --pii: #c4344a;
            --pii-bg: #ffe5ea;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: #fff;
        }
        .page:last-child { page-break-after: auto; }

        /* ============================================================
           COVER — lilac bg + gradient circle + decorative shapes
           ============================================================ */
        .cover { background: var(--lilac); }
        .blob-grad {
            position: absolute;
            top: -180pt; right: -180pt;
            width: 480pt; height: 480pt;
            border-radius: 50%;
            background: linear-gradient(135deg, #19124e 0%, #4b3aa6 100%);
        }
        .blob-mid {
            position: absolute;
            top: 100pt; right: 80pt;
            width: 140pt; height: 140pt;
            border-radius: 50%;
            background: #c4b6ff;
        }
        .blob-small {
            position: absolute;
            top: 240pt; right: 200pt;
            width: 60pt; height: 60pt;
            border-radius: 50%;
            background: var(--indigo);
        }
        .blob-bottom {
            position: absolute;
            bottom: -120pt; left: -120pt;
            width: 320pt; height: 320pt;
            border-radius: 50%;
            background: rgba(25,18,78,.08);
        }

        .cover-inner {
            position: absolute;
            top: 72pt; left: 64pt; right: 64pt; bottom: 72pt;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 2;
        }

        .logo-row {
            display: flex;
            align-items: center;
            gap: 12pt;
        }
        .logo-mark {
            width: 36pt; height: 36pt;
            border-radius: 10pt;
            background: var(--indigo);
            color: var(--lilac);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16pt;
        }
        .logo-text {
            font-size: 13pt;
            font-weight: 600;
            letter-spacing: -.01em;
        }

        .cover-eyebrow {
            font-size: 12pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 600;
        }
        .cover-h1 {
            font-size: 76pt;
            line-height: .98;
            font-weight: 800;
            letter-spacing: -.03em;
            margin: 16pt 0 24pt;
            color: var(--indigo);
        }
        .cover-h1 .accent { color: var(--purple); }
        .cover-desc {
            font-size: 14pt;
            color: var(--text);
            max-width: 540pt;
            line-height: 1.55;
            font-weight: 500;
        }

        .cover-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12pt;
        }
        .cc {
            background: #fff;
            border-radius: 18pt;
            padding: 16pt 18pt;
            border: 1px solid var(--border);
        }
        .cck {
            font-size: 9pt;
            letter-spacing: 2.2pt;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 700;
        }
        .ccv {
            margin-top: 8pt;
            font-size: 14pt;
            font-weight: 600;
            color: var(--indigo);
        }

        /* ============================================================
           CONTENT — gradient header band w/ rounded bottom, soft cards
           ============================================================ */
        .content-head {
            background: linear-gradient(120deg, #19124e, #4b3aa6);
            color: var(--lilac);
            padding: 26pt 56pt;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom-left-radius: 28pt;
            border-bottom-right-radius: 28pt;
        }
        .ch-eyebrow {
            font-size: 9pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            opacity: .75;
            font-weight: 600;
        }
        .ch-title {
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: -.01em;
            margin-top: 4pt;
        }
        .ch-ref {
            font-size: 11pt;
            opacity: .85;
            font-weight: 500;
        }

        .body {
            padding: 32pt 56pt 56pt;
        }

        .card-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14pt;
        }
        .scard {
            background: var(--soft);
            border-radius: 16pt;
            padding: 14pt 16pt;
        }
        .scard-k {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 700;
        }
        .scard-v {
            margin-top: 6pt;
            font-size: 13pt;
            font-weight: 600;
            color: var(--indigo);
            line-height: 1.5;
        }
        .scard.full {
            grid-column: 1 / -1;
        }
        .scard.full .scard-v {
            font-weight: 500;
            font-size: 12.5pt;
            line-height: 1.6;
        }

        .sec-divider {
            margin-top: 28pt;
            display: flex;
            align-items: baseline;
            gap: 12pt;
        }
        .sec-dot {
            width: 8pt; height: 8pt;
            border-radius: 50%;
            background: var(--purple);
            display: inline-block;
        }
        .sec-h {
            margin: 0;
            font-size: 20pt;
            font-weight: 700;
            letter-spacing: -.01em;
            color: var(--indigo);
        }

        /* Chip styling */
        .chips { margin-top: 6pt; }
        .chip {
            display: inline-block;
            padding: 5pt 12pt;
            border-radius: 999pt;
            background: var(--chip);
            color: var(--indigo);
            font-size: 11pt;
            font-weight: 500;
            margin-right: 6pt;
            margin-bottom: 6pt;
        }
        .chip.pii {
            background: var(--pii-bg);
            color: var(--pii);
        }

        /* Table */
        .ptable {
            margin-top: 14pt;
            background: var(--soft);
            border-radius: 16pt;
            overflow: hidden;
        }
        .ptable table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
        }
        .ptable th {
            text-align: left;
            font-size: 9pt;
            letter-spacing: 1.8pt;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 700;
            padding: 12pt 14pt;
            border-bottom: 1px solid var(--border);
        }
        .ptable td {
            padding: 10pt 14pt;
            vertical-align: top;
            border-bottom: 1px solid var(--border);
            color: var(--indigo);
            line-height: 1.5;
        }
        .ptable tr:last-child td { border-bottom: none; }
        .ptable .seq {
            width: 32pt;
            background: var(--purple);
            color: var(--lilac);
            font-weight: 700;
            text-align: center;
            border-radius: 6pt;
            padding: 4pt 0;
            display: inline-block;
            min-width: 24pt;
            font-size: 10pt;
        }

        /* Inline label block (used in cards for grouped data) */
        .ilab {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 700;
            margin-top: 12pt;
        }
        .ilab:first-child { margin-top: 0; }
        .ival {
            margin-top: 6pt;
            font-size: 12.5pt;
            color: var(--indigo);
            line-height: 1.55;
        }

        /* QA boxes */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12pt;
            margin-top: 8pt;
        }
        .qa {
            background: var(--soft);
            border-radius: 14pt;
            padding: 14pt 16pt;
            border-left: 4pt solid var(--purple);
        }
        .qa-k {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 700;
        }
        .qa-v {
            margin-top: 6pt;
            font-size: 13pt;
            color: var(--indigo);
            font-weight: 600;
        }

        .risk-badge {
            display: inline-block;
            padding: 7pt 18pt;
            border-radius: 999pt;
            font-size: 11pt;
            font-weight: 700;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
        }
        .risk-HIGH { background: #ffe5ea; color: #c4344a; }
        .risk-MEDIUM { background: #fff1d6; color: #a06a1d; }
        .risk-LOW { background: #d6f1de; color: #2d6a3e; }

        .page-footer {
            position: absolute;
            bottom: 24pt;
            left: 56pt; right: 56pt;
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            color: var(--muted);
            font-weight: 500;
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $nameParts = explode(' via ', (string)($r['name'] ?? ''));
    $namePart1 = $nameParts[0] ?? ($r['name'] ?? '-');
    $namePart2 = $nameParts[1] ?? null;
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page cover">
    <div class="blob-grad"></div>
    <div class="blob-mid"></div>
    <div class="blob-small"></div>
    <div class="blob-bottom"></div>

    <div class="cover-inner">
        <div class="logo-row">
            <div class="logo-mark">R</div>
            <div class="logo-text">ROPA Export</div>
        </div>

        <div>
            <div class="cover-eyebrow">Record of Processing Activities</div>
            <h1 class="cover-h1">
                {{ $namePart1 }}@if($namePart2)<br><span class="accent">via {{ $namePart2 }}.</span>@endif
            </h1>
            <div class="cover-desc">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="cover-cards">
            <div class="cc"><div class="cck">ROPA No.</div><div class="ccv">{{ $r['number'] ?? '-' }}</div></div>
            <div class="cc"><div class="cck">Divisi</div><div class="ccv">{{ $r['division'] ?? '-' }}</div></div>
            <div class="cc"><div class="cck">Tanggal</div><div class="ccv">{{ $r['date'] ?? '-' }}</div></div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi + DPO/PIC
     ============================================================ --}}
<section class="page" style="background: #fff;">
    <div class="content-head">
        <div>
            <div class="ch-eyebrow">Bagian 01</div>
            <div class="ch-title">Deskripsi Pemrosesan</div>
        </div>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>

    <div class="body">
        <div class="card-grid">
            <div class="scard"><div class="scard-k">Nama Pemrosesan</div><div class="scard-v">{{ $r['name'] ?? '-' }}</div></div>
            <div class="scard"><div class="scard-k">Entitas</div><div class="scard-v">{{ $orgName }}</div></div>
            <div class="scard"><div class="scard-k">Divisi</div><div class="scard-v">{{ $r['division'] ?? '-' }}</div></div>
            <div class="scard"><div class="scard-k">Unit Kerja</div><div class="scard-v">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="scard full"><div class="scard-k">Deskripsi Singkat</div><div class="scard-v">{{ $r['description'] ?? '-' }}</div></div>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Pejabat PDP &amp; PIC</h3>
        </div>
        <div class="card-grid" style="margin-top: 14pt;">
            <div class="scard">
                <div class="scard-k">Data Protection Officer</div>
                <div class="scard-v">{{ $r['dpo']['name'] ?? '-' }}</div>
                <div style="margin-top: 4pt; font-size: 11pt; color: var(--text); font-weight: 500;">{{ $r['dpo']['email'] ?? '-' }}</div>
                @if(!empty($r['dpo']['phone']))
                    <div style="font-size: 11pt; color: var(--text); font-weight: 500;">{{ $r['dpo']['phone'] }}</div>
                @endif
            </div>
            <div class="scard">
                <div class="scard-k">Process Owner / PIC</div>
                <div class="scard-v">{{ $r['pic']['name'] ?? '-' }}</div>
                <div style="margin-top: 4pt; font-size: 11pt; color: var(--text); font-weight: 500;">{{ $r['pic']['role'] ?? '-' }}</div>
                <div style="font-size: 11pt; color: var(--text); font-weight: 500;">{{ $r['pic']['email'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>02 / 0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan + Sistem + Teknologi
     ============================================================ --}}
<section class="page" style="background: #fff;">
    <div class="content-head">
        <div>
            <div class="ch-eyebrow">Bagian 02 — 04</div>
            <div class="ch-title">Informasi &amp; Sistem</div>
        </div>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>

    <div class="body">
        <div class="scard full">
            <div class="ilab">Tujuan</div>
            <div class="ival">{{ $r['purpose'] ?? '-' }}</div>
            <div class="ilab">Aktivitas Pemrosesan</div>
            <div class="ival">{{ $r['activity'] ?? '-' }}</div>
            <div class="ilab">Dasar Hukum</div>
            <div class="ival"><span class="chip">{{ $r['legal_basis'] ?? '-' }}</span></div>
            <div class="ilab">Kategori Pemrosesan</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['categories'] ?? [] as $c)
                        <span class="chip">{{ $c }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Sistem Informasi Terkait</h3>
        </div>
        <div class="ptable">
            <table>
                <thead>
                    <tr>
                        <th style="width: 8%;">No.</th>
                        <th>Nama Sistem</th>
                        <th>Lokasi Penyimpanan</th>
                        <th>Lokasi Penggunaan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['systems'] ?? [] as $i => $sys)
                        <tr>
                            <td><span class="seq">{{ $i + 1 }}</span></td>
                            <td style="font-weight: 600;">{{ $sys['name'] ?? '-' }}</td>
                            <td>{{ $sys['loc'] ?? '-' }}</td>
                            <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Teknologi &amp; Pemrofilan</h3>
        </div>
        <div class="qa-grid">
            <div class="qa"><div class="qa-k">Bantuan AI</div><div class="qa-v">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Keputusan Otomatis</div><div class="qa-v">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Teknologi Baru</div><div class="qa-v">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Tujuan Pemrofilan</div><div class="qa-v">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>03 / 0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page" style="background: #fff;">
    <div class="content-head">
        <div>
            <div class="ch-eyebrow">Bagian 05</div>
            <div class="ch-title">Pengumpulan Data</div>
        </div>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>

    <div class="body">
        <div class="card-grid">
            <div class="scard">
                <div class="scard-k">Jenis Subjek Data</div>
                <div class="chips" style="margin-top: 8pt;">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <span class="chip">{{ $s }}</span>
                    @endforeach
                </div>
            </div>
            <div class="scard">
                <div class="scard-k">Jumlah Subjek Data</div>
                <div class="scard-v">{{ $r['data_subjects_volume'] ?? '-' }}</div>
                <div style="margin-top: 12pt;" class="scard-k">Sumber Pengumpulan</div>
                <div class="scard-v" style="font-weight: 500; font-size: 12pt;">{{ $r['data_source'] ?? '-' }}</div>
            </div>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Klasifikasi Data Pribadi</h3>
        </div>

        <div class="scard full" style="margin-top: 14pt;">
            <div class="ilab">Data Pribadi &mdash; Umum</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="chip">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="ilab">Data Pribadi &mdash; Spesifik</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <span class="chip">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="ilab">Data Pribadi &mdash; PII (Sensitif)</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="chip pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>04 / 0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Pihak Ketiga
     ============================================================ --}}
<section class="page" style="background: #fff;">
    <div class="content-head">
        <div>
            <div class="ch-eyebrow">Bagian 06 — 07</div>
            <div class="ch-title">Penggunaan &amp; Pihak Ketiga</div>
        </div>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>

    <div class="body">
        <div class="scard full">
            <div class="ilab">Kategori Pihak Pemroses</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <span class="chip">{{ $role }}</span>
                    @endforeach
                </div>
            </div>
            <div class="ilab">Pihak Pemroses Utama</div>
            <div class="ival">{{ $r['processor_entity'] ?? '-' }}</div>
            <div class="ilab">Pihak Ketiga Terlibat</div>
            <div class="ival">{{ $r['has_third_party'] ?? '-' }}</div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Daftar Pihak Ketiga</h3>
        </div>
        <div class="ptable">
            <table>
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
                            <td><span class="seq">{{ $i + 1 }}</span></td>
                            <td style="font-weight: 600;">{{ $tp['name'] ?? '-' }}</td>
                            <td>{{ $tp['address'] ?? '-' }}</td>
                            <td>{{ $tp['pic_name'] ?? '-' }}</td>
                            <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: var(--muted); font-size: 9.5pt;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>05 / 0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data
     ============================================================ --}}
<section class="page" style="background: #fff;">
    <div class="content-head">
        <div>
            <div class="ch-eyebrow">Bagian 08 — 09</div>
            <div class="ch-title">Pengiriman Data</div>
        </div>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>

    <div class="body">
        <div class="qa-grid">
            <div class="qa"><div class="qa-k">Penerima Internal</div><div class="qa-v">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Penerima Eksternal</div><div class="qa-v">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Transfer Lintas Negara</div><div class="qa-v">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="qa"><div class="qa-k">Negara Tujuan</div><div class="qa-v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Jenis Data yang Dikirim</h3>
        </div>

        <div class="scard full" style="margin-top: 14pt;">
            <div class="ilab">Umum</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="chip">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="ilab">Spesifik</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <span class="chip">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
            <div class="ilab">PII</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="chip pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>06 / 0{{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi, Keamanan, Risiko
     ============================================================ --}}
<section class="page" style="background: #fff;">
    <div class="content-head">
        <div>
            <div class="ch-eyebrow">Bagian 10 — 12</div>
            <div class="ch-title">Retensi &amp; Risiko</div>
        </div>
        <div class="ch-ref">{{ $r['number'] ?? '-' }}</div>
    </div>

    <div class="body">
        <div class="scard full">
            <div class="ilab">Nama Dokumen Terkait</div>
            <div class="ival">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div>
            <div class="ilab">Masa Retensi</div>
            <div class="ival">{{ $r['retention_period'] ?? '-' }}</div>
            <div class="ilab">Tanggal Berlaku</div>
            <div class="ival">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} &mdash; {{ $r['retention_end_date'] ?? '-' }}</div>
            <div class="ilab">Aktivitas Penghapusan</div>
            <div class="ival">{{ $r['has_deletion_activity'] ?? '-' }}</div>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Keamanan Data</h3>
        </div>
        <div class="scard full" style="margin-top: 14pt;">
            <div class="ilab">Kontrol Keamanan</div>
            <div class="ival">
                <div class="chips">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <span class="chip">{{ $ctrl }}</span>
                    @endforeach
                </div>
            </div>
            <div class="ilab">Riwayat Insiden</div>
            <div class="ival">{{ $r['has_past_incident'] ?? '-' }}</div>
        </div>

        <div class="sec-divider">
            <span class="sec-dot"></span>
            <h3 class="sec-h">Klasifikasi Risiko</h3>
        </div>
        <div class="scard full" style="margin-top: 14pt;">
            <div class="ilab">Level Risiko</div>
            <div class="ival"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            <div class="ilab">Justifikasi</div>
            <div class="ival">{{ $r['risk_justification'] ?? '-' }}</div>
        </div>
    </div>

    <div class="page-footer">
        <span>{{ $orgName }}</span>
        <span>07 / 0{{ $totalPages }}</span>
    </div>
</section>

</body>
</html>
