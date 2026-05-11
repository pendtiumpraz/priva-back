<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA — {{ $ropa['name'] ?? '' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'DejaVu Sans', 'Helvetica', sans-serif; color: #1a1612; }

        /* ---------- COVER (Newsprint Gazette) ---------- */
        .cover {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #ede4d0;
            color: #1a1612;
            page-break-after: always;
            overflow: hidden;
        }

        /* huge serif R watermark behind text */
        .watermark-r {
            position: absolute;
            bottom: -30mm; left: 6mm;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 380pt;
            font-weight: 900;
            line-height: 0.8;
            color: #1a1612;
            opacity: 0.06;
        }

        .gazette-top {
            position: relative;
            padding: 10mm 16mm 4mm;
            border-bottom: 1px solid #1a1612;
        }
        .gazette-top table { width: 100%; border-collapse: collapse; }
        .gazette-top td {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
        }
        .gazette-top .center { text-align: center; }
        .gazette-top .right { text-align: right; }

        .masthead {
            position: relative;
            padding: 6mm 16mm 5mm;
            text-align: center;
            border-bottom: 3px solid #1a1612;
        }
        .masthead .title {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 70pt;
            font-weight: 900;
            line-height: 0.9;
            letter-spacing: -2.5pt;
            color: #1a1612;
        }
        .masthead .subtitle {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            margin-top: 3mm;
        }

        .three-col {
            position: relative;
            padding: 6mm 16mm 0;
        }
        .three-col table { width: 100%; border-collapse: collapse; }
        .three-col td {
            width: 33.33%;
            vertical-align: top;
            padding: 0 5mm;
            border-right: 1px solid #b5a98a;
        }
        .three-col td.first { padding-left: 0; }
        .three-col td.last { padding-right: 0; border-right: none; }
        .col-label {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #7a6b4f;
            font-weight: 700;
        }
        .col-head {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 18pt;
            font-weight: 700;
            line-height: 1.05;
            margin: 3mm 0 4mm;
            letter-spacing: -0.3pt;
        }
        .col-body {
            font-size: 10pt;
            line-height: 1.55;
        }
        .facts-list {
            margin-top: 3mm;
            font-size: 10pt;
            line-height: 1.6;
        }
        .facts-list .row {
            border-bottom: 1px dashed #b5a98a;
            padding: 1.5mm 0;
        }
        .facts-list .row.last { border-bottom: none; }
        .editor-note {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            line-height: 1.55;
            margin-top: 3mm;
        }
        .editor-sign {
            margin-top: 4mm;
            font-size: 9pt;
            color: #7a6b4f;
        }

        .cover-bar {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            padding: 5mm 16mm;
            background: #1a1612;
            color: #ede4d0;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
        }
        .cover-bar table { width: 100%; border-collapse: collapse; }
        .cover-bar .center { text-align: center; }
        .cover-bar .right { text-align: right; }

        /* ---------- CONTENT PAGE ---------- */
        .content {
            position: relative;
            width: 21cm; height: 29.7cm;
            background: #ede4d0;
            color: #1a1612;
        }
        .ct-head {
            padding: 11mm 16mm 4mm;
            border-bottom: 3px solid #1a1612;
        }
        .ct-head .topline {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #7a6b4f;
        }
        .ct-head .topline table { width: 100%; border-collapse: collapse; }
        .ct-head .topline .right { text-align: right; }
        .ct-head h2 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 44pt;
            font-weight: 900;
            margin: 3mm 0 0;
            line-height: 1;
            letter-spacing: -1.2pt;
        }
        .ct-head h2 em { font-style: italic; font-weight: 400; }
        .ct-head .section-sub {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-style: italic;
            font-size: 11pt;
            margin-top: 2mm;
            color: #7a6b4f;
        }

        /* dompdf column-count unreliable with floats -> 2-cell table */
        .article {
            padding: 7mm 16mm;
        }
        .article table { width: 100%; border-collapse: collapse; }
        .article td.col {
            width: 50%;
            vertical-align: top;
            font-size: 10pt;
            line-height: 1.6;
        }
        .article td.col-left { padding-right: 5mm; }
        .article td.col-right { padding-left: 5mm; }
        .article p { margin: 0 0 3mm; }
        .dropcap {
            float: left;
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 42pt;
            line-height: 0.85;
            margin-right: 2.5mm;
            margin-top: 0.5mm;
            color: #1a1612;
            font-weight: 900;
        }

        .key-strip {
            margin: 0 16mm;
            padding: 5mm 0;
            border-top: 1px solid #1a1612;
            border-bottom: 1px solid #1a1612;
        }
        .key-strip table { width: 100%; border-collapse: collapse; }
        .key-strip td {
            width: 25%;
            vertical-align: top;
            padding-right: 6mm;
        }
        .key-strip td.last { padding-right: 0; }
        .ks-k {
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
            color: #7a6b4f;
            font-weight: 700;
        }
        .ks-v {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 13pt;
            margin-top: 1.5mm;
            display: block;
        }

        .cat-section {
            padding: 7mm 16mm 0;
        }
        .cat-section h3 {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 24pt;
            font-weight: 900;
            margin: 0;
            letter-spacing: -0.6pt;
        }
        .cat-cols {
            margin-top: 3mm;
        }
        .cat-cols table { width: 100%; border-collapse: collapse; }
        .cat-cols td {
            width: 50%;
            vertical-align: top;
            font-size: 10pt;
            line-height: 1.8;
        }
        .cat-cols td.col-left { padding-right: 5mm; }
        .cat-cols td.col-right { padding-left: 5mm; }

        .ct-bar {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            padding: 5mm 16mm;
            background: #1a1612;
            color: #ede4d0;
            font-size: 8pt;
            letter-spacing: 2.4pt;
            text-transform: uppercase;
        }
        .ct-bar table { width: 100%; border-collapse: collapse; }
        .ct-bar .center { text-align: center; }
        .ct-bar .right { text-align: right; }
    </style>
</head>
<body>
    {{-- COVER --}}
    <div class="cover">
        <div class="watermark-r">R</div>

        <div class="gazette-top">
            <table><tr>
                <td>Edisi {{ $ropa['number'] ?? '' }}</td>
                <td class="center">&starf; &starf; &starf;</td>
                <td class="right">{{ $ropa['date'] ?? ($today ?? '') }}</td>
            </tr></table>
        </div>

        <div class="masthead">
            <div class="title">The Ropa Gazette</div>
            <div class="subtitle">&ldquo;All the data processing fit to print&rdquo; &middot; {{ $ropa['org'] ?? ($orgName ?? '') }}</div>
        </div>

        <div class="three-col">
            <table><tr>
                <td class="first">
                    <div class="col-label">Headlines</div>
                    <div class="col-head">{{ $ropa['name'] ?? '-' }}</div>
                    <div class="col-body"><strong>JAKARTA</strong> &mdash; {{ $ropa['description'] ?? '-' }}</div>
                </td>
                <td>
                    <div class="col-label">Key Facts</div>
                    <div class="facts-list">
                        <div class="row"><strong>No.</strong> {{ $ropa['number'] ?? '-' }}</div>
                        <div class="row"><strong>Divisi.</strong> {{ $ropa['division'] ?? '-' }}</div>
                        <div class="row"><strong>Unit.</strong> {{ $ropa['unit'] ?? '-' }}</div>
                        <div class="row"><strong>DPO.</strong> {{ $ropa['dpo']['name'] ?? '-' }}</div>
                        <div class="row last"><strong>PIC.</strong> {{ $ropa['pic']['name'] ?? '-' }}</div>
                    </div>
                </td>
                <td class="last">
                    <div class="col-label">Editor's Note</div>
                    <div class="editor-note">&ldquo;Dokumen ini menyatakan komitmen kami terhadap pelindungan data pribadi sesuai UU PDP 27/2022.&rdquo;</div>
                    <div class="editor-sign">&mdash; Data Protection Officer</div>
                </td>
            </tr></table>
        </div>

        <div class="cover-bar">
            <table><tr>
                <td>Pemrosesan Resmi</td>
                <td class="center">Halaman 1</td>
                <td class="right">{{ $ropa['legal_basis'] ?? '' }}</td>
            </tr></table>
        </div>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <div class="ct-head">
            <div class="topline">
                <table><tr>
                    <td>The Ropa Gazette &middot; p.02</td>
                    <td class="right">{{ $ropa['number'] ?? '' }}</td>
                </tr></table>
            </div>
            <h2>Deskripsi <em>Pemrosesan</em></h2>
            <div class="section-sub">Section I &mdash; Identity &amp; Scope of the Processing</div>
        </div>

        <div class="article">
            <table><tr>
                <td class="col col-left">
                    <p>
                        <span class="dropcap">D</span>
                        okumen ini mencatat aktivitas pemrosesan data pribadi berjudul
                        <strong>{{ $ropa['name'] ?? '-' }}</strong>, yang dilaksanakan oleh divisi
                        <strong>{{ $ropa['division'] ?? '-' }}</strong>, khususnya unit
                        <strong>{{ $ropa['unit'] ?? '-' }}</strong>.
                    </p>
                    <p>
                        Pemrosesan tersebut dilaksanakan oleh <strong>{{ $ropa['org'] ?? ($orgName ?? '-') }}</strong>
                        dalam kapasitas sebagai <em>{{ $ropa['category'] ?? '-' }}</em>.
                        Tujuan utama pemrosesan adalah {{ $ropa['purpose'] ?? '-' }}.
                    </p>
                </td>
                <td class="col col-right">
                    <p>{{ $ropa['activity'] ?? '-' }}</p>
                    <p>
                        Dasar hukum yang menjadi landasan pemrosesan adalah
                        <strong>{{ $ropa['legal_basis'] ?? '-' }}</strong>,
                        sebagaimana diatur dalam ketentuan perundang-undangan yang berlaku.
                    </p>
                </td>
            </tr></table>
        </div>

        <div class="key-strip">
            <table><tr>
                <td>
                    <div class="ks-k">No.</div>
                    <span class="ks-v">{{ $ropa['number'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="ks-k">Divisi</div>
                    <span class="ks-v">{{ $ropa['division'] ?? '-' }}</span>
                </td>
                <td>
                    <div class="ks-k">Tanggal</div>
                    <span class="ks-v">{{ $ropa['date'] ?? ($today ?? '-') }}</span>
                </td>
                <td class="last">
                    <div class="ks-k">Dasar</div>
                    <span class="ks-v">{{ $ropa['legal_basis'] ?? '-' }}</span>
                </td>
            </tr></table>
        </div>

        <div class="cat-section">
            <h3>Kategori Pemrosesan</h3>
            <div class="cat-cols">
                @php
                    $cats = (!empty($ropa['categories']) && is_array($ropa['categories'])) ? $ropa['categories'] : [];
                    $half = (int) ceil(count($cats) / 2);
                    $left = array_slice($cats, 0, $half);
                    $right = array_slice($cats, $half);
                @endphp
                <table><tr>
                    <td class="col-left">
                        @forelse($left as $c)
                            <div>&middot; {{ $c }}</div>
                        @empty
                            <div>&middot; -</div>
                        @endforelse
                    </td>
                    <td class="col-right">
                        @foreach($right as $c)
                            <div>&middot; {{ $c }}</div>
                        @endforeach
                    </td>
                </tr></table>
            </div>
        </div>

        <div class="ct-bar">
            <table><tr>
                <td>{{ $ropa['org'] ?? ($orgName ?? '') }}</td>
                <td class="center">Halaman 2</td>
                <td class="right">Confidential</td>
            </tr></table>
        </div>
    </div>
</body>
</html>
