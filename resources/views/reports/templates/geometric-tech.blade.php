<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --bg: #0a0f1f;
            --bg-2: #0d1428;
            --ink: #e6edff;
            --muted: #7a8bbf;
            --blue: #4d7bff;
            --blue-2: #2c4cff;
            --rule: #1a233f;
            --rule-2: #2a3a6e;
            --panel: rgba(77,123,255,.06);
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
            background: var(--bg);
            color: var(--ink);
        }
        .page:last-child { page-break-after: auto; }

        /* =========================================================
           COVER
           ========================================================= */
        .cover { font-family: 'JetBrains Mono', 'IBM Plex Mono', monospace; }
        .grid-bg {
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(110,160,255,.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(110,160,255,.08) 1px, transparent 1px);
            background-size: 40px 40px;
            z-index: 1;
        }
        .sq-rot {
            position: absolute;
            top: 80px; right: 0;
            width: 280px; height: 280px;
            border: 1px solid var(--blue);
            transform: rotate(15deg);
            z-index: 1;
        }
        .sq-rot-2 {
            position: absolute;
            top: 120px; right: 40px;
            width: 200px; height: 200px;
            border: 1px solid var(--blue);
            opacity: .5;
            z-index: 1;
        }
        .sq-fill {
            position: absolute;
            top: 160px; right: 80px;
            width: 120px; height: 120px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-2) 100%);
            z-index: 1;
        }

        .cover-inner {
            position: relative;
            z-index: 3;
            padding: 56px 56px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cover-top {
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            color: var(--muted);
        }
        .terminal-line {
            font-size: 10pt;
            color: var(--blue);
            letter-spacing: .2em;
            margin-bottom: 16pt;
        }
        .cover-title {
            font-family: 'Inter', sans-serif;
            font-size: 80pt;
            font-weight: 800;
            line-height: .95;
            letter-spacing: -.04em;
            margin: 0;
            color: var(--ink);
        }
        .cover-title .slash { color: var(--blue); }

        .payload {
            margin-top: 24pt;
            padding: 14pt 16pt;
            border: 1px solid var(--rule-2);
            background: var(--panel);
            max-width: 540pt;
        }
        .payload-label { font-size: 10pt; color: var(--muted); margin-bottom: 6pt; }
        .payload-body {
            font-family: 'Inter', sans-serif;
            font-size: 13pt;
            line-height: 1.55;
        }
        .payload-body .k { color: var(--blue); font-family: 'JetBrains Mono', monospace; }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border: 1px solid var(--rule-2);
        }
        .meta-grid > div {
            padding: 14pt 16pt;
            border-right: 1px solid var(--rule-2);
        }
        .meta-grid > div:last-child { border-right: none; }
        .meta-k {
            font-size: 9pt;
            color: var(--blue);
            letter-spacing: .2em;
            font-family: 'JetBrains Mono', monospace;
        }
        .meta-v {
            margin-top: 6pt;
            font-size: 12pt;
            color: var(--ink);
            font-family: 'Inter', sans-serif;
        }

        /* =========================================================
           CONTENT
           ========================================================= */
        .head-band {
            padding: 28pt 22mm 14pt 22mm;
            border-bottom: 1px solid var(--rule-2);
        }
        .head-meta {
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9.5pt;
            color: var(--muted);
        }
        .head-title {
            margin: 10pt 0 0;
            font-size: 26pt;
            font-weight: 700;
            letter-spacing: -.02em;
            color: var(--ink);
        }
        .head-title .sec {
            color: var(--blue);
            font-family: 'JetBrains Mono', monospace;
            font-size: 14pt;
            margin-right: 10pt;
            font-weight: 500;
        }

        .body { padding: 18pt 22mm 60pt 22mm; }

        .section { margin-bottom: 22pt; page-break-inside: avoid; }
        .section + .section { border-top: 1px solid var(--rule); padding-top: 18pt; }

        .sec-bar {
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            color: var(--muted);
            margin-bottom: 10pt;
        }
        .sec-title {
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: -.02em;
            margin: 0 0 14pt;
            color: var(--ink);
        }
        .sec-title .sec {
            color: var(--blue);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13pt;
            margin-right: 10pt;
            font-weight: 500;
        }

        .row {
            display: grid;
            grid-template-columns: 200pt 1fr;
            padding: 9pt 14pt;
            border-bottom: 1px solid var(--rule);
            font-family: 'JetBrains Mono', monospace;
            font-size: 10.5pt;
        }
        .row:last-child { border-bottom: none; }
        .row .k { color: var(--blue); }
        .row .k::after { content: ":"; color: var(--muted); margin-left: 1pt; }
        .row .v {
            color: var(--ink);
            font-family: 'Inter', sans-serif;
            font-size: 11pt;
            line-height: 1.55;
        }

        .pill-row { display: flex; flex-wrap: wrap; gap: 6pt; }
        .pill {
            padding: 3pt 9pt;
            border: 1px solid var(--rule-2);
            border-radius: 3pt;
            font-size: 10pt;
            font-family: 'JetBrains Mono', monospace;
            color: #a3b3e6;
        }
        .pill.pii {
            border-color: rgba(255,90,110,.5);
            color: #ff8c9c;
            background: rgba(255,90,110,.06);
        }

        table.tt {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-top: 6pt;
        }
        table.tt th {
            background: rgba(77,123,255,.10);
            color: var(--blue);
            font-family: 'JetBrains Mono', monospace;
            text-align: left;
            padding: 7pt 10pt;
            font-size: 9pt;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 500;
            border-bottom: 1px solid var(--rule-2);
        }
        table.tt td {
            padding: 7pt 10pt;
            border-bottom: 1px solid var(--rule);
            color: var(--ink);
            vertical-align: top;
            line-height: 1.45;
        }
        table.tt .seq { color: var(--blue); font-family: 'JetBrains Mono', monospace; width: 24pt; }

        .qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; }
        .qa-card {
            padding: 10pt 12pt;
            border: 1px solid var(--rule-2);
            background: var(--panel);
            font-family: 'JetBrains Mono', monospace;
        }
        .qa-card .q { font-size: 9pt; color: var(--muted); }
        .qa-card .q::before { content: "// "; color: var(--blue); }
        .qa-card .a {
            margin-top: 6pt;
            font-size: 12pt;
            color: var(--ink);
            font-family: 'Inter', sans-serif;
            font-weight: 500;
        }

        ul.tick { margin: 0; padding: 0; list-style: none; font-size: 11pt; }
        ul.tick li {
            padding: 2pt 0 2pt 14pt;
            position: relative;
            color: var(--ink);
        }
        ul.tick li::before {
            content: "›";
            color: var(--blue);
            position: absolute;
            left: 0;
            font-family: 'JetBrains Mono', monospace;
        }

        .risk-badge {
            display: inline-block;
            padding: 5pt 14pt;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            letter-spacing: .2em;
            text-transform: uppercase;
            font-weight: 700;
            border-radius: 3pt;
        }
        .risk-HIGH { background: rgba(255,80,90,.15); color: #ff6b76; border: 1px solid rgba(255,80,90,.55); }
        .risk-MEDIUM { background: rgba(255,180,60,.12); color: #ffba3c; border: 1px solid rgba(255,180,60,.5); }
        .risk-LOW { background: rgba(77,255,150,.12); color: #4dffa0; border: 1px solid rgba(77,255,150,.5); }

        .page-footer {
            position: absolute;
            bottom: 22pt;
            left: 22mm; right: 22mm;
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            color: var(--blue);
        }
        .page-footer .left::before { content: "$ "; color: var(--muted); }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;
    $pad = function($n){ return str_pad((string)$n, 2, '0', STR_PAD_LEFT); };
@endphp

{{-- PAGE 1 — COVER --}}
<section class="page cover">
    <div class="grid-bg"></div>
    <div class="sq-rot"></div>
    <div class="sq-rot-2"></div>
    <div class="sq-fill"></div>

    <div class="cover-inner">
        <div class="cover-top">
            <span>// CLASSIFIED · L2-CONFIDENTIAL</span>
            <span>{{ $r['number'] ?? '-' }}</span>
        </div>
        <div>
            <div class="terminal-line">$ ropa --export --format=pdf</div>
            <h1 class="cover-title">
                ROPA<br>
                <span class="slash">/EXPORT</span>
            </h1>
            <div class="payload">
                <div class="payload-label">// payload</div>
                <div class="payload-body">
                    <span class="k">name:</span> "{{ $r['name'] ?? '-' }}"<br>
                    <span class="k">org:</span> "{{ $orgName }}"
                </div>
            </div>
        </div>
        <div class="meta-grid">
            <div>
                <div class="meta-k">[NUM]</div>
                <div class="meta-v">{{ $r['number'] ?? '-' }}</div>
            </div>
            <div>
                <div class="meta-k">[DIV]</div>
                <div class="meta-v">{{ \Illuminate\Support\Str::limit($r['division'] ?? '-', 12, '') }}</div>
            </div>
            <div>
                <div class="meta-k">[DATE]</div>
                <div class="meta-v">{{ $r['date'] ?? '-' }}</div>
            </div>
            <div>
                <div class="meta-k">[STATUS]</div>
                <div class="meta-v">ACTIVE</div>
            </div>
        </div>
    </div>
</section>

{{-- PAGE 2 — Sec I + II --}}
<section class="page">
    <div class="head-band">
        <div class="head-meta">
            <span>// section/01</span>
            <span>{{ $r['number'] ?? '-' }} · p.{{ $pad(2) }}</span>
        </div>
        <h2 class="head-title"><span class="sec">§01</span>Deskripsi Pemrosesan</h2>
    </div>
    <div class="body">
        <div class="section">
            <div class="row"><div class="k">ropa_number</div><div class="v">{{ $r['number'] ?? '-' }}</div></div>
            <div class="row"><div class="k">name</div><div class="v">{{ $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="k">division</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
            <div class="row"><div class="k">unit</div><div class="v">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="row"><div class="k">entity</div><div class="v">{{ $orgName }}</div></div>
            <div class="row"><div class="k">category</div><div class="v">{{ $r['category'] ?? '-' }}</div></div>
            <div class="row"><div class="k">description</div><div class="v">{{ $r['description'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/02</span></div>
            <h2 class="sec-title"><span class="sec">§02</span>Data Protection Officer &amp; PIC</h2>
            <table class="tt">
                <thead>
                    <tr>
                        <th style="width:4%;">No</th>
                        <th>Pejabat PDP (DPO)</th>
                        <th>Email</th>
                        <th>Telepon</th>
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
            <table class="tt" style="margin-top: 12pt;">
                <thead>
                    <tr>
                        <th style="width:4%;">No</th>
                        <th>Process Owner / PIC</th>
                        <th>Jabatan</th>
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
    </div>
    <div class="page-footer">
        <span class="left">{{ $orgName }}</span>
        <span>EOF · {{ $pad(2) }}/{{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 3 — Sec III + IV + V --}}
<section class="page">
    <div class="head-band">
        <div class="head-meta">
            <span>// section/03</span>
            <span>{{ $r['number'] ?? '-' }} · p.{{ $pad(3) }}</span>
        </div>
        <h2 class="head-title"><span class="sec">§03</span>Informasi Pemrosesan</h2>
    </div>
    <div class="body">
        <div class="section">
            <div class="row"><div class="k">purpose</div><div class="v">{{ $r['purpose'] ?? '-' }}</div></div>
            <div class="row"><div class="k">activity</div><div class="v">{{ $r['activity'] ?? '-' }}</div></div>
            <div class="row"><div class="k">legal_basis</div><div class="v">{{ $r['legal_basis'] ?? '-' }}</div></div>
            <div class="row">
                <div class="k">categories</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['categories'] ?? [] as $c)
                            <span class="pill">{{ $c }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/04</span></div>
            <h2 class="sec-title"><span class="sec">§04</span>Sistem Informasi Terkait</h2>
            <table class="tt">
                <thead>
                    <tr>
                        <th style="width:4%;">No</th>
                        <th>Nama Sistem</th>
                        <th>Lokasi Penyimpanan</th>
                        <th>Lokasi Penggunaan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['systems'] ?? [] as $i => $sys)
                        <tr>
                            <td class="seq">{{ $pad($i + 1) }}</td>
                            <td>{{ $sys['name'] ?? '-' }}</td>
                            <td>{{ $sys['loc'] ?? '-' }}</td>
                            <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/05</span></div>
            <h2 class="sec-title"><span class="sec">§05</span>Teknologi &amp; Pemrofilan</h2>
            <div class="qa-grid">
                <div class="qa-card">
                    <div class="q">uses_ai</div>
                    <div class="a">{{ $r['uses_ai'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="q">uses_automated_decision</div>
                    <div class="a">{{ $r['uses_automated_decision'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="q">uses_new_tech</div>
                    <div class="a">{{ $r['uses_new_tech'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="q">profiling_purpose</div>
                    <div class="a">{{ $r['profiling_purpose'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">
        <span class="left">{{ $orgName }}</span>
        <span>EOF · {{ $pad(3) }}/{{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 4 — Sec VI --}}
<section class="page">
    <div class="head-band">
        <div class="head-meta">
            <span>// section/06</span>
            <span>{{ $r['number'] ?? '-' }} · p.{{ $pad(4) }}</span>
        </div>
        <h2 class="head-title"><span class="sec">§06</span>Pengumpulan Data</h2>
    </div>
    <div class="body">
        <div class="section">
            <div class="row">
                <div class="k">data_subjects</div>
                <div class="v">
                    <ul class="tick">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <li>{{ $s }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="k">volume</div><div class="v">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
            <div class="row"><div class="k">source</div><div class="v">{{ $r['data_source'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/06.1 — data_personal</span></div>
            <h2 class="sec-title" style="font-size: 18pt;"><span class="sec">»</span>Data Pribadi</h2>
            <div class="row" style="border-bottom: none;">
                <div class="k">general</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="k">specific</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="k">pii</div>
                <div class="v">
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
        <span class="left">{{ $orgName }}</span>
        <span>EOF · {{ $pad(4) }}/{{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 5 — Sec VII + VIII --}}
<section class="page">
    <div class="head-band">
        <div class="head-meta">
            <span>// section/07</span>
            <span>{{ $r['number'] ?? '-' }} · p.{{ $pad(5) }}</span>
        </div>
        <h2 class="head-title"><span class="sec">§07</span>Penggunaan &amp; Penyimpanan Data</h2>
    </div>
    <div class="body">
        <div class="section">
            <div class="row">
                <div class="k">processor_role</div>
                <div class="v">
                    <ul class="tick">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="k">processor_entity</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="row"><div class="k">has_third_party</div><div class="v">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>

        @if(!empty($r['third_parties']))
        <div class="section">
            <div class="sec-bar"><span>// section/08</span></div>
            <h2 class="sec-title"><span class="sec">§08</span>Pihak Ketiga</h2>
            <table class="tt">
                <thead>
                    <tr>
                        <th style="width:4%;">No</th>
                        <th>Nama Entitas</th>
                        <th>Alamat</th>
                        <th>PIC</th>
                        <th>Kontak</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r['third_parties'] as $i => $tp)
                        <tr>
                            <td class="seq">{{ $pad($i + 1) }}</td>
                            <td>{{ $tp['name'] ?? '-' }}</td>
                            <td>{{ $tp['address'] ?? '-' }}</td>
                            <td>{{ $tp['pic_name'] ?? '-' }}</td>
                            <td>
                                {{ $tp['pic_email'] ?? '-' }}<br>
                                <span style="color: var(--muted); font-size: 8.5pt;">{{ $tp['pic_phone'] ?? '' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="page-footer">
        <span class="left">{{ $orgName }}</span>
        <span>EOF · {{ $pad(5) }}/{{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 6 — Sec IX + X --}}
<section class="page">
    <div class="head-band">
        <div class="head-meta">
            <span>// section/09</span>
            <span>{{ $r['number'] ?? '-' }} · p.{{ $pad(6) }}</span>
        </div>
        <h2 class="head-title"><span class="sec">§09</span>Pengiriman Data</h2>
    </div>
    <div class="body">
        <div class="section">
            <div class="qa-grid">
                <div class="qa-card">
                    <div class="q">recipients_internal</div>
                    <div class="a">{{ $r['recipients_internal'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="q">recipients_external</div>
                    <div class="a">{{ $r['recipients_external'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="q">cross_border_transfer</div>
                    <div class="a">{{ $r['cross_border_transfer'] ?? '-' }}</div>
                </div>
                <div class="qa-card">
                    <div class="q">destinations</div>
                    <div class="a">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/10</span></div>
            <h2 class="sec-title"><span class="sec">§10</span>Jenis Data yang Dikirim</h2>
            <div class="row" style="border-bottom: none;">
                <div class="k">general</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="k">specific</div>
                <div class="v">
                    <div class="pill-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="pill">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row" style="border-bottom: none;">
                <div class="k">pii</div>
                <div class="v">
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
        <span class="left">{{ $orgName }}</span>
        <span>EOF · {{ $pad(6) }}/{{ $pad($totalPages) }}</span>
    </div>
</section>

{{-- PAGE 7 — Sec XI + XII + XIII --}}
<section class="page">
    <div class="head-band">
        <div class="head-meta">
            <span>// section/11</span>
            <span>{{ $r['number'] ?? '-' }} · p.{{ $pad(7) }}</span>
        </div>
        <h2 class="head-title"><span class="sec">§11</span>Retensi Data</h2>
    </div>
    <div class="body">
        <div class="section">
            <div class="row"><div class="k">document</div><div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="k">period</div><div class="v">{{ $r['retention_period'] ?? $r['retention'] ?? '-' }}</div></div>
            <div class="row"><div class="k">effective</div><div class="v">{{ $r['retention_effective_date'] ?? $r['date'] ?? '-' }} → {{ $r['retention_end_date'] ?? '-' }}</div></div>
            <div class="row"><div class="k">deletion_activity</div><div class="v">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/12</span></div>
            <h2 class="sec-title"><span class="sec">§12</span>Keamanan Data</h2>
            <div class="row" style="border-bottom: none;">
                <div class="k">controls</div>
                <div class="v">
                    <ul class="tick">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="k">past_incident</div><div class="v">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>

        <div class="section">
            <div class="sec-bar"><span>// section/13</span></div>
            <h2 class="sec-title"><span class="sec">§13</span>Klasifikasi Risiko</h2>
            <div class="row">
                <div class="k">risk_level</div>
                <div class="v"><span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span></div>
            </div>
            <div class="row"><div class="k">justification</div><div class="v">{{ $r['risk_justification'] ?? '-' }}</div></div>
        </div>
    </div>
    <div class="page-footer">
        <span class="left">{{ $orgName }}</span>
        <span>EOF · {{ $pad(7) }}/{{ $pad($totalPages) }}</span>
    </div>
</section>

</body>
</html>
