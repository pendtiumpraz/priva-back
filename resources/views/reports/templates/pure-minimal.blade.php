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
            color: #0a0a0a;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            -webkit-font-smoothing: antialiased;
        }

        :root {
            --paper: #fbfaf8;
            --ink: #0a0a0a;
            --gold: #c9b27a;
            --muted: #9b9b96;
            --soft: #5a5a55;
            --hair: #ececea;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: var(--paper);
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* ============================================================
           COVER — huge R·  hairline rule, 4-col data grid
           ============================================================ */
        .cover-top {
            padding: 44pt 64pt 0;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 400;
        }
        .cover-r {
            padding: 0 64pt;
            margin-top: 50pt;
            font-size: 240pt;
            font-weight: 200;
            line-height: .9;
            letter-spacing: -.06em;
            color: var(--ink);
        }
        .cover-r .dot { color: var(--gold); }
        .cover-eyebrow {
            padding: 0 64pt;
            font-size: 10pt;
            letter-spacing: 4pt;
            text-transform: uppercase;
            margin-top: 14pt;
            color: var(--soft);
            font-weight: 400;
        }

        .cover-rule {
            position: absolute;
            left: 64pt; right: 64pt;
            top: 545pt;
            height: 1px;
            background: var(--ink);
        }
        .cover-name {
            position: absolute;
            left: 64pt; right: 64pt;
            top: 580pt;
        }
        .cover-name h1 {
            font-size: 36pt;
            font-weight: 300;
            line-height: 1.15;
            letter-spacing: -.02em;
            margin: 0;
            max-width: 540pt;
            color: var(--ink);
        }
        .cover-name .org {
            margin-top: 12pt;
            font-size: 13pt;
            color: var(--soft);
            font-weight: 400;
        }

        .cover-grid {
            position: absolute;
            bottom: 48pt;
            left: 64pt; right: 64pt;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-top: 1px solid var(--ink);
            padding-top: 0;
        }
        .cover-grid-item {
            padding-top: 14pt;
            padding-right: 14pt;
        }
        .cgk {
            font-size: 8.5pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 400;
        }
        .cgv {
            margin-top: 8pt;
            font-size: 14pt;
            font-weight: 400;
            letter-spacing: -.01em;
            color: var(--ink);
        }

        /* ============================================================
           CONTENT — hairline rule field rows, oversized section numbers
           ============================================================ */
        .ph {
            padding: 44pt 64pt 0;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
        }
        .pf {
            position: absolute;
            bottom: 32pt;
            left: 64pt; right: 64pt;
            display: flex;
            justify-content: space-between;
            font-size: 8.5pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--muted);
        }

        .sec {
            margin-top: 36pt;
            padding: 0 64pt;
            page-break-inside: avoid;
        }
        .sec-head {
            display: flex;
            align-items: baseline;
            gap: 18pt;
            margin-bottom: 16pt;
        }
        .sec-num {
            font-size: 11pt;
            color: var(--gold);
            letter-spacing: 2.4pt;
            font-weight: 400;
            flex-shrink: 0;
        }
        .sec-title {
            font-size: 28pt;
            font-weight: 400;
            margin: 0;
            letter-spacing: -.02em;
            color: var(--ink);
        }

        .field {
            padding: 12pt 0;
            border-bottom: 1px solid var(--hair);
            display: grid;
            grid-template-columns: 160pt 1fr;
            gap: 24pt;
            font-size: 13pt;
        }
        .field:last-child { border-bottom: none; }
        .field-k {
            font-size: 9.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--muted);
            padding-top: 2pt;
            font-weight: 400;
        }
        .field-v {
            font-size: 13pt;
            line-height: 1.55;
            color: var(--ink);
            font-weight: 400;
        }
        .field-v .quiet {
            color: var(--soft);
            font-size: 11pt;
        }

        /* Minimal list */
        ul.list { margin: 0; padding: 0; list-style: none; }
        ul.list li {
            padding: 4pt 0;
            font-size: 13pt;
            color: var(--ink);
            border-bottom: 1px solid var(--hair);
        }
        ul.list li:last-child { border-bottom: none; }
        ul.list li::before {
            content: "—";
            color: var(--gold);
            margin-right: 10pt;
        }

        /* Tight data table for sistem informasi / pihak ketiga */
        table.t {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
            margin-top: 4pt;
        }
        table.t th {
            text-align: left;
            font-size: 8.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--muted);
            padding: 12pt 12pt 12pt 0;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            font-weight: 400;
        }
        table.t td {
            padding: 12pt 12pt 12pt 0;
            border-bottom: 1px solid var(--hair);
            vertical-align: top;
            line-height: 1.5;
            color: var(--ink);
        }
        table.t .seq {
            color: var(--gold);
            width: 30pt;
            font-size: 10pt;
            letter-spacing: 1.5pt;
        }

        /* QA tile - minimal */
        .qa-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border-top: 1px solid var(--hair);
        }
        .qa-cell {
            padding: 16pt 16pt 16pt 0;
            border-bottom: 1px solid var(--hair);
        }
        .qa-cell:nth-child(odd) {
            border-right: 1px solid var(--hair);
            padding-right: 24pt;
        }
        .qa-cell:nth-child(even) {
            padding-left: 24pt;
            padding-right: 0;
        }
        .qa-k {
            font-size: 8.5pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6pt;
        }
        .qa-v {
            font-size: 13pt;
            color: var(--ink);
        }

        /* Pills - minimal text + dot */
        .tag-row { margin-top: 6pt; }
        .tag {
            display: inline-block;
            font-size: 11pt;
            color: var(--ink);
            margin: 3pt 16pt 3pt 0;
            white-space: nowrap;
        }
        .tag::before {
            content: "";
            display: inline-block;
            width: 4pt; height: 4pt;
            background: var(--gold);
            border-radius: 50%;
            margin-right: 7pt;
            vertical-align: middle;
        }
        .tag.pii::before { background: #c84a4a; }
        .tag.pii { color: #8a2828; }

        .risk-badge {
            display: inline-block;
            padding: 4pt 14pt;
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 500;
            border: 1px solid var(--ink);
            color: var(--ink);
        }
        .risk-badge .dot {
            display: inline-block;
            width: 6pt; height: 6pt;
            border-radius: 50%;
            margin-right: 8pt;
            vertical-align: middle;
        }
        .risk-HIGH .dot { background: #c84a4a; }
        .risk-MEDIUM .dot { background: var(--gold); }
        .risk-LOW .dot { background: #4a6d3f; }
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
    <div class="cover-top">
        <span>{{ $orgName }}</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
    <div class="cover-r">R<span class="dot">·</span></div>
    <div class="cover-eyebrow">Record of Processing Activities</div>

    <div class="cover-rule"></div>
    <div class="cover-name">
        <h1>{{ $r['name'] ?? '-' }}</h1>
        <div class="org">{{ $orgName }}</div>
    </div>

    <div class="cover-grid">
        <div class="cover-grid-item">
            <div class="cgk">Document</div>
            <div class="cgv">{{ $r['number'] ?? '-' }}</div>
        </div>
        <div class="cover-grid-item">
            <div class="cgk">Effective</div>
            <div class="cgv">{{ $r['date'] ?? '-' }}</div>
        </div>
        <div class="cover-grid-item">
            <div class="cgk">Division</div>
            <div class="cgv">{{ $r['division'] ?? '-' }}</div>
        </div>
        <div class="cover-grid-item">
            <div class="cgk">Status</div>
            <div class="cgv">Active</div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Deskripsi Pemrosesan + DPO/PIC
     ============================================================ --}}
<section class="page">
    <div class="ph">
        <span>{{ $orgName }}</span>
        <span>02 / 0{{ $totalPages }}</span>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">01</span>
            <h2 class="sec-title">Deskripsi Pemrosesan</h2>
        </div>
        <div class="field"><div class="field-k">Nomor ROPA</div><div class="field-v">{{ $r['number'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Nama Pemrosesan</div><div class="field-v">{{ $r['name'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Divisi</div><div class="field-v">{{ $r['division'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Unit Kerja</div><div class="field-v">{{ $r['unit'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Entitas</div><div class="field-v">{{ $orgName }}</div></div>
        <div class="field"><div class="field-k">Kategori</div><div class="field-v">{{ $r['category'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Deskripsi Singkat</div><div class="field-v">{{ $r['description'] ?? '-' }}</div></div>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">02</span>
            <h2 class="sec-title">Pejabat PDP &amp; PIC</h2>
        </div>
        <div class="field">
            <div class="field-k">DPO</div>
            <div class="field-v">
                {{ $r['dpo']['name'] ?? '-' }}
                <div class="quiet">{{ $r['dpo']['email'] ?? '-' }}{!! !empty($r['dpo']['phone']) ? ' &middot; ' . e($r['dpo']['phone']) : '' !!}</div>
            </div>
        </div>
        <div class="field">
            <div class="field-k">PIC</div>
            <div class="field-v">
                {{ $r['pic']['name'] ?? '-' }}
                <div class="quiet">{{ $r['pic']['role'] ?? '-' }} &middot; {{ $r['pic']['email'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="pf">
        <span>Confidential</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — Informasi Pemrosesan + Sistem + Teknologi
     ============================================================ --}}
<section class="page">
    <div class="ph">
        <span>{{ $orgName }}</span>
        <span>03 / 0{{ $totalPages }}</span>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">03</span>
            <h2 class="sec-title">Informasi Pemrosesan</h2>
        </div>
        <div class="field"><div class="field-k">Tujuan</div><div class="field-v">{{ $r['purpose'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Aktivitas</div><div class="field-v">{{ $r['activity'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Dasar Hukum</div><div class="field-v">{{ $r['legal_basis'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Kategori</div><div class="field-v">{{ implode(' · ', $r['categories'] ?? []) }}</div></div>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">04</span>
            <h2 class="sec-title">Sistem Informasi</h2>
        </div>
        <table class="t">
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
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">05</span>
            <h2 class="sec-title">Teknologi &amp; Pemrofilan</h2>
        </div>
        <div class="qa-grid">
            <div class="qa-cell">
                <div class="qa-k">Bantuan AI</div>
                <div class="qa-v">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="qa-k">Keputusan Otomatis</div>
                <div class="qa-v">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="qa-k">Teknologi Baru</div>
                <div class="qa-v">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="qa-k">Tujuan Pemrofilan</div>
                <div class="qa-v">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="pf">
        <span>Confidential</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="ph">
        <span>{{ $orgName }}</span>
        <span>04 / 0{{ $totalPages }}</span>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">06</span>
            <h2 class="sec-title">Pengumpulan Data</h2>
        </div>
        <div class="field"><div class="field-k">Jenis Subjek</div><div class="field-v">
            <ul class="list">
                @foreach($r['data_subjects'] ?? [] as $s)
                    <li>{{ $s }}</li>
                @endforeach
            </ul>
        </div></div>
        <div class="field"><div class="field-k">Jumlah Subjek</div><div class="field-v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Sumber</div><div class="field-v">{{ $r['data_source'] ?? '-' }}</div></div>

        <div class="field"><div class="field-k">Data Umum</div><div class="field-v">
            <div class="tag-row">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="tag">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Data Spesifik</div><div class="field-v">
            <div class="tag-row">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="tag">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Data PII</div><div class="field-v">
            <div class="tag-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="tag pii">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
    </div>

    <div class="pf">
        <span>Confidential</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — Penggunaan & Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="ph">
        <span>{{ $orgName }}</span>
        <span>05 / 0{{ $totalPages }}</span>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">07</span>
            <h2 class="sec-title">Penggunaan &amp; Penyimpanan</h2>
        </div>
        <div class="field"><div class="field-k">Kategori Pemroses</div><div class="field-v">
            <ul class="list">
                @foreach($r['processor_role'] ?? [] as $role)
                    <li>{{ $role }}</li>
                @endforeach
            </ul>
        </div></div>
        <div class="field"><div class="field-k">Pemroses Utama</div><div class="field-v">{{ $r['processor_entity'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Pihak Ketiga</div><div class="field-v">{{ $r['has_third_party'] ?? '-' }}</div></div>
    </div>

    @if(!empty($r['third_parties']))
    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">08</span>
            <h2 class="sec-title">Pihak Ketiga</h2>
        </div>
        <table class="t">
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
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="color: var(--muted); font-size: 9.5pt;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="pf">
        <span>Confidential</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — Pengiriman Data
     ============================================================ --}}
<section class="page">
    <div class="ph">
        <span>{{ $orgName }}</span>
        <span>06 / 0{{ $totalPages }}</span>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">09</span>
            <h2 class="sec-title">Pengiriman Data</h2>
        </div>
        <div class="qa-grid">
            <div class="qa-cell">
                <div class="qa-k">Penerima Internal</div>
                <div class="qa-v">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="qa-k">Penerima Eksternal</div>
                <div class="qa-v">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="qa-k">Transfer Lintas Negara</div>
                <div class="qa-v">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="qa-cell">
                <div class="qa-k">Negara Tujuan</div>
                <div class="qa-v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
            </div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">10</span>
            <h2 class="sec-title">Jenis Data yang Dikirim</h2>
        </div>
        <div class="field"><div class="field-k">Umum</div><div class="field-v">
            <div class="tag-row">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="tag">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">Spesifik</div><div class="field-v">
            <div class="tag-row">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="tag">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
        <div class="field"><div class="field-k">PII</div><div class="field-v">
            <div class="tag-row">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="tag pii">{{ $d }}</span>
                @endforeach
            </div>
        </div></div>
    </div>

    <div class="pf">
        <span>Confidential</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — Retensi, Keamanan, Risiko
     ============================================================ --}}
<section class="page">
    <div class="ph">
        <span>{{ $orgName }}</span>
        <span>07 / 0{{ $totalPages }}</span>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">11</span>
            <h2 class="sec-title">Retensi Data</h2>
        </div>
        <div class="field"><div class="field-k">Dokumen Terkait</div><div class="field-v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Masa Retensi</div><div class="field-v">{{ $r['retention_period'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Tanggal Berlaku</div><div class="field-v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} &mdash; {{ $r['retention_end_date'] ?? '-' }}</div></div>
        <div class="field"><div class="field-k">Penghapusan</div><div class="field-v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">12</span>
            <h2 class="sec-title">Keamanan Data</h2>
        </div>
        <div class="field"><div class="field-k">Kontrol Keamanan</div><div class="field-v">
            <ul class="list">
                @foreach($r['controls'] ?? [] as $ctrl)
                    <li>{{ $ctrl }}</li>
                @endforeach
            </ul>
        </div></div>
        <div class="field"><div class="field-k">Riwayat Insiden</div><div class="field-v">{{ $r['has_past_incident'] ?? '-' }}</div></div>
    </div>

    <div class="sec">
        <div class="sec-head">
            <span class="sec-num">13</span>
            <h2 class="sec-title">Klasifikasi Risiko</h2>
        </div>
        <div class="field"><div class="field-k">Level Risiko</div><div class="field-v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}"><span class="dot"></span>{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div></div>
        <div class="field"><div class="field-k">Justifikasi</div><div class="field-v">{{ $r['risk_justification'] ?? '-' }}</div></div>
    </div>

    <div class="pf">
        <span>Confidential</span>
        <span>{{ $r['number'] ?? '-' }}</span>
    </div>
</section>

</body>
</html>
