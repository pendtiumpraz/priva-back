<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>NDA Persetujuan · {{ $orgName }}</title>
    <style>
        :root { --primary: {{ $branding['primary_color'] ?? '#0f172a' }}; --accent: {{ $branding['accent_color'] ?? '#0ea5e9' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: var(--primary); padding: 24px 12px; }
        .wrap { max-width: 720px; margin: 0 auto; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 10px 40px rgba(15,23,42,.08); overflow: hidden; }
        .head { background: var(--primary); color: #fff; padding: 18px 24px; }
        .head h1 { margin: 0; font-size: 20px; }
        .head .sub { opacity: .8; font-size: 13px; }
        .body { padding: 24px; }
        .meta { display: grid; grid-template-columns: 140px 1fr; row-gap: 6px; column-gap: 12px; font-size: 14px; margin-bottom: 18px; padding-bottom: 18px; border-bottom: 1px solid #e2e8f0; }
        .meta .k { color: #64748b; }
        .meta .v { font-weight: 600; }
        .nda-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 18px 22px; border-radius: 8px; line-height: 1.7; font-size: 14px; white-space: pre-wrap; }
        .agree { margin: 22px 0 6px; display: flex; gap: 10px; align-items: flex-start; font-size: 14px; }
        .agree input { margin-top: 4px; transform: scale(1.2); }
        label.field { display: block; margin: 14px 0 6px; font-weight: 600; color: var(--primary); font-size: 13px; }
        input.text { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        .sig-input { font-family: 'Cedarville Cursive', 'Lucida Handwriting', cursive; font-size: 22px; }
        .actions { margin-top: 22px; display: flex; justify-content: flex-end; }
        button.submit { background: var(--accent); color: #fff; border: 0; padding: 12px 22px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 14px; }
        button.submit:disabled { opacity: .5; cursor: not-allowed; }
        .footer-note { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 14px; }
        .err { color: #dc2626; font-size: 13px; margin-top: 10px; display: none; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <h1>Non-Disclosure Agreement</h1>
            <div class="sub">Persetujuan kerahasiaan untuk permintaan akses data pribadi</div>
        </div>
        <div class="body">
            <div class="meta">
                <div class="k">Nomor Permintaan</div>
                <div class="v">{{ $dsr->request_id }}</div>
                <div class="k">Pengendali Data</div>
                <div class="v">{{ $orgName }}</div>
                <div class="k">Pemohon</div>
                <div class="v">{{ $dsr->requester_email }}</div>
                <div class="k">Tipe Permintaan</div>
                <div class="v">{{ ucfirst(str_replace('_', ' ', $dsr->request_type)) }}</div>
            </div>

            <div class="nda-box">{{ $ndaText }}</div>

            <form id="signForm" method="POST" action="{{ $signUrl }}">
                @csrf
                <label class="field" for="full_name">Nama Lengkap (sesuai KTP)</label>
                <input class="text" id="full_name" name="full_name" required maxlength="200" value="{{ $dsr->requester_name }}">

                <label class="field" for="typed_signature">Tanda Tangan Elektronik (ketik nama Anda)</label>
                <input class="text sig-input" id="typed_signature" name="typed_signature" required maxlength="200" placeholder="Ketik nama Anda di sini">

                <div class="agree">
                    <input type="checkbox" id="agree" name="agree" value="1" required>
                    <label for="agree">Saya membaca, memahami, dan menyetujui isi NDA di atas. Saya menyadari tanda tangan elektronik ini sah dan mengikat secara hukum.</label>
                </div>

                <div class="err" id="err"></div>

                <div class="actions">
                    <button type="submit" class="submit" id="submitBtn">Tanda Tangani</button>
                </div>
            </form>
        </div>
    </div>
    <div class="footer-note">
        Powered by Privasimu Nexus · IP &amp; perangkat Anda akan dicatat sebagai bukti tanda tangan
    </div>
</div>
<script>
    document.getElementById('signForm').addEventListener('submit', function (e) {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Memproses...';
    });
</script>
</body>
</html>
