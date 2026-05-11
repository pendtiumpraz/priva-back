<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Cormorant Garamond', serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* =========================================================
           Palette Art Deco Gold
           ========================================================= */
        :root {
            --black: #0c0c0c;
            --gold: #d4af37;
            --gold-soft: rgba(212, 175, 55, 0.4);
            --gold-faint: rgba(212, 175, 55, 0.3);
            --cream: #faf4e0;
            --ivory: #e8d9a7;
            --dark: #1a1a1a;
            --muted-gold: #8a6f1d;
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
           COVER — black + gold, fans, diamond ornaments
           ========================================================= */
        .cover {
            background: var(--black);
            color: var(--ivory);
        }
        .frame-outer { position: absolute; inset: 11mm; border: 1.6pt solid var(--gold); z-index: 1; }
        .frame-inner { position: absolute; inset: 14mm; border: 0.6pt solid var(--gold-soft); z-index: 1; }

        /* 4 corner fans */
        .corner-fan {
            position: absolute;
            width: 36mm; height: 36mm;
            z-index: 2;
        }
        .corner-fan.tl { top: 14mm; left: 14mm; }
        .corner-fan.tr { top: 14mm; right: 14mm; transform: scaleX(-1); }
        .corner-fan.bl { bottom: 14mm; left: 14mm; transform: scaleY(-1); }
        .corner-fan.br { bottom: 14mm; right: 14mm; transform: scale(-1, -1); }

        .cover-inner {
            position: absolute;
            top: 22mm; left: 22mm; right: 22mm; bottom: 22mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            z-index: 3;
        }

        .anno-block {
            margin-top: 16mm;
        }
        .anno-block .anno {
            margin-top: 8pt;
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.5em;
            text-transform: uppercase;
            color: var(--gold);
            font-weight: 600;
        }

        .cover-title-block {
            margin-top: 12mm;
        }
        .cover-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.6em;
            color: var(--gold);
            font-weight: 600;
        }
        .cover-title {
            font-size: 124pt;
            font-style: italic;
            font-weight: 400;
            line-height: 0.95;
            letter-spacing: -0.02em;
            margin: 14pt 0;
            color: var(--ivory);
        }

        .cover-quote {
            margin-top: 18pt;
            font-size: 22pt;
            font-style: italic;
            color: var(--ivory);
            max-width: 460pt;
        }
        .cover-org {
            margin-top: 14pt;
            font-family: 'Inter', sans-serif;
            font-size: 11pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--gold);
        }

        .cover-meta {
            margin-bottom: 6mm;
            display: flex;
            gap: 36pt;
            font-family: 'Inter', sans-serif;
        }
        .cover-meta-item .k {
            font-size: 9pt;
            letter-spacing: 0.4em;
            text-transform: uppercase;
            color: var(--gold);
            font-weight: 600;
        }
        .cover-meta-item .v {
            margin-top: 6pt;
            font-family: 'Cormorant Garamond', serif;
            font-size: 17pt;
            font-style: italic;
            color: var(--ivory);
        }

        /* Diamond ornament line */
        .ornament-line {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin: 0 auto;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .content {
            background: var(--cream);
            color: var(--dark);
        }

        .black-band {
            background: var(--black);
            color: var(--gold);
            padding: 16pt 22mm;
            text-align: center;
            position: relative;
        }
        .black-band .sec-num {
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.5em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .black-band .sec-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 28pt;
            font-style: italic;
            margin-top: 4pt;
            color: var(--ivory);
            font-weight: 400;
        }
        .black-band::before, .black-band::after {
            content: "";
            position: absolute;
            top: 50%; transform: translateY(-50%);
            width: 18mm;
            height: 1px;
            background: var(--gold);
        }
        .black-band::before { left: 22mm; }
        .black-band::after { right: 22mm; }

        .content-pad {
            padding: 22pt 22mm 24pt;
        }

        .framed-box {
            border-top: 1.6pt solid var(--gold);
            border-bottom: 1.6pt solid var(--gold);
            padding: 14pt 0;
            margin: 0 auto;
            max-width: 540pt;
        }

        .row {
            display: grid;
            grid-template-columns: 200pt 1fr;
            gap: 20pt;
            padding: 8pt 0;
            border-bottom: 0.6pt solid var(--gold-soft);
        }
        .row:last-child { border-bottom: none; }
        .row-label {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--muted-gold);
            font-weight: 600;
            padding-top: 4pt;
        }
        .row-value {
            font-size: 15.5pt;
            font-style: italic;
            color: var(--dark);
            line-height: 1.5;
        }
        .row-value.body {
            font-family: 'Inter', sans-serif;
            font-style: normal;
            font-size: 10.5pt;
            line-height: 1.6;
        }

        .dark-quote-box {
            margin: 22pt auto 0;
            max-width: 540pt;
            background: var(--black);
            color: var(--ivory);
            text-align: center;
            border: 1px solid var(--gold);
            padding: 18pt 24pt;
        }
        .dark-quote-box .k {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.4em;
            text-transform: uppercase;
            color: var(--gold);
            font-weight: 600;
        }
        .dark-quote-box .v {
            margin-top: 8pt;
            font-size: 15.5pt;
            font-style: italic;
            line-height: 1.55;
        }

        /* Diamond row separator */
        .diamond-row {
            text-align: center;
            margin: 18pt 0;
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.5em;
            color: var(--muted-gold);
        }

        /* Two-column grid for prose pairs */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28pt;
            margin: 0 auto;
            max-width: 540pt;
            padding-top: 16pt;
        }
        .two-col .pair .k {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--muted-gold);
            font-weight: 600;
        }
        .two-col .pair .v {
            margin-top: 6pt;
            font-size: 15pt;
            font-style: italic;
            line-height: 1.5;
        }

        /* List */
        ul.gold-list { list-style: none; margin: 4pt 0 0; padding: 0; }
        ul.gold-list li {
            padding: 4pt 0;
            font-size: 14pt;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 10pt;
        }
        ul.gold-list li::before {
            content: "";
            width: 6pt; height: 6pt;
            background: var(--gold);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        /* Tables */
        table.deco-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 auto;
            max-width: 540pt;
        }
        table.deco-table th {
            background: var(--black);
            color: var(--gold);
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            text-align: left;
            padding: 8pt 12pt;
            font-weight: 600;
            border-right: 0.5pt solid var(--gold-soft);
        }
        table.deco-table th:last-child { border-right: none; }
        table.deco-table td {
            border-bottom: 0.6pt solid var(--gold-soft);
            padding: 10pt 12pt;
            font-size: 13pt;
            font-style: italic;
            vertical-align: top;
            line-height: 1.5;
        }
        table.deco-table tr:last-child td { border-bottom: 1.6pt solid var(--gold); }
        table.deco-table td.seq {
            font-family: 'Inter', sans-serif;
            font-style: normal;
            color: var(--muted-gold);
            font-size: 10pt;
            font-weight: 600;
            width: 28pt;
        }

        /* Pills */
        .chip-row { display: flex; flex-wrap: wrap; gap: 6pt; margin-top: 6pt; }
        .chip {
            padding: 4pt 12pt;
            border: 0.6pt solid var(--gold);
            background: rgba(212, 175, 55, 0.08);
            font-size: 11pt;
            font-style: italic;
            color: var(--dark);
        }
        .chip.pii {
            background: var(--black);
            color: var(--gold);
            border-color: var(--gold);
        }

        .risk-badge {
            display: inline-block;
            padding: 6pt 18pt;
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            font-weight: 700;
            border: 1px solid var(--gold);
        }
        .risk-HIGH { background: var(--black); color: var(--gold); }
        .risk-MEDIUM { background: var(--gold); color: var(--black); }
        .risk-LOW { background: var(--cream); color: var(--dark); border-color: var(--muted-gold); }

        /* Page footer */
        .page-footer {
            position: absolute;
            bottom: 16pt;
            left: 0; right: 0;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.5em;
            color: var(--muted-gold);
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;

    // SVG snippets: ornament + corner fan
    $ornament = '<svg width="80" height="40" viewBox="0 0 80 40"><path d="M40 4 L46 18 L60 20 L46 22 L40 36 L34 22 L20 20 L34 18 Z" fill="#d4af37"/><line x1="0" y1="20" x2="18" y2="20" stroke="#d4af37" stroke-width="1"/><line x1="62" y1="20" x2="80" y2="20" stroke="#d4af37" stroke-width="1"/></svg>';
    $cornerFan = '<svg width="100%" height="100%" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0 0 L100 0 L0 100 Z" fill="none" stroke="#d4af37" stroke-width=".6"/><path d="M0 0 L80 0 L0 80 Z" fill="none" stroke="#d4af37" stroke-width=".6"/><path d="M0 0 L60 0 L0 60 Z" fill="none" stroke="#d4af37" stroke-width=".6"/><path d="M0 0 L40 0 L0 40 Z" fill="none" stroke="#d4af37" stroke-width=".6"/><path d="M0 0 L20 0 L0 20 Z" fill="none" stroke="#d4af37" stroke-width=".6"/></svg>';
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page cover">
    <div class="frame-outer"></div>
    <div class="frame-inner"></div>

    <div class="corner-fan tl">{!! $cornerFan !!}</div>
    <div class="corner-fan tr">{!! $cornerFan !!}</div>
    <div class="corner-fan bl">{!! $cornerFan !!}</div>
    <div class="corner-fan br">{!! $cornerFan !!}</div>

    <div class="cover-inner">
        <div class="anno-block">
            <div class="ornament-line">{!! $ornament !!}</div>
            <div class="anno">Anno Domini · 2026</div>
        </div>

        <div class="cover-title-block">
            <div class="cover-eyebrow">RECORD OF</div>
            <h1 class="cover-title"><em>Processing</em></h1>
            <div class="cover-eyebrow">ACTIVITIES</div>
            <div class="ornament-line" style="margin-top: 14pt;">{!! $ornament !!}</div>
            <div class="cover-quote">&ldquo;{{ $r['name'] ?? '-' }}&rdquo;</div>
            <div class="cover-org">{{ $orgName }}</div>
        </div>

        <div>
            <div class="ornament-line" style="margin-bottom: 18pt;">{!! $ornament !!}</div>
            <div class="cover-meta">
                <div class="cover-meta-item">
                    <div class="k">Ref</div>
                    <div class="v">{{ $r['number'] ?? '-' }}</div>
                </div>
                <div class="cover-meta-item">
                    <div class="k">Date</div>
                    <div class="v">{{ $r['date'] ?? '-' }}</div>
                </div>
                <div class="cover-meta-item">
                    <div class="k">Div</div>
                    <div class="v">{{ $r['division'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Section I Deskripsi + II Pejabat
     ============================================================ --}}
<section class="page content">
    <div class="black-band">
        <div class="sec-num">Section I</div>
        <div class="sec-title">Deskripsi Pemrosesan</div>
    </div>
    <div class="content-pad">
        <div class="framed-box">
            <div class="row"><div class="row-label">Nomor</div><div class="row-value">{{ $r['number'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Nama Pemrosesan</div><div class="row-value">{{ $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Divisi</div><div class="row-value">{{ $r['division'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Unit Kerja</div><div class="row-value">{{ $r['unit'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Entitas</div><div class="row-value">{{ $orgName }}</div></div>
            <div class="row"><div class="row-label">Kategori Perusahaan</div><div class="row-value">{{ $r['category'] ?? '-' }}</div></div>
        </div>
        <div class="dark-quote-box">
            <div class="k">Deskripsi Singkat</div>
            <div class="v">&ldquo;{{ $r['description'] ?? '-' }}&rdquo;</div>
        </div>
    </div>

    <div class="black-band">
        <div class="sec-num">Section II</div>
        <div class="sec-title">Pejabat PDP &amp; PIC</div>
    </div>
    <div class="content-pad">
        <table class="deco-table">
            <thead>
                <tr>
                    <th style="width: 6%;">No.</th>
                    <th style="width: 26%;">DPO</th>
                    <th>Email</th>
                    <th style="width: 22%;">Telepon</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq">i.</td>
                    <td>{{ $r['dpo']['name'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['email'] ?? '-' }}</td>
                    <td>{{ $r['dpo']['phone'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
        <div class="diamond-row">&diams; &diams; &diams;</div>
        <table class="deco-table">
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
                    <td class="seq">i.</td>
                    <td>{{ $r['pic']['name'] ?? '-' }}</td>
                    <td>{{ $r['pic']['role'] ?? '-' }}</td>
                    <td>{{ $r['pic']['email'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="page-footer">&diams; &nbsp; PAGE TWO &nbsp; &diams;</div>
</section>

{{-- ============================================================
     PAGE 3 — III Informasi + IV Sistem + V Teknologi
     ============================================================ --}}
<section class="page content">
    <div class="black-band">
        <div class="sec-num">Section III</div>
        <div class="sec-title">Informasi Pemrosesan</div>
    </div>
    <div class="content-pad" style="padding-top: 18pt;">
        <div class="two-col">
            <div class="pair">
                <div class="k">Tujuan</div>
                <div class="v">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Dasar Hukum</div>
                <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
            <div class="pair" style="grid-column: span 2;">
                <div class="k">Aktivitas</div>
                <div class="v">{{ $r['activity'] ?? '-' }}</div>
            </div>
            <div class="pair" style="grid-column: span 2;">
                <div class="k">Kategori</div>
                <ul class="gold-list">
                    @foreach($r['categories'] ?? [] as $cat)
                        <li>{{ $cat }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="diamond-row">&diams; &diams; &diams;</div>
    </div>

    <div class="black-band">
        <div class="sec-num">Section IV</div>
        <div class="sec-title">Sistem Informasi</div>
    </div>
    <div class="content-pad">
        <table class="deco-table">
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
                        <td class="seq">{{ $i + 1 }}.</td>
                        <td>{{ $sys['name'] ?? '-' }}</td>
                        <td>{{ $sys['loc'] ?? '-' }}</td>
                        <td>{{ $sys['use_loc'] ?? ($sys['loc'] ?? '-') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center; color: var(--muted-gold);">&mdash;</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="black-band">
        <div class="sec-num">Section V</div>
        <div class="sec-title">Teknologi &amp; Pemrofilan</div>
    </div>
    <div class="content-pad">
        <div class="two-col">
            <div class="pair">
                <div class="k">Bantuan AI</div>
                <div class="v">{{ $r['uses_ai'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Keputusan Otomatis</div>
                <div class="v">{{ $r['uses_automated_decision'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Teknologi Baru</div>
                <div class="v">{{ $r['uses_new_tech'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Tujuan Pemrofilan</div>
                <div class="v">{{ $r['profiling_purpose'] ?? '-' }}</div>
            </div>
        </div>
    </div>
    <div class="page-footer">&diams; &nbsp; PAGE THREE &nbsp; &diams;</div>
</section>

{{-- ============================================================
     PAGE 4 — VI Pengumpulan Data
     ============================================================ --}}
<section class="page content">
    <div class="black-band">
        <div class="sec-num">Section VI</div>
        <div class="sec-title">Pengumpulan Data</div>
    </div>
    <div class="content-pad">
        <div class="framed-box">
            <div class="row">
                <div class="row-label">Jenis Subjek</div>
                <div class="row-value">
                    <ul class="gold-list">
                        @foreach($r['data_subjects'] ?? [] as $s)
                            <li>{{ $s }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Jumlah Subjek</div><div class="row-value">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Sumber</div><div class="row-value">{{ $r['data_source'] ?? '-' }}</div></div>
        </div>

        <div class="diamond-row">&diams; &diams; &diams;</div>

        <div class="framed-box">
            <div class="row">
                <div class="row-label">Data — Umum</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="row-label">Data — Spesifik</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_specific'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="row-label">Data — PII</div>
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
    <div class="page-footer">&diams; &nbsp; PAGE FOUR &nbsp; &diams;</div>
</section>

{{-- ============================================================
     PAGE 5 — VII Penggunaan + VIII Pihak Ketiga
     ============================================================ --}}
<section class="page content">
    <div class="black-band">
        <div class="sec-num">Section VII</div>
        <div class="sec-title">Penggunaan &amp; Penyimpanan</div>
    </div>
    <div class="content-pad">
        <div class="framed-box">
            <div class="row">
                <div class="row-label">Kategori Pemroses</div>
                <div class="row-value">
                    <ul class="gold-list">
                        @foreach($r['processor_role'] ?? [] as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Pemroses Utama</div><div class="row-value">{{ $r['processor_entity'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Pihak Ketiga</div><div class="row-value">{{ $r['has_third_party'] ?? '-' }}</div></div>
        </div>
    </div>

    @if(!empty($r['third_parties']))
    <div class="black-band">
        <div class="sec-num">Section VIII</div>
        <div class="sec-title">Pihak Ketiga</div>
    </div>
    <div class="content-pad">
        <table class="deco-table">
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
                        <td class="seq">{{ $i + 1 }}.</td>
                        <td>{{ $tp['name'] ?? '-' }}</td>
                        <td>{{ $tp['address'] ?? '-' }}</td>
                        <td>{{ $tp['pic_name'] ?? '-' }}</td>
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="font-family: 'Inter', sans-serif; font-style: normal; font-size: 8.5pt; color: var(--muted-gold);">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    <div class="page-footer">&diams; &nbsp; PAGE FIVE &nbsp; &diams;</div>
</section>

{{-- ============================================================
     PAGE 6 — IX Pengiriman + X Jenis Data Dikirim
     ============================================================ --}}
<section class="page content">
    <div class="black-band">
        <div class="sec-num">Section IX</div>
        <div class="sec-title">Pengiriman Data</div>
    </div>
    <div class="content-pad">
        <div class="two-col">
            <div class="pair">
                <div class="k">Penerima Internal</div>
                <div class="v">{{ $r['recipients_internal'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Penerima Eksternal</div>
                <div class="v">{{ $r['recipients_external'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Transfer Lintas Batas</div>
                <div class="v">{{ $r['cross_border_transfer'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Negara Tujuan</div>
                <div class="v">{{ !empty($r['cross_border_destinations']) ? implode(', ', $r['cross_border_destinations']) : '-' }}</div>
            </div>
        </div>
        <div class="diamond-row">&diams; &diams; &diams;</div>
    </div>

    <div class="black-band">
        <div class="sec-num">Section X</div>
        <div class="sec-title">Jenis Data Dikirim</div>
    </div>
    <div class="content-pad">
        <div class="framed-box">
            <div class="row">
                <div class="row-label">Umum</div>
                <div class="row-value">
                    <div class="chip-row">
                        @foreach($r['data_general'] ?? [] as $d)
                            <span class="chip">{{ $d }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="row">
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
    <div class="page-footer">&diams; &nbsp; PAGE SIX &nbsp; &diams;</div>
</section>

{{-- ============================================================
     PAGE 7 — XI Retensi + XII Keamanan + XIII Risiko
     ============================================================ --}}
<section class="page content">
    <div class="black-band">
        <div class="sec-num">Section XI</div>
        <div class="sec-title">Retensi Data</div>
    </div>
    <div class="content-pad">
        <div class="framed-box">
            <div class="row"><div class="row-label">Dokumen Terkait</div><div class="row-value">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Masa Retensi</div><div class="row-value">{{ $r['retention_period'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Tanggal Berlaku</div><div class="row-value">{{ $r['retention_effective_date'] ?? '-' }} &mdash; {{ $r['retention_end_date'] ?? '-' }}</div></div>
            <div class="row"><div class="row-label">Aktivitas Penghapusan</div><div class="row-value">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="black-band">
        <div class="sec-num">Section XII</div>
        <div class="sec-title">Keamanan Data</div>
    </div>
    <div class="content-pad">
        <div class="framed-box">
            <div class="row">
                <div class="row-label">Kontrol Keamanan</div>
                <div class="row-value">
                    <ul class="gold-list">
                        @foreach($r['controls'] ?? [] as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="row"><div class="row-label">Riwayat Insiden</div><div class="row-value">{{ $r['has_past_incident'] ?? '-' }}</div></div>
        </div>
    </div>

    <div class="black-band">
        <div class="sec-num">Section XIII</div>
        <div class="sec-title">Klasifikasi Risiko</div>
    </div>
    <div class="content-pad" style="text-align: center;">
        <span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span>
        <div style="margin-top: 16pt; max-width: 540pt; margin-left: auto; margin-right: auto; font-size: 14pt; font-style: italic; line-height: 1.55; color: var(--dark);">&ldquo;{{ $r['risk_justification'] ?? '-' }}&rdquo;</div>
    </div>
    <div class="page-footer">&diams; &nbsp; FINIS &nbsp; &diams;</div>
</section>

</body>
</html>
