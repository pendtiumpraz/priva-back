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
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #000;
        }

        /* =========================================================
           Palette Brutalist Mono
           ========================================================= */
        :root {
            --gray: #e8e8e8;
            --black: #000;
            --white: #fff;
            --yellow: #ffeb3b;
        }

        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            background: var(--gray);
            overflow: hidden;
            page-break-after: always;
            padding: 12mm;
        }
        .page:last-child { page-break-after: auto; }

        /* Brutalist block — 6px black border, no border radius */
        .bblock {
            border: 6px solid var(--black);
            background: var(--white);
        }
        .bblock.invert { background: var(--black); color: var(--white); }
        .bblock.yellow { background: var(--yellow); }
        .bblock + .bblock { margin-top: 10pt; }

        /* =========================================================
           COVER
           ========================================================= */
        .top-bar {
            padding: 14pt 18pt;
            display: flex;
            justify-content: space-between;
            font-size: 11pt;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.1em;
        }
        .headline-block {
            padding: 28pt 22pt;
        }
        .headline-eyebrow {
            font-size: 11pt;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #aaa;
        }
        .headline {
            font-family: 'Inter', sans-serif;
            font-size: 84pt;
            font-weight: 900;
            line-height: 0.9;
            letter-spacing: -0.04em;
            margin: 10pt 0 0;
            text-transform: uppercase;
        }
        .pair-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 10pt; margin-top: 10pt; }
        .pair-block { padding: 16pt 18pt; }
        .pair-label {
            font-size: 10pt;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .pair-name {
            margin-top: 6pt;
            font-family: 'Inter', sans-serif;
            font-size: 14pt;
            font-weight: 700;
        }
        .pair-org { margin-top: 2pt; font-size: 10pt; }
        .pair-ref {
            margin-top: 6pt;
            font-family: 'Inter', sans-serif;
            font-size: 18pt;
            font-weight: 800;
        }

        .div-unit {
            padding: 12pt 18pt;
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            text-transform: uppercase;
            font-weight: 600;
        }
        .note-block {
            padding: 14pt 18pt;
            font-size: 10pt;
            line-height: 1.55;
        }
        .note-block .note-head {
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 4pt;
        }
        .footer-tag {
            margin-top: 10pt;
            padding: 10pt 18pt;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 600;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .sec-head {
            padding: 16pt 18pt;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sec-head .h-title {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            font-size: 14pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .sec-head .h-meta {
            font-size: 10pt;
        }

        .kv-table {
            border: 6px solid var(--black);
            border-top: none;
            background: var(--white);
        }
        .kv-row {
            display: grid;
            grid-template-columns: 180pt 1fr;
            border-top: 3px solid var(--black);
        }
        .kv-row:first-child { border-top: none; }
        .kv-row .k {
            padding: 9pt 12pt;
            background: var(--yellow);
            border-right: 3px solid var(--black);
            font-family: 'JetBrains Mono', monospace;
            font-size: 9.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .kv-row .v {
            padding: 9pt 12pt;
            font-family: 'Inter', sans-serif;
            font-size: 11pt;
            font-weight: 500;
            word-break: break-word;
        }
        .kv-row .v.body { font-size: 10pt; line-height: 1.55; font-weight: 400; }
        .kv-row .v.mono { font-family: 'JetBrains Mono', monospace; font-size: 10pt; }

        /* Tag chips */
        .tag-row {
            display: flex;
            flex-wrap: wrap;
            gap: 5pt;
            margin: 6pt 0;
        }
        .tag {
            padding: 4pt 9pt;
            background: var(--black);
            color: var(--white);
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
        }
        .tag.yellow { background: var(--yellow); color: var(--black); }
        .tag.outline { background: transparent; color: var(--black); border: 2px solid var(--black); }
        .tag.pii { background: #c00; color: var(--white); }

        /* Bulleted lists */
        ul.mono-list { list-style: none; margin: 4pt 0 0; padding: 0; }
        ul.mono-list li {
            padding: 3pt 0;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10pt;
        }
        ul.mono-list li::before {
            content: "> ";
            font-weight: 700;
        }

        /* Data tables */
        table.btable {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            background: var(--white);
        }
        table.btable th {
            background: var(--black);
            color: var(--yellow);
            padding: 8pt 10pt;
            text-align: left;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-right: 3px solid var(--gray);
        }
        table.btable th:last-child { border-right: none; }
        table.btable td {
            padding: 9pt 10pt;
            border-top: 3px solid var(--black);
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            vertical-align: top;
            line-height: 1.45;
            border-right: 3px solid var(--black);
        }
        table.btable td:last-child { border-right: none; }
        table.btable td.seq {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            background: var(--yellow);
            text-align: center;
        }

        /* Q/A note block grid */
        .qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; }
        .qa-card {
            border: 6px solid var(--black);
            padding: 12pt 14pt;
            background: var(--white);
        }
        .qa-card .q {
            font-family: 'JetBrains Mono', monospace;
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .qa-card .a {
            margin-top: 5pt;
            font-family: 'Inter', sans-serif;
            font-size: 13pt;
            font-weight: 700;
        }

        .risk-block {
            display: flex;
            align-items: center;
            gap: 14pt;
            padding: 12pt 14pt;
        }
        .risk-badge {
            font-family: 'JetBrains Mono', monospace;
            padding: 8pt 18pt;
            font-size: 13pt;
            font-weight: 800;
            letter-spacing: 0.12em;
            border: 6px solid var(--black);
            text-transform: uppercase;
        }
        .risk-HIGH { background: #c00; color: var(--white); }
        .risk-MEDIUM { background: var(--yellow); color: var(--black); }
        .risk-LOW { background: var(--white); color: var(--black); }
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
    <div class="bblock top-bar">
        <span>[ROPA-EXPORT-v1]</span>
        <span>{{ $r['date'] ?? '-' }}</span>
    </div>

    <div class="bblock invert headline-block">
        <div class="headline-eyebrow">// HEADLINE</div>
        <h1 class="headline">
            RECORD OF<br>
            PROCESSING<br>
            ACTIVITIES
        </h1>
    </div>

    <div class="pair-grid">
        <div class="bblock pair-block">
            <div class="pair-label">FOR:</div>
            <div class="pair-name">{{ $r['name'] ?? '-' }}</div>
            <div class="pair-org">{{ $orgName }}</div>
        </div>
        <div class="bblock yellow pair-block">
            <div class="pair-label">REF:</div>
            <div class="pair-ref">{{ $r['number'] ?? '-' }}</div>
        </div>
    </div>

    <div class="bblock div-unit">
        <span>DIV: {{ $r['division'] ?? '-' }}</span>
        <span>UNIT: {{ $r['unit'] ?? '-' }}</span>
    </div>

    <div class="bblock note-block">
        <div class="note-head">&gt;&gt; NOTE</div>
        {{ $r['description'] ?? '-' }}
    </div>

    <div class="bblock footer-tag">
        <span>CATEGORY: {{ $r['category'] ?? '-' }}</span>
        <span>PAGE 01 / {{ $totalPages }}</span>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — SEC.01 DESKRIPSI + SEC.02 PEJABAT PDP
     ============================================================ --}}
<section class="page">
    <div class="bblock invert sec-head">
        <span class="h-title">&gt;&gt; SEC.01 / DESKRIPSI</span>
        <span class="h-meta">p.02 / {{ $totalPages }}</span>
    </div>
    <div class="kv-table">
        <div class="kv-row"><div class="k">NO_ROPA</div><div class="v mono">{{ $r['number'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">NAME</div><div class="v">{{ $r['name'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">DIVISI</div><div class="v">{{ $r['division'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">UNIT</div><div class="v">{{ $r['unit'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">ENTITAS</div><div class="v">{{ $orgName }}</div></div>
        <div class="kv-row"><div class="k">KATEGORI</div><div class="v">{{ $r['category'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">DESKRIPSI</div><div class="v body">{{ $r['description'] ?? '-' }}</div></div>
    </div>

    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.02 / DPO &amp; PIC</span>
        <span class="h-meta">PEJABAT PDP</span>
    </div>
    <div class="bblock" style="border-top: none; padding: 0;">
        <table class="btable">
            <thead>
                <tr>
                    <th style="width: 6%;">#</th>
                    <th style="width: 26%;">DPO</th>
                    <th>EMAIL</th>
                    <th style="width: 22%;">PHONE</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">01</td>
                    <td><b>{{ $r['dpo']['name'] ?? '-' }}</b></td>
                    <td>{{ $r['dpo']['email'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['phone'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
        <table class="btable" style="border-top: 6px solid var(--black);">
            <thead>
                <tr>
                    <th style="width: 6%;">#</th>
                    <th style="width: 26%;">PIC</th>
                    <th>ROLE</th>
                    <th>EMAIL</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">01</td>
                    <td><b>{{ $r['pic']['name'] ?? '-' }}</b></td>
                    <td>{{ $r['pic']['role'] ?? '-' }}</td>
                    <td>{{ $r['pic']['email'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

{{-- ============================================================
     PAGE 3 — SEC.03 INFORMASI + SEC.04 SISTEM + SEC.05 TEKNOLOGI
     ============================================================ --}}
<section class="page">
    <div class="bblock invert sec-head">
        <span class="h-title">&gt;&gt; SEC.03 / INFORMASI</span>
        <span class="h-meta">p.03 / {{ $totalPages }}</span>
    </div>
    <div class="kv-table">
        <div class="kv-row"><div class="k">TUJUAN</div><div class="v">{{ $r['purpose'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">AKTIVITAS</div><div class="v">{{ $r['activity'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">DASAR_HUKUM</div><div class="v">{{ $r['legal_basis'] ?? '-' }}</div></div>
        <div class="kv-row">
            <div class="k">KATEGORI</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['categories'] ?? [] as $cat)
                        <span class="tag">[{{ $cat }}]</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.04 / SISTEM</span>
        <span class="h-meta">INFORMASI TERKAIT</span>
    </div>
    <div class="bblock" style="border-top: none; padding: 0;">
        <table class="btable">
            <thead>
                <tr>
                    <th style="width: 6%;">#</th>
                    <th>NAMA SISTEM</th>
                    <th>LOKASI SIMPAN</th>
                    <th>LOKASI PAKAI</th>
                </tr>
            </thead>
            <tbody>
                @forelse($r['systems'] ?? [] as $i => $sys)
                    <tr>
                        <td class="seq">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</td>
                        <td><b>{{ $sys['name'] ?? '-' }}</b></td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;">[null]</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.05 / TEKNOLOGI</span>
        <span class="h-meta">AI · ADM · NEW_TECH</span>
    </div>
    <div style="margin-top: 10pt;">
        <div class="qa-grid">
            <div class="qa-card"><div class="q">AI_HELPER:</div><div class="a">{{ $r['uses_ai'] ?? '-' }}</div></div>
            <div class="qa-card"><div class="q">AUTO_DECISION:</div><div class="a">{{ $r['uses_automated_decision'] ?? '-' }}</div></div>
            <div class="qa-card"><div class="q">NEW_TECH:</div><div class="a">{{ $r['uses_new_tech'] ?? '-' }}</div></div>
            <div class="qa-card"><div class="q">PROFILING_PURPOSE:</div><div class="a">{{ $r['profiling_purpose'] ?? '-' }}</div></div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 4 — SEC.06 PENGUMPULAN DATA
     ============================================================ --}}
<section class="page">
    <div class="bblock invert sec-head">
        <span class="h-title">&gt;&gt; SEC.06 / PENGUMPULAN_DATA</span>
        <span class="h-meta">p.04 / {{ $totalPages }}</span>
    </div>
    <div class="kv-table">
        <div class="kv-row">
            <div class="k">SUBJEK</div>
            <div class="v">
                <ul class="mono-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="kv-row"><div class="k">VOLUME</div><div class="v mono">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">SOURCE</div><div class="v">{{ $r['data_source'] ?? '-' }}</div></div>
        <div class="kv-row">
            <div class="k">DATA / UMUM</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="tag outline">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="kv-row">
            <div class="k">DATA / SPESIFIK</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <span class="tag yellow">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="kv-row">
            <div class="k">DATA / PII</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="tag pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 5 — SEC.07 PENGGUNAAN + SEC.08 PIHAK KETIGA
     ============================================================ --}}
<section class="page">
    <div class="bblock invert sec-head">
        <span class="h-title">&gt;&gt; SEC.07 / PENGGUNAAN</span>
        <span class="h-meta">p.05 / {{ $totalPages }}</span>
    </div>
    <div class="kv-table">
        <div class="kv-row">
            <div class="k">PROC_ROLE</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <span class="tag">{{ $role }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="kv-row"><div class="k">PROC_ENTITY</div><div class="v">{{ $r['processor_entity'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">HAS_3P</div><div class="v mono">{{ $r['has_third_party'] ?? '-' }}</div></div>
    </div>

    @if(!empty($r['third_parties']))
    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.08 / PIHAK_KETIGA</span>
        <span class="h-meta">{{ count($r['third_parties']) }} ENTRIES</span>
    </div>
    <div class="bblock" style="border-top: none; padding: 0;">
        <table class="btable">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th>ENTITAS</th>
                    <th>ALAMAT</th>
                    <th>PIC</th>
                    <th>CONTACT</th>
                </tr>
            </thead>
            <tbody>
                @foreach($r['third_parties'] as $i => $tp)
                    <tr>
                        <td class="seq">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</td>
                        <td><b>{{ $tp['name'] ?? '-' }}</b></td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td style="font-family:'JetBrains Mono', monospace; font-size: 9pt;">{{ $tp['pic_email'] ?? '-' }}<br>{{ $tp['pic_phone'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</section>

{{-- ============================================================
     PAGE 6 — SEC.09 PENGIRIMAN + SEC.10 JENIS DATA DIKIRIM
     ============================================================ --}}
<section class="page">
    <div class="bblock invert sec-head">
        <span class="h-title">&gt;&gt; SEC.09 / PENGIRIMAN</span>
        <span class="h-meta">p.06 / {{ $totalPages }}</span>
    </div>
    <div style="margin-top: 10pt;">
        <div class="qa-grid">
            <div class="qa-card"><div class="q">RECIPIENT_INT:</div><div class="a">{{ $r['recipients_internal'] ?? '-' }}</div></div>
            <div class="qa-card"><div class="q">RECIPIENT_EXT:</div><div class="a">{{ $r['recipients_external'] ?? '-' }}</div></div>
            <div class="qa-card"><div class="q">CROSS_BORDER:</div><div class="a">{{ $r['cross_border_transfer'] ?? '-' }}</div></div>
            <div class="qa-card"><div class="q">DESTINATIONS:</div><div class="a">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div></div>
        </div>
    </div>

    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.10 / DATA_DIKIRIM</span>
        <span class="h-meta">CATEGORIES</span>
    </div>
    <div class="kv-table">
        <div class="kv-row">
            <div class="k">UMUM</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['data_general'] ?? [] as $d)
                        <span class="tag outline">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="kv-row">
            <div class="k">SPESIFIK</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['data_specific'] ?? [] as $d)
                        <span class="tag yellow">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="kv-row">
            <div class="k">PII</div>
            <div class="v">
                <div class="tag-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="tag pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 7 — SEC.11 RETENSI + SEC.12 KEAMANAN + SEC.13 RISIKO
     ============================================================ --}}
<section class="page">
    <div class="bblock invert sec-head">
        <span class="h-title">&gt;&gt; SEC.11 / RETENSI</span>
        <span class="h-meta">p.07 / {{ $totalPages }}</span>
    </div>
    <div class="kv-table">
        <div class="kv-row"><div class="k">DOC_NAME</div><div class="v">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">PERIOD</div><div class="v mono">{{ $r['retention_period'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">EFFECTIVE</div><div class="v mono">{{ $r['retention_effective_date'] ?? '-' }} &mdash;&gt; {{ $r['retention_end_date'] ?? '-' }}</div></div>
        <div class="kv-row"><div class="k">HAS_DELETION</div><div class="v mono">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
    </div>

    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.12 / KEAMANAN</span>
        <span class="h-meta">CONTROLS</span>
    </div>
    <div class="kv-table">
        <div class="kv-row">
            <div class="k">CONTROLS</div>
            <div class="v">
                <ul class="mono-list">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <li>{{ $ctrl }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="kv-row"><div class="k">PAST_INCIDENT</div><div class="v mono">{{ $r['has_past_incident'] ?? '-' }}</div></div>
    </div>

    <div class="bblock invert sec-head" style="margin-top: 10pt;">
        <span class="h-title">&gt;&gt; SEC.13 / RISIKO</span>
        <span class="h-meta">CLASSIFICATION</span>
    </div>
    <div class="bblock" style="border-top: none;">
        <div class="risk-block">
            <span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">[{{ $r['risk_level'] ?? 'MEDIUM' }}]</span>
            <div style="font-family: 'Inter', sans-serif; font-size: 11pt; line-height: 1.55; flex: 1;">{{ $r['risk_justification'] ?? '-' }}</div>
        </div>
    </div>

    <div class="bblock footer-tag" style="margin-top: 10pt;">
        <span>END_OF_DOC // {{ $orgName }}</span>
        <span>EOF</span>
    </div>
</section>

</body>
</html>
