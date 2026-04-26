@php
    $loc = $locale ?? 'id';
    $T = [
        'id' => [
            'htmlLang' => 'id',
            'title' => 'Preview — DSR Widget',
            'h1' => '🔒 Live Preview — DSR Widget',
            'sub' => 'Demo halaman seperti web klien Anda. Tombol "Permintaan Privasi" muncul otomatis di pojok bawah-kanan. Klik untuk lihat form submission DSR.',
            'badge' => 'PREVIEW MODE',
            'pageH2' => 'Sample Website',
            'lead' => 'Halaman demo dummy untuk testing widget DSR. End-user (subject) akan klik tombol floating "🔒 Permintaan Privasi" untuk submit permintaan hak data (Akses / Hapus / Koreksi / dll).',
            'arrow' => '👉 Lihat tombol "🔒 Permintaan Privasi" di pojok kanan bawah halaman ini',
            'metaTitle' => '📋 Settings yang dipakai widget ini:',
            'metaApiBase' => 'API base',
            'metaEmbed' => 'Embed Token',
            'metaFooter' => 'Branding (warna, logo) + captcha config + bahasa di-fetch real-time dari DSR App settings. Edit di dashboard /dsr-apps → reload preview ini untuk lihat perubahan.',
            'localeLabel' => 'Bahasa Widget',
        ],
        'en' => [
            'htmlLang' => 'en',
            'title' => 'Preview — DSR Widget',
            'h1' => '🔒 Live Preview — DSR Widget',
            'sub' => 'Demo page mimicking your client website. The "Privacy Request" button appears automatically at bottom-right. Click to view the DSR submission form.',
            'badge' => 'PREVIEW MODE',
            'pageH2' => 'Sample Website',
            'lead' => 'Dummy demo page for testing the DSR widget. End-users (subjects) will click the floating "🔒 Privacy Request" button to submit data rights requests (Access / Delete / Rectify / etc).',
            'arrow' => '👉 Look for the "🔒 Privacy Request" button at the bottom-right of this page',
            'metaTitle' => '📋 Widget settings in use:',
            'metaApiBase' => 'API base',
            'metaEmbed' => 'Embed Token',
            'metaFooter' => 'Branding (colors, logo) + captcha config + language are fetched real-time from DSR App settings. Edit at /dsr-apps then reload this preview to see changes.',
            'localeLabel' => 'Widget Language',
        ],
    ];
    $t = $T[$loc] ?? $T['id'];
@endphp
<!DOCTYPE html>
<html lang="{{ $t['htmlLang'] }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $t['title'] }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #0f172a; min-height: 100vh; }
        .demo-shell { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        .preview-header { background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 16px 22px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .preview-header h1 { font-size: 18px; }
        .preview-header .badge { background: #10b981; color: #fff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; }
        .preview-header p { opacity: 0.85; font-size: 12px; margin-top: 2px; }
        .preview-header .lang-pill { background: #6366f1; color: #fff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; margin-left: 6px; }
        .demo-page { background: #fff; border-radius: 12px; padding: 32px 28px; min-height: 60vh; box-shadow: 0 4px 24px rgba(15,23,42,.06); }
        .demo-page h2 { font-size: 24px; margin-bottom: 8px; }
        .demo-page p.lead { font-size: 14px; color: #64748b; margin-bottom: 24px; line-height: 1.6; }
        .demo-page .meta { background: #fef3c7; padding: 10px 12px; border-radius: 6px; margin-top: 16px; font-size: 11px; color: #78350f; }
        .demo-page .arrow { display: inline-block; margin-top: 16px; padding: 12px 18px; background: #fef3c7; border-radius: 8px; font-size: 13px; color: #78350f; font-weight: 600; }
    </style>
</head>
<body>
<div class="demo-shell">
    <div class="preview-header">
        <div>
            <h1>{{ $t['h1'] }}</h1>
            <p>{{ $t['sub'] }}</p>
        </div>
        <div>
            <span class="badge">{{ $t['badge'] }}</span>
            <span class="lang-pill">{{ $t['localeLabel'] }}: {{ strtoupper($loc) }}</span>
        </div>
    </div>

    <div class="demo-page">
        <h2>{{ $t['pageH2'] }}</h2>
        <p class="lead">{{ $t['lead'] }}</p>

        <div class="arrow">{{ $t['arrow'] }}</div>

        <div class="meta">
            <strong>{{ $t['metaTitle'] }}</strong><br>
            {{ $t['metaEmbed'] }}: <code>{{ Str::limit($embed_token, 16) }}…</code> · {{ $t['metaApiBase'] }}: <code>{{ $api_base }}</code>
            <br>{{ $t['metaFooter'] }}
        </div>
    </div>
</div>

{{-- Pass data-locale eksplisit supaya widget langsung pakai bahasa preview tanpa nunggu config fetch --}}
<script
    src="{{ $widget_url }}"
    data-embed-token="{{ $embed_token }}"
    data-api-base="{{ $api_base }}"
    data-locale="{{ $loc }}"
    async
></script>
</body>
</html>
