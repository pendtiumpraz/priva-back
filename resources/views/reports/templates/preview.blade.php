<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Template Preview</title>
    @include('reports._doc_styles', ['config' => $config])
</head>
<body>

    @if(($config['watermark_enabled'] ?? false) && $config['watermark_text'])
        <div class="watermark">{{ $config['watermark_text'] }}</div>
    @endif

    @if($config['header_enabled'] ?? true)
    <div class="header">
        <div class="header-left">
            @if(($config['header_show_logo'] ?? true) && $orgLogoUrl)
                <img src="{{ $orgLogoUrl }}" alt="Logo">
            @else
                <span style="font-size: 13pt; font-weight: 800;">{{ $orgName }}</span>
            @endif
        </div>
        <div class="header-right">
            TEMPLATE PREVIEW · {{ $today }}
            @if(!empty($config['header_text']))<br>{!! $config['header_text'] !!}@endif
        </div>
    </div>
    @endif

    @if($config['footer_enabled'] ?? true)
    <div class="footer">
        <div class="footer-left">{{ $orgName }} · {{ $orgWebsite }}@if(!empty($config['footer_text'])) · {!! $config['footer_text'] !!}@endif</div>
        <div class="footer-right">
            <script type="text/php">
                if (isset($pdf)) {
                    $pdf->page_script('$font = $fontMetrics->getFont("DejaVu Sans", "normal"); $pdf->text(480, 820, "Hal " . $PAGE_NUM . " dari " . $PAGE_COUNT, $font, 8, [150,150,150]);');
                }
            </script>
        </div>
    </div>
    @endif

    <h1 style="text-align: center;">Preview Dokumen</h1>
    <p class="muted" style="text-align: center;">Sample layout dengan styling template saat ini.</p>

    <h2>Section Heading</h2>
    <p>Paragraf normal. Ini adalah paragraf dengan body text untuk demonstrasi ukuran font dan line-height. Brown fox jumps over the lazy dog. Ujian karakter: áéíóú ñ çü.</p>
    <p class="muted">Teks muted untuk caption/note/small details.</p>

    <div class="callout">
        <strong>Callout Box</strong> — Box informasi dengan accent color dari template. Pake untuk warning, info, atau pesan penting.
    </div>

    <h2>Tabel Sample ({{ $config['table_style'] ?? 'clean' }})</h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width: 40px;">No</th>
                <th>Item</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 120px;">Tanggal</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>1</td><td>Isolasi sistem terdampak</td><td><span class="badge b-green">DONE</span></td><td>15 Apr 2026</td></tr>
            <tr><td>2</td><td>Preserve forensic evidence</td><td><span class="badge b-green">DONE</span></td><td>15 Apr 2026</td></tr>
            <tr><td>3</td><td>Identifikasi root cause</td><td><span class="badge b-amber">PENDING</span></td><td>—</td></tr>
            <tr><td>4</td><td>Notifikasi KOMDIGI 3×24 jam</td><td><span class="badge b-red">URGENT</span></td><td>Due 18 Apr</td></tr>
            <tr><td>5</td><td>Remediation plan</td><td><span class="badge b-blue">IN REVIEW</span></td><td>—</td></tr>
        </tbody>
    </table>

    <h2>Signature Block</h2>
    <div class="sig-block">
        <p>Hormat kami,</p>
        <div class="sig-line"></div>
        <p><strong>Nama DPO</strong><br>Data Protection Officer<br>{{ $orgName }}</p>
    </div>

    <p class="muted" style="margin-top: 30px; font-size: 8pt;">
        Preview digenerate pada {{ $generatedAt }} oleh {{ $generatedBy }}.
    </p>

</body>
</html>
