<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>ROPA &mdash; {{ $ropa['name'] ?? '' }}</title>
    {{-- Browsershot waits networkidle, so Google Fonts are guaranteed loaded --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #e0fcff;
            -webkit-font-smoothing: antialiased;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ---------- Shared ---------- */
        .page {
            position: relative;
            width: 21cm;
            height: 29.7cm;
            overflow: hidden;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        .cover {
            background: linear-gradient(140deg, #021b1f 0%, #032c33 50%, #021b1f 100%);
            color: #e0fcff;
        }
        .content {
            background: linear-gradient(140deg, #021b1f 0%, #032c33 100%);
            color: #e0fcff;
        }

        /* Aurora blobs -- filter: blur() Browsershot supports, dompdf cannot */
        .aurora {
            position: absolute;
            border-radius: 50%;
            filter: blur(40px);
        }
        .aurora-cyan-tr {
            top: -36mm;
            right: -36mm;
            width: 140mm;
            height: 140mm;
            background: radial-gradient(circle, rgba(0,217,255,.40), transparent 70%);
        }
        .aurora-green-bl {
            bottom: -40mm;
            left: -28mm;
            width: 130mm;
            height: 130mm;
            background: radial-gradient(circle, rgba(0,255,153,.40), transparent 70%);
        }
        .aurora-small-tr {
            top: -28mm;
            right: -28mm;
            width: 100mm;
            height: 100mm;
            background: radial-gradient(circle, rgba(0,217,255,.27), transparent 70%);
            filter: blur(30px);
        }

        /* Grid overlay */
        .grid-overlay {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(0,217,255,.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,217,255,.06) 1px, transparent 1px);
            background-size: 30px 30px;
            pointer-events: none;
        }
        .grid-overlay.softer {
            background-image:
                linear-gradient(rgba(0,217,255,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,217,255,.05) 1px, transparent 1px);
        }

        /* ---------- COVER ---------- */
        .cover-inner {
            position: relative;
            z-index: 2;
            padding: 18mm 18mm;
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
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 3mm;
            padding: 2mm 4mm;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(0,217,255,.30);
            border-radius: 999px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .pill-dot {
            width: 2.4mm;
            height: 2.4mm;
            border-radius: 50%;
            background: #00d9ff;
            box-shadow: 0 0 12px #00d9ff;
        }
        .pill-text {
            font-size: 8pt;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: #00d9ff;
            font-weight: 600;
        }
        .ref {
            font-size: 8pt;
            letter-spacing: .2em;
            color: rgba(224,252,255,.5);
        }

        .cover-head { margin-top: 4mm; }
        .eyebrow {
            font-size: 8pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: #00d9ff;
            font-weight: 600;
        }
        .cover-title {
            font-family: 'Inter', sans-serif;
            font-size: 100pt;
            font-weight: 800;
            line-height: .9;
            letter-spacing: -.05em;
            margin: 4mm 0 0;
            color: #e0fcff;
        }
        /* Gradient text-clip -- Browsershot renders this, dompdf falls back to solid */
        .cover-title .grad {
            background: linear-gradient(90deg, #00d9ff, #00ff99);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
        }

        .glass {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(0,217,255,.20);
            border-radius: 12px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .glass-accent {
            background: rgba(0,217,255,.08);
            border-color: rgba(0,217,255,.40);
        }

        .cover-desc {
            margin-top: 8mm;
            padding: 5mm 6mm;
            max-width: 160mm;
            font-size: 11pt;
            line-height: 1.55;
            color: #e0fcff;
        }

        .cover-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 3mm;
        }
        .meta-card {
            padding: 4mm 5mm;
        }
        .meta-k {
            font-size: 7pt;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: #00d9ff;
            font-weight: 600;
        }
        .meta-v {
            margin-top: 2mm;
            font-size: 10.5pt;
            font-weight: 600;
            color: #e0fcff;
        }

        /* ---------- CONTENT ---------- */
        .content-inner {
            position: relative;
            z-index: 2;
            padding: 14mm 16mm;
        }

        .sec-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 5mm;
            border-bottom: 1px solid rgba(0,217,255,.20);
        }
        .sec-head {
            display: flex;
            align-items: baseline;
            gap: 4mm;
        }
        .sec-num {
            font-size: 36pt;
            font-weight: 800;
            letter-spacing: -.04em;
        }
        .sec-num.cyan {
            color: #00d9ff;
            text-shadow: 0 0 24px rgba(0,217,255,.45);
        }
        .sec-num.green {
            color: #00ff99;
            text-shadow: 0 0 24px rgba(0,255,153,.45);
        }
        .sec-title {
            margin: 0;
            font-size: 18pt;
            font-weight: 700;
            letter-spacing: -.02em;
            color: #e0fcff;
        }
        .sec-page {
            font-size: 7.5pt;
            letter-spacing: .24em;
            color: rgba(224,252,255,.5);
            text-transform: uppercase;
        }

        .grid-2 {
            margin-top: 6mm;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3mm;
        }
        .grid-2-full {
            margin-top: 3mm;
        }
        .glass-card {
            padding: 4mm 5mm;
        }
        .glass-k {
            font-size: 7pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: #00d9ff;
            font-weight: 700;
        }
        .glass-k.green { color: #00ff99; }
        .glass-v {
            margin-top: 2mm;
            font-size: 10.5pt;
            font-weight: 500;
            color: #e0fcff;
            line-height: 1.5;
        }
        .glass-v.lg {
            font-size: 13pt;
            font-weight: 600;
        }
        .glass-v.muted {
            color: rgba(224,252,255,.85);
            font-size: 10pt;
            line-height: 1.55;
        }

        .sec-spacer { margin-top: 10mm; }

        .cat-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 2mm;
        }
        .cat-tag {
            padding: 1mm 3mm;
            border: 1px solid rgba(0,255,153,.40);
            color: #00ff99;
            font-size: 8.5pt;
            border-radius: 6px;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-weight: 500;
        }

        .footer-strip {
            position: absolute;
            bottom: 10mm;
            left: 16mm;
            right: 16mm;
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: rgba(224,252,255,.45);
            z-index: 3;
        }
    </style>
</head>
<body>

{{-- ============ COVER ============ --}}
<div class="page cover">
    <div class="aurora aurora-cyan-tr"></div>
    <div class="aurora aurora-green-bl"></div>
    <div class="grid-overlay"></div>

    <div class="cover-inner">
        <div class="cover-top">
            <div class="pill">
                <span class="pill-dot"></span>
                <span class="pill-text">ROPA &middot; live</span>
            </div>
            <div class="ref">{{ $ropa['number'] ?? '' }}</div>
        </div>

        <div class="cover-head">
            <div class="eyebrow">Record of Processing</div>
            <h1 class="cover-title">
                data<br>
                <span class="grad">integrity.</span>
            </h1>

            <div class="glass cover-desc">
                {{ $ropa['description'] ?? '' }}
            </div>
        </div>

        <div class="cover-meta-grid">
            <div class="glass meta-card">
                <div class="meta-k">Doc</div>
                <div class="meta-v">{{ $ropa['number'] ?? '-' }}</div>
            </div>
            <div class="glass meta-card">
                <div class="meta-k">Effective</div>
                <div class="meta-v">{{ $ropa['date'] ?? ($today ?? '-') }}</div>
            </div>
            <div class="glass meta-card">
                <div class="meta-k">Division</div>
                <div class="meta-v">{{ $ropa['division'] ?? '-' }}</div>
            </div>
        </div>
    </div>
</div>

{{-- ============ CONTENT ============ --}}
<div class="page content">
    <div class="aurora aurora-small-tr"></div>
    <div class="grid-overlay softer"></div>

    <div class="content-inner">
        {{-- 01 Deskripsi --}}
        <div class="sec-bar">
            <div class="sec-head">
                <span class="sec-num cyan">01</span>
                <h2 class="sec-title">Deskripsi</h2>
            </div>
            <div class="sec-page">p.02</div>
        </div>

        <div class="grid-2">
            <div class="glass glass-accent glass-card">
                <div class="glass-k">Nomor</div>
                <div class="glass-v">{{ $ropa['number'] ?? '-' }}</div>
            </div>
            <div class="glass glass-card">
                <div class="glass-k">Divisi</div>
                <div class="glass-v">{{ $ropa['division'] ?? '-' }}</div>
            </div>
            <div class="glass glass-card">
                <div class="glass-k">Unit</div>
                <div class="glass-v">{{ $ropa['unit'] ?? '-' }}</div>
            </div>
            <div class="glass glass-accent glass-card">
                <div class="glass-k">Entitas</div>
                <div class="glass-v">{{ $ropa['org'] ?? ($orgName ?? '-') }}</div>
            </div>
        </div>

        <div class="grid-2-full">
            <div class="glass glass-card">
                <div class="glass-k">Nama Pemrosesan</div>
                <div class="glass-v lg">{{ $ropa['name'] ?? '-' }}</div>
                <div class="glass-k" style="margin-top: 5mm;">Deskripsi Singkat</div>
                <div class="glass-v muted">{{ $ropa['description'] ?? '-' }}</div>
            </div>
        </div>

        {{-- 02 Informasi --}}
        <div class="sec-bar sec-spacer">
            <div class="sec-head">
                <span class="sec-num green">02</span>
                <h2 class="sec-title">Informasi</h2>
            </div>
            <div class="sec-page">p.02</div>
        </div>

        <div class="grid-2">
            <div class="glass glass-card">
                <div class="glass-k green">Tujuan</div>
                <div class="glass-v">{{ $ropa['purpose'] ?? '-' }}</div>
            </div>
            <div class="glass glass-card">
                <div class="glass-k green">Dasar</div>
                <div class="glass-v">{{ $ropa['legal_basis'] ?? '-' }}</div>
            </div>
        </div>

        @if(!empty($ropa['categories']) && is_array($ropa['categories']))
        <div class="grid-2-full">
            <div class="glass glass-card">
                <div class="glass-k green" style="margin-bottom: 3mm;">Kategori Pemrosesan</div>
                <div class="cat-tags">
                    @foreach($ropa['categories'] as $cat)
                        <span class="cat-tag">{{ $cat }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        @if(!empty($ropa['activity']) || !empty($ropa['retention']))
        <div class="grid-2" style="margin-top: 3mm;">
            @if(!empty($ropa['activity']))
            <div class="glass glass-card">
                <div class="glass-k green">Aktivitas</div>
                <div class="glass-v">{{ $ropa['activity'] }}</div>
            </div>
            @endif
            @if(!empty($ropa['retention']))
            <div class="glass glass-card">
                <div class="glass-k green">Retensi</div>
                <div class="glass-v">{{ $ropa['retention'] }}</div>
            </div>
            @endif
        </div>
        @endif
    </div>

    <div class="footer-strip">
        <span>{{ $ropa['org'] ?? ($orgName ?? '') }}</span>
        <span>{{ $ropa['number'] ?? '' }} &middot; cyber.glass</span>
    </div>
</div>

</body>
</html>
