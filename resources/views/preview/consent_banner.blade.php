@php
    $loc = $locale ?? 'id';
    $T = [
        'id' => [
            'htmlLang' => 'id',
            'title' => 'Preview — Cookie Consent Banner',
            'h1' => '🍪 Live Preview — Cookie Consent Banner',
            'sub' => 'Halaman demo ini menampilkan cookie banner Anda persis seperti yang akan dilihat visitor di klien web. Klik "Reset" untuk re-trigger banner.',
            'badge' => 'PREVIEW MODE',
            'reset' => '↻ Reset & Re-trigger',
            'pageH2' => 'Sample Website',
            'lead' => 'Ini adalah halaman demo dummy untuk testing widget cookie consent. Banner akan muncul otomatis di pojok bawah-kanan saat halaman load. Klik "Pengaturan Cookie" untuk lihat modal "Pusat Preferensi Privasi" dengan accordion per kategori.',
            'mainTitle' => 'Konten Utama',
            'mainBody' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Cras venenatis euismod malesuada. Nullam ac erat ante. Nunc nibh nisl, semper sed pellentesque eu, sodales ac dui. Maecenas malesuada ac justo nec auctor.',
            'sideTitle' => 'Sidebar',
            'sideBody' => 'Sed dignissim purus eu odio. Aliquam erat volutpat. Pellentesque vel ipsum at lectus.',
            'metaTitle' => '📋 Settings yang dipakai widget ini:',
            'metaCol' => 'Collection ID',
            'metaApiBase' => 'API base',
            'metaFooter' => 'Customisasi banner_text, modal_intro_text, branding, bahasa semua di-fetch real-time dari config server. Edit di dashboard collection point → reload preview ini untuk lihat perubahan.',
            'localeLabel' => 'Bahasa Widget',
        ],
        'en' => [
            'htmlLang' => 'en',
            'title' => 'Preview — Cookie Consent Banner',
            'h1' => '🍪 Live Preview — Cookie Consent Banner',
            'sub' => 'This demo page renders your cookie banner exactly as visitors will see it on the client website. Click "Reset" to re-trigger the banner.',
            'badge' => 'PREVIEW MODE',
            'reset' => '↻ Reset & Re-trigger',
            'pageH2' => 'Sample Website',
            'lead' => 'This is a dummy demo page for testing the cookie consent widget. The banner appears automatically at the bottom-right on page load. Click "Cookie Settings" to open the "Privacy Preference Center" modal with per-category accordion.',
            'mainTitle' => 'Main Content',
            'mainBody' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Cras venenatis euismod malesuada. Nullam ac erat ante. Nunc nibh nisl, semper sed pellentesque eu, sodales ac dui. Maecenas malesuada ac justo nec auctor.',
            'sideTitle' => 'Sidebar',
            'sideBody' => 'Sed dignissim purus eu odio. Aliquam erat volutpat. Pellentesque vel ipsum at lectus.',
            'metaTitle' => '📋 Widget settings in use:',
            'metaCol' => 'Collection ID',
            'metaApiBase' => 'API base',
            'metaFooter' => 'banner_text, modal_intro_text, branding, language are all fetched real-time from server config. Edit on the collection point dashboard then reload this preview to see changes.',
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
        .preview-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .preview-actions button { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.3); padding: 6px 14px; border-radius: 6px; font-size: 12px; cursor: pointer; font-weight: 600; }
        .preview-actions button:hover { background: rgba(255,255,255,.25); }
        .demo-page { background: #fff; border-radius: 12px; padding: 32px 28px; min-height: 60vh; box-shadow: 0 4px 24px rgba(15,23,42,.06); }
        .demo-page h2 { font-size: 24px; margin-bottom: 8px; color: #0f172a; }
        .demo-page p.lead { font-size: 14px; color: #64748b; margin-bottom: 24px; line-height: 1.6; }
        .demo-page .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .demo-page article { background: #f8fafc; padding: 16px 18px; border-radius: 8px; }
        .demo-page article h3 { font-size: 14px; margin-bottom: 6px; }
        .demo-page article p { font-size: 12px; color: #64748b; line-height: 1.6; }
        .demo-page .meta { background: #fef3c7; padding: 10px 12px; border-radius: 6px; margin-top: 16px; font-size: 11px; color: #78350f; }
        @media (max-width:680px) { .demo-page .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="demo-shell">
    <div class="preview-header">
        <div>
            <h1>{{ $t['h1'] }}</h1>
            <p>{{ $t['sub'] }}</p>
        </div>
        <div class="preview-actions">
            <span class="badge">{{ $t['badge'] }}</span>
            <span class="lang-pill">{{ $t['localeLabel'] }}: {{ strtoupper($loc) }}</span>
            <button type="button" onclick="if(window.PrivasimuConsent){window.PrivasimuConsent.reset();window.PrivasimuConsent.show();}">{{ $t['reset'] }}</button>
        </div>
    </div>

    <div class="demo-page">
        <h2>{{ $t['pageH2'] }}</h2>
        <p class="lead">{{ $t['lead'] }}</p>

        <div class="grid">
            <article>
                <h3>{{ $t['mainTitle'] }}</h3>
                <p>{{ $t['mainBody'] }}</p>
            </article>
            <article>
                <h3>{{ $t['sideTitle'] }}</h3>
                <p>{{ $t['sideBody'] }}</p>
            </article>
        </div>

        <div class="meta">
            <strong>{{ $t['metaTitle'] }}</strong><br>
            {{ $t['metaCol'] }}: <code>{{ $collection_id }}</code> · {{ $t['metaApiBase'] }}: <code>{{ $api_base }}</code>
            <br>{{ $t['metaFooter'] }}
        </div>
    </div>
</div>

{{-- Pass data-locale eksplisit supaya widget banner langsung pakai bahasa preview tanpa nunggu config fetch --}}
<script
    src="{{ $widget_url }}"
    data-collection-id="{{ $collection_id }}"
    data-api-base="{{ $api_base }}"
    data-locale="{{ $loc }}"
    async
></script>
</body>
</html>
