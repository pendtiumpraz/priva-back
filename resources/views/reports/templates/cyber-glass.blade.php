<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #e0fcff;
        }

        /* =========================================================
           Cyber Glass palette
           ========================================================= */
        :root {
            --deep-1: #021b1f;
            --deep-2: #032c33;
            --ice: #e0fcff;
            --ice-soft: rgba(224, 252, 255, 0.85);
            --ice-mute: rgba(224, 252, 255, 0.55);
            --ice-dim: rgba(224, 252, 255, 0.3);
            --cyan: #00d9ff;
            --green: #00ff99;
            --glass-bg: rgba(255, 255, 255, 0.04);
            --glass-bg-accent: rgba(0, 217, 255, 0.08);
            --glass-border: rgba(0, 217, 255, 0.2);
            --glass-border-accent: rgba(0, 217, 255, 0.4);
            --grid-line: rgba(0, 217, 255, 0.06);
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: linear-gradient(140deg, var(--deep-1) 0%, var(--deep-2) 50%, var(--deep-1) 100%);
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* Aurora blobs (signature) */
        .aurora {
            position: absolute;
            border-radius: 50%;
            filter: blur(36pt);
            pointer-events: none;
            z-index: 1;
        }
        .aurora.cyan {
            background: radial-gradient(circle, rgba(0, 217, 255, 0.4), transparent 70%);
        }
        .aurora.green {
            background: radial-gradient(circle, rgba(0, 255, 153, 0.4), transparent 70%);
        }

        /* Grid overlay (signature) */
        .grid-overlay {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(var(--grid-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
            background-size: 26pt 26pt;
            pointer-events: none;
            z-index: 2;
        }

        .shell {
            position: relative;
            z-index: 3;
            padding: 16mm 14mm;
        }

        /* =========================================================
           COVER
           ========================================================= */
        .cover-shell {
            position: relative;
            z-index: 3;
            padding: 18mm;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .cover-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .live-pill {
            display: inline-flex;
            align-items: center;
            gap: 8pt;
            padding: 6pt 12pt;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(0, 217, 255, 0.3);
            border-radius: 999pt;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .live-pill .dot {
            width: 7pt; height: 7pt;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 10pt var(--cyan);
        }
        .live-pill .lab {
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            color: var(--cyan);
            font-weight: 600;
        }
        .cover-ref {
            font-size: 9pt;
            letter-spacing: 2pt;
            color: var(--ice-mute);
            font-family: 'JetBrains Mono', monospace;
        }

        .cover-mid .kicker {
            font-size: 9.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--cyan);
            font-weight: 600;
        }
        .cover-title {
            margin: 12pt 0 0;
            font-size: 112pt;
            font-weight: 800;
            line-height: 0.9;
            letter-spacing: -5pt;
            color: var(--ice);
        }
        .cover-title .grad {
            background: linear-gradient(90deg, var(--cyan), var(--green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: transparent;
        }
        .cover-abstract-card {
            margin-top: 22pt;
            max-width: 470pt;
            padding: 14pt 18pt;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12pt;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .cover-abstract-card .body {
            font-size: 12.5pt;
            line-height: 1.55;
            color: var(--ice);
        }

        .cover-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10pt;
        }
        .cover-stat {
            padding: 14pt 16pt;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12pt;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .cover-stat .k {
            font-size: 8.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: var(--cyan);
            font-weight: 600;
        }
        .cover-stat .v {
            margin-top: 6pt;
            font-size: 13pt;
            font-weight: 600;
            color: var(--ice);
        }

        /* =========================================================
           CONTENT
           ========================================================= */
        .content-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 14pt;
            border-bottom: 1px solid var(--glass-border);
            margin-bottom: 18pt;
        }
        .content-head-left {
            display: flex;
            align-items: baseline;
            gap: 14pt;
        }
        .neon-num {
            font-size: 44pt;
            font-weight: 800;
            letter-spacing: -2.5pt;
            line-height: 1;
        }
        .neon-num.cyan {
            color: var(--cyan);
            text-shadow: 0 0 20pt rgba(0, 217, 255, 0.6), 0 0 36pt rgba(0, 217, 255, 0.3);
        }
        .neon-num.green {
            color: var(--green);
            text-shadow: 0 0 20pt rgba(0, 255, 153, 0.6), 0 0 36pt rgba(0, 255, 153, 0.3);
        }
        .content-head h2 {
            margin: 0;
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: -0.8pt;
            color: var(--ice);
        }
        .content-head-right {
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            letter-spacing: 2pt;
            color: var(--ice-mute);
        }

        /* Glass cards */
        .glass {
            padding: 12pt 14pt;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12pt;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .glass.accent {
            background: var(--glass-bg-accent);
            border-color: var(--glass-border-accent);
        }
        .glass.green-accent {
            background: rgba(0, 255, 153, 0.08);
            border-color: rgba(0, 255, 153, 0.35);
        }
        .glass .k {
            font-size: 8.5pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: var(--cyan);
            font-weight: 700;
        }
        .glass.green-accent .k { color: var(--green); }
        .glass .v {
            margin-top: 6pt;
            font-size: 12pt;
            color: var(--ice);
            line-height: 1.45;
        }
        .glass .v.lg { font-size: 14pt; font-weight: 600; }
        .glass .v.body { font-size: 11.5pt; color: var(--ice-soft); }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10pt;
            margin-bottom: 12pt;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10pt;
            margin-bottom: 12pt;
        }
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10pt;
            margin-bottom: 12pt;
        }
        .full { grid-column: 1 / -1; }

        /* Mono terminal chip */
        .mono-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 5pt;
            margin-top: 6pt;
        }
        .mono-chip {
            font-family: 'JetBrains Mono', monospace;
            padding: 3pt 9pt;
            border: 1px solid var(--glass-border-accent);
            border-radius: 6pt;
            color: var(--cyan);
            font-size: 9.5pt;
            font-weight: 500;
            background: rgba(0, 217, 255, 0.05);
        }
        .mono-chip.green {
            color: var(--green);
            border-color: rgba(0, 255, 153, 0.4);
            background: rgba(0, 255, 153, 0.05);
        }
        .mono-chip.danger {
            color: #ff5577;
            border-color: rgba(255, 85, 119, 0.45);
            background: rgba(255, 85, 119, 0.08);
        }

        /* Glass table */
        table.glass-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 4pt;
            margin-bottom: 12pt;
            font-size: 10pt;
            border: 1px solid var(--glass-border);
            border-radius: 12pt;
            overflow: hidden;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        table.glass-table th {
            text-align: left;
            padding: 9pt 12pt;
            font-size: 8.5pt;
            letter-spacing: 2.2pt;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--cyan);
            border-bottom: 1px solid var(--glass-border);
            background: rgba(0, 217, 255, 0.05);
        }
        table.glass-table td {
            padding: 9pt 12pt;
            border-bottom: 1px solid rgba(0, 217, 255, 0.1);
            vertical-align: top;
            line-height: 1.5;
            color: var(--ice);
        }
        table.glass-table tr:last-child td { border-bottom: none; }
        table.glass-table .seq {
            color: var(--green);
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            width: 30pt;
        }

        .risk-badge {
            display: inline-block;
            padding: 6pt 16pt;
            font-size: 9pt;
            letter-spacing: 2pt;
            text-transform: uppercase;
            font-weight: 700;
            border-radius: 999pt;
            border: 1px solid;
            font-family: 'JetBrains Mono', monospace;
        }
        .risk-HIGH {
            background: rgba(255, 85, 119, 0.15);
            color: #ff5577;
            border-color: rgba(255, 85, 119, 0.5);
            text-shadow: 0 0 12pt rgba(255, 85, 119, 0.5);
        }
        .risk-MEDIUM {
            background: rgba(255, 217, 0, 0.12);
            color: #ffd900;
            border-color: rgba(255, 217, 0, 0.45);
            text-shadow: 0 0 10pt rgba(255, 217, 0, 0.4);
        }
        .risk-LOW {
            background: rgba(0, 255, 153, 0.12);
            color: var(--green);
            border-color: rgba(0, 255, 153, 0.45);
            text-shadow: 0 0 10pt rgba(0, 255, 153, 0.4);
        }

        .footer {
            position: absolute;
            left: 14mm; right: 14mm;
            bottom: 10mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'JetBrains Mono', monospace;
            font-size: 8.5pt;
            letter-spacing: 1.5pt;
            color: var(--ice-mute);
            z-index: 5;
        }
        .footer .glow {
            color: var(--cyan);
            text-shadow: 0 0 8pt rgba(0, 217, 255, 0.5);
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $orgShort = mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', $orgName), 0, 28));
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page">
    <div class="aurora cyan" style="top: -40mm; right: -40mm; width: 140mm; height: 140mm;"></div>
    <div class="aurora green" style="bottom: -50mm; left: -30mm; width: 130mm; height: 130mm;"></div>
    <div class="grid-overlay"></div>

    <div class="cover-shell">
        <div class="cover-top">
            <div class="live-pill">
                <span class="dot"></span>
                <span class="lab">ROPA · Live</span>
            </div>
            <div class="cover-ref">{{ $r['number'] ?? '-' }}</div>
        </div>

        <div class="cover-mid">
            <div class="kicker">Record of Processing</div>
            <h1 class="cover-title">
                data<br>
                <span class="grad">integrity.</span>
            </h1>
            <div class="cover-abstract-card">
                <div class="body">{{ $r['description'] ?? '-' }}</div>
            </div>
        </div>

        <div class="cover-stats">
            <div class="cover-stat">
                <div class="k">Doc</div>
                <div class="v">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="cover-stat">
                <div class="k">Effective</div>
                <div class="v">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div class="cover-stat">
                <div class="k">Division</div>
                <div class="v">{{ $r['division'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — 01 Deskripsi + 02 Pejabat
     ============================================================ --}}
<section class="page">
    <div class="aurora cyan" style="top: -40mm; right: -30mm; width: 100mm; height: 100mm; opacity: 0.7;"></div>
    <div class="grid-overlay"></div>

    <div class="shell">
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">01</span>
                <h2>Deskripsi</h2>
            </div>
            <div class="content-head-right">p.02 / 07</div>
        </div>

        <div class="grid-2">
            <div class="glass accent">
                <div class="k">Nomor</div>
                <div class="v lg">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Tanggal</div>
                <div class="v lg">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Divisi</div>
                <div class="v">{{ $r['division'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Unit Kerja</div>
                <div class="v">{{ $r['unit'] ?? '-' }}</div>
            </div>
            <div class="glass accent">
                <div class="k">Entitas</div>
                <div class="v">{{ $orgName }}</div>
            </div>
            <div class="glass">
                <div class="k">Kategori</div>
                <div class="v">{{ $r['category'] ?? '-' }}</div>
            </div>
        </div>

        <div class="glass" style="margin-bottom: 18pt;">
            <div class="k">Nama Pemrosesan</div>
            <div class="v lg">{{ $r['name'] ?? '-' }}</div>
            <div class="k" style="margin-top: 12pt;">Deskripsi Singkat</div>
            <div class="v body">{{ $r['description'] ?? '-' }}</div>
        </div>

        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num green">02</span>
                <h2>Pejabat &amp; PIC</h2>
            </div>
            <div class="content-head-right">DPO · PIC</div>
        </div>

        <table class="glass-table">
            <thead>
                <tr>
                    <th style="width: 8%;">No</th>
                    <th style="width: 30%;">Pejabat PDP (DPO)</th>
                    <th>Email</th>
                    <th style="width: 22%;">Telepon</th>
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
        <table class="glass-table">
            <thead>
                <tr>
                    <th style="width: 8%;">No</th>
                    <th style="width: 30%;">Process Owner / PIC</th>
                    <th style="width: 28%;">Jabatan</th>
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

    <div class="footer">
        <span>{{ $orgShort }}</span>
        <span class="glow">&lt;0x02&gt;</span>
        <span>p.02 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — 03 Informasi + 04 Sistem + 05 Teknologi
     ============================================================ --}}
<section class="page">
    <div class="aurora green" style="top: -40mm; left: -30mm; width: 100mm; height: 100mm; opacity: 0.6;"></div>
    <div class="aurora cyan" style="bottom: -40mm; right: -30mm; width: 90mm; height: 90mm; opacity: 0.6;"></div>
    <div class="grid-overlay"></div>

    <div class="shell">
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">03</span>
                <h2>Informasi</h2>
            </div>
            <div class="content-head-right">p.03 / 07</div>
        </div>

        <div class="grid-2">
            <div class="glass">
                <div class="k">Tujuan</div>
                <div class="v">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Dasar Hukum</div>
                <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
            <div class="glass full">
                <div class="k">Aktivitas Pemrosesan</div>
                <div class="v body">{{ $r['activity'] ?? '-' }}</div>
            </div>
            <div class="glass green-accent full">
                <div class="k">Kategori Pemrosesan</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['categories'] ?? [] as $cat)
                            <span class="mono-chip green">{{ $cat }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num green">04</span>
                <h2>Sistem Informasi</h2>
            </div>
            <div class="content-head-right">systems[]</div>
        </div>
        <table class="glass-table">
            <thead>
                <tr>
                    <th style="width: 8%;">No</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Penyimpanan</th>
                    <th>Lokasi Penggunaan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @empty
                    <tr><td class="seq">—</td><td colspan="3">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">05</span>
                <h2>Teknologi &amp; Pemrofilan</h2>
            </div>
            <div class="content-head-right">tech.profile</div>
        </div>
        <div class="grid-4">
            <div class="glass">
                <div class="k">AI</div>
                <div class="v">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Auto Decision</div>
                <div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">New Tech</div>
                <div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Profiling</div>
                <div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <span>{{ $orgShort }}</span>
        <span class="glow">&lt;0x03&gt;</span>
        <span>p.03 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — 06 Pengumpulan Data
     ============================================================ --}}
<section class="page">
    <div class="aurora cyan" style="top: -30mm; right: -40mm; width: 120mm; height: 120mm;"></div>
    <div class="aurora green" style="bottom: -30mm; left: -20mm; width: 90mm; height: 90mm; opacity: 0.55;"></div>
    <div class="grid-overlay"></div>

    <div class="shell">
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">06</span>
                <h2>Pengumpulan Data</h2>
            </div>
            <div class="content-head-right">p.04 / 07</div>
        </div>

        <div class="grid-3">
            <div class="glass accent">
                <div class="k">Jumlah Subjek</div>
                <div class="v lg">{{ $r['data_subjects_volume'] ?? '—' }}</div>
            </div>
            <div class="glass" style="grid-column: span 2;">
                <div class="k">Sumber Pengumpulan</div>
                <div class="v">{{ $r['data_source'] ?? '-' }}</div>
            </div>
            <div class="glass green-accent full">
                <div class="k">Subjek Data</div>
                <div class="v">
                    <div class="mono-chips">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <span class="mono-chip green">{{ $s }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="glass" style="margin-bottom: 10pt;">
            <div class="k">Data Pribadi — Umum</div>
            <div class="mono-chips">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="mono-chip">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="glass" style="margin-bottom: 10pt;">
            <div class="k">Data Pribadi — Spesifik</div>
            <div class="mono-chips">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="mono-chip">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="glass" style="border-color: rgba(255, 85, 119, 0.35); background: rgba(255, 85, 119, 0.06);">
            <div class="k" style="color: #ff5577;">Data Pribadi — PII (Sensitif)</div>
            <div class="mono-chips">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="mono-chip danger">{{ $d }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="footer">
        <span>{{ $orgShort }}</span>
        <span class="glow">&lt;0x04&gt;</span>
        <span>p.04 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — 07 Penggunaan + 08 Pihak Ketiga
     ============================================================ --}}
<section class="page">
    <div class="aurora green" style="top: -30mm; right: -30mm; width: 110mm; height: 110mm; opacity: 0.55;"></div>
    <div class="aurora cyan" style="bottom: -40mm; left: -30mm; width: 100mm; height: 100mm;"></div>
    <div class="grid-overlay"></div>

    <div class="shell">
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">07</span>
                <h2>Penggunaan &amp; Penyimpanan</h2>
            </div>
            <div class="content-head-right">p.05 / 07</div>
        </div>

        <div class="grid-2">
            <div class="glass full">
                <div class="k">Kategori Pihak Pemroses</div>
                <div class="mono-chips">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <span class="mono-chip">{{ $role }}</span>
                    @endforeach
                </div>
            </div>
            <div class="glass">
                <div class="k">Pihak Pemroses Utama</div>
                <div class="v">{{ $r['processor_entity'] ?? '-' }}</div>
            </div>
            <div class="glass accent">
                <div class="k">Pihak Ketiga Terlibat</div>
                <div class="v">{{ $r['has_third_party'] ?? '-' }}</div>
            </div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num green">08</span>
                <h2>Pihak Ketiga</h2>
            </div>
            <div class="content-head-right">third_parties[]</div>
        </div>
        <table class="glass-table">
            <thead>
                <tr>
                    <th style="width: 6%;">No</th>
                    <th>Nama Entitas</th>
                    <th>Alamat</th>
                    <th style="width: 16%;">PIC</th>
                    <th style="width: 24%;">Kontak</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ sprintf('%02d', $i + 1) }}</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="font-size: 8.5pt; color: var(--ice-mute); font-family: 'JetBrains Mono', monospace;">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div class="footer">
        <span>{{ $orgShort }}</span>
        <span class="glow">&lt;0x05&gt;</span>
        <span>p.05 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 6 — 09 Pengiriman + 10 Jenis Data Dikirim
     ============================================================ --}}
<section class="page">
    <div class="aurora cyan" style="top: -30mm; left: -30mm; width: 110mm; height: 110mm;"></div>
    <div class="aurora green" style="bottom: -30mm; right: -30mm; width: 100mm; height: 100mm; opacity: 0.55;"></div>
    <div class="grid-overlay"></div>

    <div class="shell">
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">09</span>
                <h2>Pengiriman Data</h2>
            </div>
            <div class="content-head-right">p.06 / 07</div>
        </div>

        <div class="grid-4">
            <div class="glass">
                <div class="k">Internal</div>
                <div class="v">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Eksternal</div>
                <div class="v">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="glass accent">
                <div class="k">Cross-Border</div>
                <div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Negara Tujuan</div>
                <div class="v" style="font-size: 11pt;">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
            </div>
        </div>

        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num green">10</span>
                <h2>Jenis Data Dikirim</h2>
            </div>
            <div class="content-head-right">payload</div>
        </div>

        <div class="glass" style="margin-bottom: 10pt;">
            <div class="k">Umum</div>
            <div class="mono-chips">
                @foreach($r['data_general'] ?? [] as $d)
                    <span class="mono-chip">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="glass" style="margin-bottom: 10pt;">
            <div class="k">Spesifik</div>
            <div class="mono-chips">
                @foreach($r['data_specific'] ?? [] as $d)
                    <span class="mono-chip">{{ $d }}</span>
                @endforeach
            </div>
        </div>
        <div class="glass" style="border-color: rgba(255, 85, 119, 0.35); background: rgba(255, 85, 119, 0.06);">
            <div class="k" style="color: #ff5577;">PII</div>
            <div class="mono-chips">
                @foreach($r['data_pii'] ?? [] as $d)
                    <span class="mono-chip danger">{{ $d }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="footer">
        <span>{{ $orgShort }}</span>
        <span class="glow">&lt;0x06&gt;</span>
        <span>p.06 / 07</span>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — 11 Retensi + 12 Keamanan + 13 Risiko
     ============================================================ --}}
<section class="page">
    <div class="aurora green" style="top: -30mm; left: -30mm; width: 100mm; height: 100mm; opacity: 0.55;"></div>
    <div class="aurora cyan" style="bottom: -30mm; right: -40mm; width: 130mm; height: 130mm;"></div>
    <div class="grid-overlay"></div>

    <div class="shell">
        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">11</span>
                <h2>Retensi Data</h2>
            </div>
            <div class="content-head-right">p.07 / 07</div>
        </div>

        <div class="grid-3">
            <div class="glass" style="grid-column: span 2;">
                <div class="k">Nama Dokumen</div>
                <div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div>
            </div>
            <div class="glass accent">
                <div class="k">Masa Retensi</div>
                <div class="v lg">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Berlaku</div>
                <div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Berakhir</div>
                <div class="v">{{ $r['retention_end_date'] ?? '-' }}</div>
            </div>
            <div class="glass">
                <div class="k">Aktivitas Penghapusan</div>
                <div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div>
            </div>
        </div>

        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num green">12</span>
                <h2>Keamanan Data</h2>
            </div>
            <div class="content-head-right">security</div>
        </div>
        <div class="glass green-accent" style="margin-bottom: 10pt;">
            <div class="k">Kontrol Keamanan</div>
            <div class="mono-chips">
                @foreach($r['controls'] ?? [] as $ctrl)
                    <span class="mono-chip green">{{ $ctrl }}</span>
                @endforeach
            </div>
        </div>
        <div class="glass" style="margin-bottom: 12pt;">
            <div class="k">Riwayat Insiden</div>
            <div class="v">{{ $r['has_past_incident'] ?? '-' }}</div>
        </div>

        <div class="content-head">
            <div class="content-head-left">
                <span class="neon-num cyan">13</span>
                <h2>Klasifikasi Risiko</h2>
            </div>
            <div class="content-head-right">risk.level</div>
        </div>
        <div class="grid-2">
            <div class="glass accent">
                <div class="k">Level Risiko</div>
                <div class="v" style="margin-top: 10pt;"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="glass">
                <div class="k">Justifikasi</div>
                <div class="v body">{{ $r['risk_justification'] ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <span>{{ $orgShort }}</span>
        <span class="glow">&lt;0x07 · END&gt;</span>
        <span>p.07 / 07</span>
    </div>
</section>

</body>
</html>
