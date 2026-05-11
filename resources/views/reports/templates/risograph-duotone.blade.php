<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA &mdash; {{ $ropa['name'] ?? '' }}</title>
    {{-- Browsershot fetches Google Fonts via networkidle, dompdf cannot --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #1a1a3a;
            -webkit-font-smoothing: antialiased;
        }

        /* ---------- Shared ---------- */
        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            background: #f5ede0;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* Grain pattern wrapper -- Browsershot supports inline SVG patterns */
        .grain {
            position: absolute;
            inset: 0;
            opacity: .12;
            mix-blend-mode: multiply;
            pointer-events: none;
        }
        .grain-soft { opacity: .10; }

        /* ---------- COVER ---------- */
        .cover-shape {
            position: absolute;
            border-radius: 50%;
            mix-blend-mode: multiply; /* core duotone effect */
        }
        .cover-pink {
            top: 22mm;
            right: 16mm;
            width: 90mm;
            height: 90mm;
            background: #ff6b9d;
            opacity: .85;
        }
        .cover-blue {
            top: 46mm;
            right: 38mm;
            width: 90mm;
            height: 90mm;
            background: #3b5bdb;
            opacity: .75;
        }

        .cover-inner {
            position: absolute;
            inset: 0;
            padding: 18mm 18mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 2;
        }
        .cover-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .micro {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: .24em;
            text-transform: uppercase;
        }
        .micro-pink { color: #ff6b9d; }
        .micro-blue { color: #3b5bdb; }

        .cover-headline {
            margin-top: 4mm;
        }
        .cover-eyebrow {
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: #3b5bdb;
        }
        .cover-title {
            font-family: 'Inter', sans-serif;
            font-weight: 900;
            font-size: 96pt;
            line-height: .88;
            letter-spacing: -.04em;
            margin: 4mm 0 0;
            color: #1a1a3a;
        }
        .cover-title .pink {
            color: #ff6b9d;
            mix-blend-mode: multiply;
        }
        .cover-desc {
            margin-top: 8mm;
            max-width: 130mm;
            font-size: 11pt;
            line-height: 1.55;
            font-weight: 500;
            color: #1a1a3a;
        }

        .cover-band {
            background: #1a1a3a;
            color: #f5ede0;
            padding: 6mm 8mm;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4mm;
        }
        .band-k {
            font-size: 7pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: #ff6b9d;
            font-weight: 700;
        }
        .band-v {
            margin-top: 1.5mm;
            font-size: 10.5pt;
            font-weight: 700;
            color: #f5ede0;
        }

        /* ---------- CONTENT ---------- */
        .content-shape-tr {
            position: absolute;
            top: -14mm;
            right: -14mm;
            width: 70mm;
            height: 70mm;
            border-radius: 50%;
            background: #ff6b9d;
            mix-blend-mode: multiply;
            opacity: .75;
        }
        .content-shape-bl {
            position: absolute;
            bottom: -20mm;
            left: -20mm;
            width: 85mm;
            height: 85mm;
            border-radius: 50%;
            background: #3b5bdb;
            mix-blend-mode: multiply;
            opacity: .65;
        }
        .content-inner {
            position: relative;
            padding: 14mm 16mm;
            z-index: 2;
        }

        .sec-head {
            display: flex;
            align-items: baseline;
            gap: 6mm;
            margin-bottom: 6mm;
        }
        .sec-num {
            font-weight: 900;
            font-size: 48pt;
            letter-spacing: -.05em;
            line-height: .9;
            mix-blend-mode: multiply;
        }
        .sec-num.pink { color: #ff6b9d; }
        .sec-num.blue { color: #3b5bdb; }
        .sec-title {
            margin: 0;
            font-size: 24pt;
            font-weight: 800;
            letter-spacing: -.02em;
            color: #1a1a3a;
        }

        .navy-card {
            background: #1a1a3a;
            color: #f5ede0;
            padding: 6mm 8mm;
        }
        .navy-row {
            display: grid;
            grid-template-columns: 36mm 1fr;
            padding: 2.5mm 0;
            border-bottom: 1px solid rgba(245,237,224,.15);
        }
        .navy-row:last-child { border-bottom: none; }
        .navy-k {
            font-size: 8pt;
            color: #ff6b9d;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .navy-v {
            font-size: 10pt;
            font-weight: 500;
            color: #f5ede0;
        }

        .callout-pink {
            margin-top: 6mm;
            padding: 5mm 6mm;
            background: #ff6b9d;
            color: #1a1a3a;
            mix-blend-mode: multiply;
        }
        .callout-k {
            font-size: 8pt;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
        }
        .callout-v {
            margin-top: 2mm;
            font-size: 10.5pt;
            line-height: 1.55;
            font-weight: 500;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4mm;
        }
        .info-card {
            padding: 5mm 6mm;
            border: 2px solid #1a1a3a;
            background: rgba(245,237,224,.6);
        }
        .info-k {
            font-size: 7.5pt;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: #3b5bdb;
        }
        .info-v {
            margin-top: 1.5mm;
            font-size: 10pt;
            font-weight: 500;
            color: #1a1a3a;
            line-height: 1.5;
        }

        .cat-wrap {
            margin-top: 6mm;
            padding: 5mm 6mm;
            border: 2px solid #1a1a3a;
            background: rgba(245,237,224,.6);
        }
        .cat-k {
            font-size: 7.5pt;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: #ff6b9d;
            margin-bottom: 3mm;
        }
        .cat-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 2mm;
        }
        .cat-tag {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 8.5pt;
            padding: 1.2mm 3mm;
            background: #1a1a3a;
            color: #ff6b9d;
            font-weight: 500;
        }

        .footer-strip {
            position: absolute;
            bottom: 8mm;
            left: 16mm;
            right: 16mm;
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: rgba(26,26,58,.55);
            font-weight: 700;
            z-index: 3;
        }
    </style>
</head>
<body>

{{-- ============ COVER ============ --}}
<div class="page">
    <div class="cover-shape cover-pink"></div>
    <div class="cover-shape cover-blue"></div>

    <svg class="grain" width="100%" height="100%">
        <defs>
            <pattern id="grain-cover" width="3" height="3" patternUnits="userSpaceOnUse">
                <circle cx="1" cy="1" r=".45" fill="#1a1a3a"></circle>
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#grain-cover)"></rect>
    </svg>

    <div class="cover-inner">
        <div class="cover-top">
            @php
                $edParts = explode('-', $ropa['number'] ?? '');
                $editionTag = end($edParts) ?: '001';
            @endphp
            <div class="micro">RISO / ed. {{ $editionTag }}</div>
            <div class="micro micro-pink">2-color print</div>
        </div>

        <div class="cover-headline">
            <div class="cover-eyebrow">Record of Processing</div>
            <h1 class="cover-title">
                data.<br>
                <span class="pink">activities.</span>
            </h1>
            <div class="cover-desc">
                {{ $ropa['description'] ?? '' }}
            </div>
        </div>

        <div class="cover-band">
            <div>
                <div class="band-k">No</div>
                <div class="band-v">{{ $ropa['number'] ?? '-' }}</div>
            </div>
            <div>
                <div class="band-k">Div</div>
                <div class="band-v">{{ \Illuminate\Support\Str::limit($ropa['division'] ?? '-', 18, '') }}</div>
            </div>
            <div>
                <div class="band-k">Date</div>
                <div class="band-v">{{ $ropa['date'] ?? ($today ?? '') }}</div>
            </div>
            <div>
                <div class="band-k">Org</div>
                <div class="band-v">{{ \Illuminate\Support\Str::limit($ropa['org'] ?? ($orgName ?? '-'), 18, '') }}</div>
            </div>
        </div>
    </div>
</div>

{{-- ============ CONTENT ============ --}}
<div class="page">
    <div class="content-shape-tr"></div>
    <div class="content-shape-bl"></div>

    <svg class="grain grain-soft" width="100%" height="100%">
        <defs>
            <pattern id="grain-content" width="3" height="3" patternUnits="userSpaceOnUse">
                <circle cx="1" cy="1" r=".4" fill="#1a1a3a"></circle>
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#grain-content)"></rect>
    </svg>

    <div class="content-inner">
        {{-- 01 Deskripsi --}}
        <div class="sec-head">
            <span class="sec-num pink">01</span>
            <h2 class="sec-title">Deskripsi.</h2>
        </div>

        <div class="navy-card">
            <div class="navy-row">
                <div class="navy-k">Nomor</div>
                <div class="navy-v">{{ $ropa['number'] ?? '-' }}</div>
            </div>
            <div class="navy-row">
                <div class="navy-k">Nama</div>
                <div class="navy-v">{{ $ropa['name'] ?? '-' }}</div>
            </div>
            <div class="navy-row">
                <div class="navy-k">Divisi</div>
                <div class="navy-v">{{ $ropa['division'] ?? '-' }}</div>
            </div>
            <div class="navy-row">
                <div class="navy-k">Unit</div>
                <div class="navy-v">{{ $ropa['unit'] ?? '-' }}</div>
            </div>
            <div class="navy-row">
                <div class="navy-k">Entitas</div>
                <div class="navy-v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</div>
            </div>
        </div>

        <div class="callout-pink">
            <div class="callout-k">Deskripsi Singkat</div>
            <div class="callout-v">{{ $ropa['description'] ?? '-' }}</div>
        </div>

        {{-- 02 Informasi --}}
        <div class="sec-head" style="margin-top: 12mm;">
            <span class="sec-num blue">02</span>
            <h2 class="sec-title">Informasi.</h2>
        </div>

        <div class="info-grid">
            <div class="info-card">
                <div class="info-k">Tujuan</div>
                <div class="info-v">{{ $ropa['purpose'] ?? '-' }}</div>
            </div>
            <div class="info-card">
                <div class="info-k">Dasar</div>
                <div class="info-v">{{ $ropa['legal_basis'] ?? '-' }}</div>
            </div>
            <div class="info-card">
                <div class="info-k">Aktivitas</div>
                <div class="info-v">{{ $ropa['activity'] ?? '-' }}</div>
            </div>
            <div class="info-card">
                <div class="info-k">Retensi</div>
                <div class="info-v">{{ $ropa['retention'] ?? '-' }}</div>
            </div>
        </div>

        @if(!empty($ropa['categories']) && is_array($ropa['categories']))
        <div class="cat-wrap">
            <div class="cat-k">Kategori Pemrosesan</div>
            <div class="cat-tags">
                @foreach($ropa['categories'] as $cat)
                    <span class="cat-tag">{{ $cat }}</span>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <div class="footer-strip">
        <span>{{ $ropa['org'] ?? ($orgName ?? '') }}</span>
        <span>{{ $ropa['number'] ?? '' }} &middot; p.02</span>
    </div>
</div>

</body>
</html>
