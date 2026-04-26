<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title ?? 'Verifikasi Permintaan' }} · {{ $appName ?? 'Privasimu' }}</title>
    <style>
        :root {
            --primary: {{ $branding['primary_color'] ?? '#0f172a' }};
            --accent: {{ $branding['accent_color'] ?? '#0ea5e9' }};
            --bg: #f8fafc;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--primary); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #fff; max-width: 460px; width: 100%; border-radius: 12px; box-shadow: 0 20px 60px rgba(15,23,42,.08); overflow: hidden; }
        .stripe { height: 6px; background: var(--accent); }
        .body { padding: 32px; text-align: center; }
        .icon { font-size: 56px; line-height: 1; margin-bottom: 14px; }
        .icon.ok { color: #16a34a; }
        .icon.warn { color: #ea580c; }
        .icon.err { color: #dc2626; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #475569; line-height: 1.55; margin: 6px 0; }
        .meta { display: inline-block; background: #f1f5f9; padding: 6px 12px; border-radius: 6px; font-family: ui-monospace, SF Mono, Menlo, monospace; font-size: 13px; margin-top: 12px; }
        .deadline { color: var(--accent); font-weight: 600; }
        .next { margin-top: 22px; padding-top: 18px; border-top: 1px solid #e2e8f0; font-size: 14px; color: #64748b; }
        .footer { text-align: center; margin-top: 18px; font-size: 12px; color: #94a3b8; }
        .nda-prompt { background: #fef3c7; border: 1px solid #fbbf24; padding: 14px; border-radius: 8px; margin-top: 18px; text-align: left; }
        .nda-prompt a { color: var(--accent); font-weight: 600; text-decoration: none; }
        .nda-prompt a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="stripe"></div>
    <div class="body">
        @if($status === 'verified')
            <div class="icon ok">✓</div>
            <h1>Identitas Terverifikasi</h1>
            <p>Permintaan Anda telah diterima dan akan diproses oleh tim DPO {{ $appName }}.</p>
            @if(!empty($requestId))
                <div class="meta">{{ $requestId }}</div>
            @endif
            @if(!empty($deadlineAt))
                <p class="next">DPO akan merespons paling lambat <span class="deadline">{{ $deadlineAt }}</span> (72 jam sesuai UU PDP Pasal 32).</p>
            @endif

            @if(!empty($ndaRequired))
                <div class="nda-prompt">
                    <strong>⚠ NDA Diperlukan</strong><br>
                    Karena Anda meminta akses ke data pribadi, Anda perlu menandatangani
                    NDA (Non-Disclosure Agreement) sebelum DPO bisa memproses permintaan.
                    <br><br>
                    <a href="{{ $ndaUrl }}">→ Tinjau &amp; Tanda Tangan NDA</a>
                </div>
            @endif

        @elseif($status === 'expired')
            <div class="icon warn">⌛</div>
            <h1>Link Kedaluwarsa</h1>
            <p>Link verifikasi sudah lewat 24 jam. Silakan submit ulang permintaan Anda.</p>

        @else
            <div class="icon err">✗</div>
            <h1>Link Tidak Valid</h1>
            <p>{{ $message ?? 'Link verifikasi tidak ditemukan atau sudah pernah dipakai.' }}</p>
        @endif
    </div>
</div>
<div class="footer">Powered by Privasimu Nexus · UU PDP compliance</div>
</body>
</html>
