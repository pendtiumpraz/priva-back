<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Preview — DSR Widget</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #0f172a; min-height: 100vh; }
        .demo-shell { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        .preview-header { background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 16px 22px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .preview-header h1 { font-size: 18px; }
        .preview-header .badge { background: #10b981; color: #fff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; }
        .preview-header p { opacity: 0.85; font-size: 12px; margin-top: 2px; }
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
            <h1>🔒 Live Preview — DSR Widget</h1>
            <p>Demo halaman seperti web klien Anda. Tombol "Privacy Request" muncul otomatis di pojok bawah-kanan. Klik untuk lihat form submission DSR.</p>
        </div>
        <span class="badge">PREVIEW MODE</span>
    </div>

    <div class="demo-page">
        <h2>Sample Website</h2>
        <p class="lead">Halaman demo dummy untuk testing widget DSR. End-user (subject) akan klik tombol floating "🔒 Privacy Request" untuk submit permintaan hak data (Akses / Hapus / Korreksi / dll).</p>

        <div class="arrow">👉 Lihat tombol "🔒 Privacy Request" di pojok kanan bawah halaman ini</div>

        <div class="meta">
            <strong>📋 Settings yang dipakai widget ini:</strong><br>
            Embed Token: <code>{{ Str::limit($embed_token, 16) }}…</code> · API base: <code>{{ $api_base }}</code>
            <br>Branding (warna, logo) + captcha config di-fetch real-time dari DSR App settings.
            Edit di dashboard /dsr-apps → reload preview ini untuk lihat perubahan.
        </div>
    </div>
</div>

<!-- The actual DSR widget — same script klien akan paste -->
<script
    src="{{ $widget_url }}"
    data-embed-token="{{ $embed_token }}"
    data-api-base="{{ $api_base }}"
    async
></script>
</body>
</html>
