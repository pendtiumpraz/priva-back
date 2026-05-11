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
            color: #2d3a22;
        }

        /* =========================================================
           Palette Botanical Vintage
           ========================================================= */
        :root {
            --paper: #f0ead6;
            --paper-2: #faf6e8;
            --olive: #4a6b3a;
            --olive-soft: #5a6b4a;
            --ink: #2d3a22;
            --rule: #b4b89a;
            --rule-soft: #d8d4b8;
            --accent: #e8e3cb;
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
           COVER — sage cream + olive, vines, scholarly
           ========================================================= */
        .cover { background: var(--paper); color: var(--ink); }

        .vine-corner {
            position: absolute;
            width: 80px; height: 200px;
            z-index: 2;
        }
        .vine-corner.tl { top: 60px; left: 32px; }
        .vine-corner.tr { top: 60px; right: 32px; transform: scaleX(-1); }
        .vine-corner.bl { bottom: 60px; left: 32px; transform: scaleY(-1); }
        .vine-corner.br { bottom: 60px; right: 32px; transform: scale(-1, -1); }

        .frame {
            position: absolute;
            inset: 80px;
            border: 1px solid var(--olive);
            z-index: 1;
        }

        .cover-inner {
            position: absolute;
            top: 88px; left: 88px; right: 88px; bottom: 88px;
            padding: 32px;
            text-align: center;
            z-index: 3;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .cover-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.5em;
            text-transform: uppercase;
            color: var(--olive);
            font-weight: 600;
        }

        .cover-title {
            font-size: 86pt;
            font-weight: 400;
            font-style: italic;
            margin: 32pt 0 16pt;
            letter-spacing: -0.02em;
            line-height: 1.04;
            color: var(--ink);
        }

        .cover-mid-rule { width: 60pt; height: 1px; background: var(--olive); margin: 16pt auto; }

        .cover-eyebrow-2 {
            font-family: 'Inter', sans-serif;
            font-size: 11pt;
            letter-spacing: 0.4em;
            text-transform: uppercase;
            color: var(--olive);
            font-weight: 600;
        }

        .cover-quote {
            margin-top: 60pt;
            font-size: 22pt;
            font-style: italic;
            color: var(--ink);
        }
        .cover-org {
            margin-top: 14pt;
            font-family: 'Inter', sans-serif;
            font-size: 11pt;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: var(--olive-soft);
            font-weight: 500;
        }

        .cover-meta {
            display: inline-flex;
            gap: 40pt;
            padding: 16pt 32pt;
            border-top: 1px solid var(--olive);
            border-bottom: 1px solid var(--olive);
            margin: 60pt auto 0;
        }
        .cover-meta-item .k {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--olive);
            font-weight: 600;
        }
        .cover-meta-item .v {
            margin-top: 4pt;
            font-size: 16pt;
            font-style: italic;
        }

        /* =========================================================
           CONTENT PAGES
           ========================================================= */
        .content { background: var(--paper-2); color: var(--ink); }

        .caput-head {
            padding: 30pt 22mm 14pt;
            border-bottom: 1px solid var(--olive);
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .caput-head h2 {
            font-size: 38pt;
            font-style: italic;
            font-weight: 400;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .caput-head h2 em {
            font-style: normal;
            color: var(--olive);
        }
        .caput-head .meta {
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--olive-soft);
            font-weight: 500;
        }

        .content-pad { padding: 18pt 22mm; }

        .row {
            display: grid;
            grid-template-columns: 160pt 1fr;
            gap: 22pt;
            padding: 9pt 0;
            border-bottom: 1px dashed var(--rule);
        }
        .row-label {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: var(--olive);
            font-weight: 600;
            padding-top: 3pt;
        }
        .row-value {
            font-size: 14.5pt;
            font-style: italic;
            line-height: 1.5;
        }
        .row-value.body {
            font-family: 'Inter', sans-serif;
            font-style: normal;
            font-size: 10pt;
            line-height: 1.65;
        }

        .pull-quote {
            margin: 14pt 0;
            padding: 14pt 22pt;
            background: var(--accent);
            border-left: 3pt solid var(--olive);
            font-size: 14pt;
            font-style: italic;
            line-height: 1.6;
        }

        .center-rule {
            text-align: center;
            margin: 18pt 0 12pt;
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.4em;
            color: var(--olive);
            position: relative;
        }
        .center-rule::before, .center-rule::after {
            content: "";
            display: inline-block;
            width: 60pt;
            height: 1px;
            background: var(--olive);
            vertical-align: middle;
            margin: 0 14pt;
        }

        .caput-divider {
            text-align: center;
            padding: 14pt 22mm 8pt;
            border-top: 1px solid var(--olive);
            border-bottom: 1px solid var(--olive);
            margin: 14pt 22mm 0;
        }
        .caput-divider h2 {
            font-size: 30pt;
            font-style: italic;
            font-weight: 400;
            margin: 0;
        }
        .caput-divider h2 em { font-style: normal; color: var(--olive); }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22pt;
            padding: 14pt 0;
        }
        .two-col .pair .k {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--olive);
            font-weight: 600;
            margin-bottom: 4pt;
        }
        .two-col .pair .v {
            font-size: 14pt;
            font-style: italic;
            line-height: 1.5;
        }

        ul.botanic-list { list-style: none; margin: 0; padding: 0; }
        ul.botanic-list li {
            padding: 2pt 0;
            font-size: 13pt;
            font-style: italic;
        }
        ul.botanic-list li::before {
            content: "· ";
            color: var(--olive);
            font-weight: 700;
            font-style: normal;
        }

        /* Tables */
        table.botanic-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8pt;
        }
        table.botanic-table th {
            font-family: 'Inter', sans-serif;
            font-size: 9pt;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: var(--olive);
            font-weight: 600;
            text-align: left;
            padding: 8pt 10pt 8pt 0;
            border-bottom: 1px solid var(--olive);
        }
        table.botanic-table td {
            padding: 9pt 10pt 9pt 0;
            border-bottom: 1px dashed var(--rule);
            font-size: 13pt;
            font-style: italic;
            vertical-align: top;
            line-height: 1.5;
        }
        table.botanic-table td.seq {
            font-family: 'Inter', sans-serif;
            font-style: normal;
            font-size: 9.5pt;
            color: var(--olive);
            font-weight: 600;
            width: 24pt;
        }

        .chip-row { display: flex; flex-wrap: wrap; gap: 6pt; margin-top: 6pt; }
        .chip {
            padding: 3pt 11pt;
            border: 0.6pt solid var(--olive);
            border-radius: 999pt;
            background: rgba(74, 107, 58, 0.06);
            font-size: 10.5pt;
            font-style: italic;
            color: var(--ink);
        }
        .chip.pii {
            background: var(--olive);
            color: var(--paper);
        }

        .risk-badge {
            display: inline-block;
            padding: 5pt 18pt;
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            font-weight: 700;
            border: 1px solid var(--olive);
            border-radius: 999pt;
        }
        .risk-HIGH { background: #6b2a2a; color: var(--paper); border-color: #6b2a2a; }
        .risk-MEDIUM { background: var(--olive); color: var(--paper); }
        .risk-LOW { background: transparent; color: var(--olive); }

        .page-footer {
            position: absolute;
            bottom: 18pt;
            left: 22mm; right: 22mm;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 10pt;
            letter-spacing: 0.4em;
            color: var(--olive-soft);
        }
    </style>
</head>
<body>

@php
    $r = $ropa;
    $orgName = $r['org'] ?? ($orgName ?? '-');
    $totalPages = 7;

    // Vine SVG ornament for corners
    $vine = '<svg width="80" height="200" viewBox="0 0 80 200" xmlns="http://www.w3.org/2000/svg">
        <path d="M40 0 Q40 50 30 80 Q20 110 40 140 Q60 170 40 200" stroke="#4a6b3a" stroke-width="1.2" fill="none"/>';
    for ($i = 0; $i < 5; $i++) {
        $y = 20 + $i * 40;
        $dir = ($i % 2 == 0) ? 1 : -1;
        $rot1 = ($i % 2 == 0) ? 30 : -30;
        $rot2 = -$rot1;
        $cx1 = 12 * $dir;
        $cx2 = -12 * $dir;
        $vine .= "<g transform='translate(40 {$y})'>";
        $vine .= "<ellipse cx='{$cx1}' cy='0' rx='10' ry='4' fill='#4a6b3a' opacity='.7' transform='rotate({$rot1})'/>";
        $vine .= "<ellipse cx='{$cx2}' cy='6' rx='8' ry='3' fill='#4a6b3a' opacity='.5' transform='rotate({$rot2})'/>";
        $vine .= '</g>';
    }
    $vine .= '</svg>';
@endphp

{{-- ============================================================
     PAGE 1 — COVER
     ============================================================ --}}
<section class="page cover">
    <div class="frame"></div>
    <div class="vine-corner tl">{!! $vine !!}</div>
    <div class="vine-corner tr">{!! $vine !!}</div>
    <div class="vine-corner bl">{!! $vine !!}</div>
    <div class="vine-corner br">{!! $vine !!}</div>

    <div class="cover-inner">
        <div>
            <div class="cover-eyebrow">Liber Processus</div>
            <h1 class="cover-title">Record of<br>Processing</h1>
            <div class="cover-mid-rule"></div>
            <div class="cover-eyebrow-2">Activities</div>
            <div class="cover-quote">&ldquo;{{ $r['name'] ?? '-' }}&rdquo;</div>
            <div class="cover-org">{{ $orgName }}</div>
        </div>

        <div>
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
                    <div class="k">Divisi</div>
                    <div class="v">{{ $r['division'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     PAGE 2 — Caput I Deskripsi + Caput II Pejabat
     ============================================================ --}}
<section class="page content">
    <div class="caput-head">
        <h2>Caput I &middot; <em>Deskripsi</em></h2>
        <div class="meta">{{ $r['number'] ?? '-' }}</div>
    </div>
    <div class="content-pad">
        <div class="row"><div class="row-label">Nomor</div><div class="row-value">{{ $r['number'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Nama</div><div class="row-value">{{ $r['name'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Divisi</div><div class="row-value">{{ $r['division'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Unit Kerja</div><div class="row-value">{{ $r['unit'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Entitas</div><div class="row-value">{{ $orgName }}</div></div>
        <div class="row" style="border-bottom: none;"><div class="row-label">Kategori</div><div class="row-value">{{ $r['category'] ?? '-' }}</div></div>

        <div class="pull-quote">&ldquo;{{ $r['description'] ?? '-' }}&rdquo;</div>
    </div>

    <div class="caput-divider">
        <h2>Caput II &middot; <em>Pejabat &amp; PIC</em></h2>
    </div>
    <div class="content-pad">
        <table class="botanic-table">
            <thead>
                <tr>
                    <th style="width: 6%;">№</th>
                    <th style="width: 26%;">Pejabat PDP (DPO)</th>
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
        <div class="center-rule">&#x2766;</div>
        <table class="botanic-table">
            <thead>
                <tr>
                    <th style="width: 6%;">№</th>
                    <th style="width: 26%;">Process Owner / PIC</th>
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
    <div class="page-footer">&#x2766; {{ $orgName }} &#x2766; &nbsp; pag. II</div>
</section>

{{-- ============================================================
     PAGE 3 — Caput III Informasi + IV Sistem + V Teknologi
     ============================================================ --}}
<section class="page content">
    <div class="caput-head">
        <h2>Caput III &middot; <em>Informasi</em></h2>
        <div class="meta">PROCESSING</div>
    </div>
    <div class="content-pad">
        <div class="two-col">
            <div class="pair">
                <div class="k">Tujuan</div>
                <div class="v">{{ $r['purpose'] ?? '-' }}</div>
            </div>
            <div class="pair">
                <div class="k">Dasar Hukum</div>
                <div class="v">{{ $r['legal_basis'] ?? '-' }}</div>
            </div>
        </div>
        <div class="row"><div class="row-label">Aktivitas</div><div class="row-value">{{ $r['activity'] ?? '-' }}</div></div>
        <div class="row" style="border-bottom: none;">
            <div class="row-label">Kategori</div>
            <div class="row-value">
                <ul class="botanic-list">
                    @foreach($r['categories'] ?? [] as $cat)
                        <li>{{ $cat }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="caput-divider">
        <h2>Caput IV &middot; <em>Sistem Informasi</em></h2>
    </div>
    <div class="content-pad">
        <table class="botanic-table">
            <thead>
                <tr>
                    <th style="width: 6%;">№</th>
                    <th>Nama Sistem</th>
                    <th>Lokasi Simpan</th>
                    <th>Lokasi Pakai</th>
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
                    <tr><td colspan="4" style="text-align:center;">&mdash;</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="caput-divider">
        <h2>Caput V &middot; <em>Teknologi</em></h2>
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
    <div class="page-footer">&#x2766; {{ $orgName }} &#x2766; &nbsp; pag. III</div>
</section>

{{-- ============================================================
     PAGE 4 — Caput VI Pengumpulan Data
     ============================================================ --}}
<section class="page content">
    <div class="caput-head">
        <h2>Caput VI &middot; <em>Pengumpulan</em></h2>
        <div class="meta">DATA SUBJECTS</div>
    </div>
    <div class="content-pad">
        <div class="row">
            <div class="row-label">Jenis Subjek</div>
            <div class="row-value">
                <ul class="botanic-list">
                    @foreach($r['data_subjects'] ?? [] as $s)
                        <li>{{ $s }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="row"><div class="row-label">Jumlah Subjek</div><div class="row-value">{{ $r['data_subjects_volume'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Sumber</div><div class="row-value">{{ $r['data_source'] ?? '-' }}</div></div>

        <div class="center-rule">&#x2766; Data Personalia &#x2766;</div>

        <div class="row" style="border-bottom: 1px dashed var(--rule);">
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
        <div class="row" style="border-bottom: none;">
            <div class="row-label">PII Sensitif</div>
            <div class="row-value">
                <div class="chip-row">
                    @foreach($r['data_pii'] ?? [] as $d)
                        <span class="chip pii">{{ $d }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="page-footer">&#x2766; {{ $orgName }} &#x2766; &nbsp; pag. IV</div>
</section>

{{-- ============================================================
     PAGE 5 — Caput VII Penggunaan + VIII Pihak Ketiga
     ============================================================ --}}
<section class="page content">
    <div class="caput-head">
        <h2>Caput VII &middot; <em>Penggunaan</em></h2>
        <div class="meta">STORAGE</div>
    </div>
    <div class="content-pad">
        <div class="row">
            <div class="row-label">Kategori Pemroses</div>
            <div class="row-value">
                <ul class="botanic-list">
                    @foreach($r['processor_role'] ?? [] as $role)
                        <li>{{ $role }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="row"><div class="row-label">Pemroses Utama</div><div class="row-value">{{ $r['processor_entity'] ?? '-' }}</div></div>
        <div class="row" style="border-bottom: none;"><div class="row-label">Pihak Ketiga</div><div class="row-value">{{ $r['has_third_party'] ?? '-' }}</div></div>
    </div>

    @if(!empty($r['third_parties']))
    <div class="caput-divider">
        <h2>Caput VIII &middot; <em>Pihak Ketiga</em></h2>
    </div>
    <div class="content-pad">
        <table class="botanic-table">
            <thead>
                <tr>
                    <th style="width: 5%;">№</th>
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
                        <td>{{ $tp['pic_email'] ?? '-' }}<br><span style="font-family: 'Inter', sans-serif; font-style: normal; font-size: 8.5pt; color: var(--olive-soft);">{{ $tp['pic_phone'] ?? '' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    <div class="page-footer">&#x2766; {{ $orgName }} &#x2766; &nbsp; pag. V</div>
</section>

{{-- ============================================================
     PAGE 6 — Caput IX Pengiriman + X Jenis Data Dikirim
     ============================================================ --}}
<section class="page content">
    <div class="caput-head">
        <h2>Caput IX &middot; <em>Pengiriman</em></h2>
        <div class="meta">TRANSFER</div>
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
    </div>

    <div class="caput-divider">
        <h2>Caput X &middot; <em>Jenis Data Dikirim</em></h2>
    </div>
    <div class="content-pad">
        <div class="row" style="border-bottom: 1px dashed var(--rule);">
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
        <div class="row" style="border-bottom: none;">
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
    <div class="page-footer">&#x2766; {{ $orgName }} &#x2766; &nbsp; pag. VI</div>
</section>

{{-- ============================================================
     PAGE 7 — Caput XI Retensi + XII Keamanan + XIII Risiko
     ============================================================ --}}
<section class="page content">
    <div class="caput-head">
        <h2>Caput XI &middot; <em>Retensi</em></h2>
        <div class="meta">RETENTION</div>
    </div>
    <div class="content-pad">
        <div class="row"><div class="row-label">Dokumen Terkait</div><div class="row-value">{{ $r['retention_doc_name'] ?? $r['name'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Masa Retensi</div><div class="row-value">{{ $r['retention_period'] ?? '-' }}</div></div>
        <div class="row"><div class="row-label">Tanggal Berlaku</div><div class="row-value">{{ $r['retention_effective_date'] ?? '-' }} &mdash; {{ $r['retention_end_date'] ?? '-' }}</div></div>
        <div class="row" style="border-bottom: none;"><div class="row-label">Aktivitas Penghapusan</div><div class="row-value">{{ $r['has_deletion_activity'] ?? '-' }}</div></div>
    </div>

    <div class="caput-divider">
        <h2>Caput XII &middot; <em>Keamanan</em></h2>
    </div>
    <div class="content-pad">
        <div class="row">
            <div class="row-label">Kontrol Keamanan</div>
            <div class="row-value">
                <ul class="botanic-list">
                    @foreach($r['controls'] ?? [] as $ctrl)
                        <li>{{ $ctrl }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="row" style="border-bottom: none;"><div class="row-label">Riwayat Insiden</div><div class="row-value">{{ $r['has_past_incident'] ?? '-' }}</div></div>
    </div>

    <div class="caput-divider">
        <h2>Caput XIII &middot; <em>Risiko</em></h2>
    </div>
    <div class="content-pad" style="text-align: center;">
        <span class="risk-badge risk-{{ $r['risk_level'] ?? 'MEDIUM' }}">{{ $r['risk_level'] ?? 'MEDIUM' }}</span>
        <div class="pull-quote" style="text-align: left; margin-top: 18pt;">&ldquo;{{ $r['risk_justification'] ?? '-' }}&rdquo;</div>
    </div>
    <div class="page-footer">&#x2766; Finis &#x2766; &nbsp; pag. VII</div>
</section>

</body>
</html>
