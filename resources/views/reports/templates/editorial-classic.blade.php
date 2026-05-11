<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Serif', 'Times New Roman', serif; color: #2b2418; }

        /* ---------- COVER (Editorial Classic) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f5efe2;
            color: #2b2418;
            page-break-after: always;
        }
        .masthead {
            padding: 12mm 22mm 0;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #7a6b4f;
        }
        .masthead table { width: 100%; border-collapse: collapse; }
        .masthead .right { text-align: right; }
        /* double rule under masthead */
        .double-rule {
            margin: 7mm 22mm 0;
            border-top: 1px solid #2b2418;
            border-bottom: 1px solid #2b2418;
            height: 3pt;
        }
        .cover-center {
            padding: 8mm 22mm 0;
            text-align: center;
        }
        .pretitle {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 5pt;
            color: #7a6b4f;
        }
        .big-title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 78pt;
            margin: 14mm 0 3mm;
            font-weight: 400;
            line-height: 0.95;
            letter-spacing: -2pt;
        }
        .big-title em { font-style: italic; }
        .subtitle {
            font-style: italic;
            font-size: 17pt;
            color: #5a4a30;
        }
        .short-rule {
            margin: 10mm auto 0;
            width: 22mm; height: 1px;
            background: #2b2418;
        }
        .quote-name {
            margin: 10mm auto 0;
            max-width: 130mm;
            font-size: 15pt;
            line-height: 1.5;
            color: #3a2f1f;
            font-style: italic;
        }
        .quote-org {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            margin-top: 5mm;
            color: #7a6b4f;
            font-style: normal;
        }
        .ornament {
            text-align: center;
            margin-top: 26mm;
            color: #7a6b4f;
            font-size: 14pt;
            letter-spacing: 6pt;
        }
        /* dompdf flex-quirky -> table layout for 3-col metadata */
        .meta-strip {
            position: absolute;
            bottom: 33mm; left: 22mm; right: 22mm;
            border-top: 1px solid #2b2418;
            padding-top: 7mm;
        }
        .meta-strip table { width: 100%; border-collapse: collapse; }
        .meta-strip td {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
        }
        .meta-label {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 7.5pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #7a6b4f;
        }
        .meta-value {
            font-size: 14pt;
            font-style: italic;
            margin-top: 2mm;
            display: block;
        }
        .cover-foot {
            position: absolute;
            bottom: 14mm; left: 0; right: 0;
            text-align: center;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 7.5pt;
            letter-spacing: 4pt;
            color: #7a6b4f;
            text-transform: uppercase;
        }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #f5efe2;
            color: #2b2418;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
        }
        .content-head {
            padding: 15mm 22mm 11mm;
            border-bottom: 1px solid #2b2418;
        }
        /* simulate 3pt double rule via a thicker second border */
        .content-head-double {
            margin: 0 22mm;
            border-bottom: 1px solid #2b2418;
            height: 1.5mm;
        }
        .content-head table { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
        .ch-label {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 8pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #7a6b4f;
        }
        .ch-right { text-align: right; }
        .content-head h2 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 32pt;
            margin: 3mm 0 0;
            font-weight: 400;
            letter-spacing: -1pt;
        }
        .content-head h2 em { font-style: italic; }

        /* 2-column body */
        .body-cols {
            padding: 11mm 22mm 0;
            font-size: 10.5pt;
            line-height: 1.65;
        }
        .body-cols table { width: 100%; border-collapse: collapse; }
        .body-cols td.col {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }
        .body-cols td.col-left { padding-right: 6mm; }
        .body-cols td.col-right { padding-left: 6mm; }
        .body-cols p { margin: 0 0 3mm; }
        .dropcap {
            float: left;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 48pt;
            line-height: 0.85;
            margin-right: 3mm;
            margin-top: 1mm;
            color: #7a4f1d;
        }

        .pullquote {
            margin: 3mm 22mm 0;
            padding: 6mm 8mm;
            border-top: 1px solid #2b2418;
            border-bottom: 1px solid #2b2418;
            font-style: italic;
            font-size: 13pt;
            text-align: center;
            color: #5a4a30;
        }

        .bot-grid {
            padding: 8mm 22mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 9pt;
            line-height: 1.6;
        }
        .bot-grid table { width: 100%; border-collapse: collapse; }
        .bot-grid td { width: 50%; vertical-align: top; padding-right: 6mm; }
        .bot-label {
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #7a6b4f;
            font-size: 7pt;
            margin-bottom: 2mm;
            display: block;
        }

        .content-foot {
            position: absolute;
            bottom: 10mm; left: 22mm; right: 22mm;
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 7pt;
            letter-spacing: 3pt;
            text-transform: uppercase;
            color: #7a6b4f;
        }
        .content-foot table { width: 100%; border-collapse: collapse; }
        .content-foot .right { text-align: right; }

        .page-break { page-break-after: always; height: 0; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="masthead">
            <table><tr>
                <td>Vol. I &middot; No. 02</td>
                <td class="right">Hak Cipta dilindungi</td>
            </tr></table>
        </div>
        <div class="double-rule"></div>

        <div class="cover-center">
            <div class="pretitle">RECORD OF PROCESSING ACTIVITIES</div>
            <h1 class="big-title">R<em>opa</em></h1>
            <div class="subtitle">A document on personal data processing</div>
            <div class="short-rule"></div>
            <div class="quote-name">
                &ldquo;{{ $ropa['name'] ?? '' }}&rdquo;
                <div class="quote-org">{{ $ropa['org'] ?? ($orgName ?? '') }}</div>
            </div>
            <div class="ornament">&diams; &nbsp; &diams; &nbsp; &diams;</div>
        </div>

        <div class="meta-strip">
            <table><tr>
                <td>
                    <div class="meta-label">Nomor</div>
                    <span class="meta-value">{{ $ropa['number'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="meta-label">Divisi</div>
                    <span class="meta-value">{{ $ropa['division'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="meta-label">Berlaku</div>
                    <span class="meta-value">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                </td>
            </tr></table>
        </div>
        <div class="cover-foot">&diams; Confidential &amp; Internal Use Only &diams;</div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="content-head">
            <table><tr>
                <td class="ch-label">Bagian I</td>
                <td class="ch-label ch-right">{{ $ropa['number'] ?? '' }}</td>
            </tr></table>
            <h2>Deskripsi <em>Pemrosesan</em></h2>
        </div>
        <div class="content-head-double"></div>

        {{-- dompdf doesn't render CSS column-count reliably -> use a 2-column table --}}
        <div class="body-cols">
            <table><tr>
                <td class="col col-left">
                    <p>
                        <span class="dropcap">P</span>
                        roses pemrosesan data pribadi yang dijelaskan dalam dokumen ini mengacu pada aktivitas
                        <em>{{ $ropa['name'] ?? '-' }}</em> yang dijalankan oleh divisi
                        <strong>{{ $ropa['division'] ?? '-' }}</strong>, khususnya
                        <strong>{{ $ropa['unit'] ?? '-' }}</strong>.
                    </p>
                    <p>
                        Aktivitas ini dilaksanakan oleh <strong>{{ $ropa['org'] ?? ($orgName ?? '-') }}</strong>
                        dalam kapasitas sebagai <em>{{ $ropa['category'] ?? '-' }}</em>. Dokumen ini disusun guna
                        memenuhi kewajiban hukum sebagaimana diatur dalam Undang-Undang Pelindungan Data Pribadi.
                    </p>
                </td>
                <td class="col col-right">
                    <p>
                        <strong>Tujuan.</strong> {{ $ropa['purpose'] ?? '-' }}. {{ $ropa['activity'] ?? '' }}
                    </p>
                    <p>
                        <strong>Dasar Hukum.</strong> <em>{{ $ropa['legal_basis'] ?? '-' }}</em>. Pemrosesan tidak
                        menggunakan bantuan AI, teknologi pengambilan keputusan otomatis, maupun teknologi baru yang
                        memerlukan asesmen tambahan.
                    </p>
                </td>
            </tr></table>
        </div>

        <div class="pullquote">
            &ldquo;{{ $ropa['description'] ?? '' }}&rdquo;
        </div>

        <div class="bot-grid">
            <table><tr>
                <td>
                    <span class="bot-label">Kategori Pemrosesan</span>
                    @if(!empty($ropa['categories']) && is_array($ropa['categories']))
                        @foreach($ropa['categories'] as $cat)
                            <div>&middot; {{ $cat }}</div>
                        @endforeach
                    @else
                        <div>&middot; -</div>
                    @endif
                </td>
                <td>
                    <span class="bot-label">Sistem Informasi</span>
                    @if(!empty($ropa['systems']) && is_array($ropa['systems']))
                        @foreach($ropa['systems'] as $sys)
                            <div>&middot; {{ is_array($sys) ? ($sys['name'] ?? '') : $sys }}</div>
                        @endforeach
                    @else
                        <div>&middot; -</div>
                    @endif
                </td>
            </tr></table>
        </div>

        <div class="content-foot">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="right">~ 2 ~</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
