<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>NDA Tertanda Tangani · {{ $orgName }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: {{ $branding['primary_color'] ?? '#0f172a' }}; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #fff; max-width: 460px; width: 100%; border-radius: 12px; box-shadow: 0 20px 60px rgba(15,23,42,.08); padding: 32px; text-align: center; }
        .icon { color: #16a34a; font-size: 56px; line-height: 1; margin-bottom: 14px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #475569; line-height: 1.55; }
        .meta { display: inline-block; background: #f1f5f9; padding: 6px 12px; border-radius: 6px; font-family: ui-monospace, monospace; font-size: 13px; margin-top: 12px; }
        .footer-note { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 18px; }
    </style>
</head>
<body>
<div>
    <div class="card">
        <div class="icon">✓</div>
        <h1>NDA Berhasil Ditandatangani</h1>
        <p>Terima kasih. NDA Anda telah dicatat sebagai bukti elektronik.</p>
        <p>DPO {{ $orgName }} akan memproses permintaan Anda dan menghubungi Anda paling lambat dalam 72 jam.</p>
        <div class="meta">{{ $dsr->request_id }}</div>
    </div>
    <div class="footer-note">Powered by Privasimu Nexus · UU PDP compliance</div>
</div>
</body>
</html>
