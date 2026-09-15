<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Persetujuan Wali</title>
    <style>
        body { margin:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color:#0f172a; }
        .wrap { max-width:560px; margin:32px auto; padding:0 16px; }
        .card { background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(15,23,42,.08); overflow:hidden; }
        .head { background:#0f172a; color:#fff; padding:16px 24px; font-weight:700; font-size:14px; letter-spacing:.5px; }
        .body { padding:24px; }
        h1 { font-size:18px; margin:0 0 12px; }
        p, li { font-size:14px; line-height:1.6; color:#334155; }
        ul { padding-left:20px; margin:0 0 16px; }
        .statement { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-size:13px; color:#1e293b; margin:0 0 20px; }
        .btn { display:inline-block; background:#0ea5e9; color:#fff; border:0; padding:12px 28px; border-radius:6px; font-weight:700; font-size:14px; cursor:pointer; }
        .muted { font-size:12px; color:#64748b; }
        .ok { color:#15803d; } .err { color:#b91c1c; }
        .foot { background:#f8fafc; border-top:1px solid #e2e8f0; padding:12px 24px; text-align:center; font-size:11px; color:#94a3b8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">{{ $p['organization'] ?? 'Privasimu Nexus' }}</div>
        <div class="body">
            @if($state === 'done')
                <h1 class="ok">Persetujuan tercatat</h1>
                <p>Terima kasih. Persetujuan Anda sebagai {{ $p['relationship_label'] ?? 'wali' }} dari
                    <strong>{{ $p['subject_label'] ?? 'subjek data' }}</strong> telah dicatat pada
                    {{ now()->setTimezone('Asia/Jakarta')->format('d F Y H:i') }} WIB.</p>
                <p class="muted">Persetujuan ini dapat ditarik kembali kapan saja melalui {{ $p['organization'] ?? 'pengendali data' }}.</p>
            @elseif($state === 'error')
                <h1 class="err">Tautan tidak dapat diproses</h1>
                <p>{{ $pesan }}</p>
            @else
                <h1>Persetujuan Anda sebagai {{ $p['relationship_label'] ?? 'wali' }} diperlukan</h1>
                <p><strong>{{ $p['organization'] ?? 'Pengendali data' }}</strong>
                    @if(!empty($p['collection_point'])) (melalui {{ $p['collection_point'] }}) @endif
                    meminta persetujuan Anda untuk memproses data pribadi
                    <strong>{{ $p['subject_label'] ?? 'subjek data' }}</strong> untuk tujuan berikut:</p>
                <ul>
                    @forelse(($p['purposes'] ?? []) as $tujuan)
                        <li>{{ $tujuan }}</li>
                    @empty
                        <li>(tidak ada tujuan yang dipilih)</li>
                    @endforelse
                </ul>
                <div class="statement">{{ $p['statement'] }}</div>
                <form method="post" action="{{ $confirmUrl }}">
                    <button type="submit" class="btn">Saya menyetujui</button>
                </form>
                @if(!empty($p['expires_at']))
                    <p class="muted" style="margin-top:16px;">Tautan berlaku hingga
                        {{ \Illuminate\Support\Carbon::parse($p['expires_at'])->setTimezone('Asia/Jakarta')->format('d F Y H:i') }} WIB.</p>
                @endif
                <p class="muted">Jika Anda bukan {{ $p['relationship_label'] ?? 'wali' }} dari {{ $p['subject_label'] ?? 'subjek data' }}, tutup halaman ini. Tidak ada yang diproses tanpa persetujuan Anda.</p>
            @endif
        </div>
        <div class="foot">Powered by Privasimu Nexus · UU PDP No. 27/2022 · PP 33/2026 Pasal 38</div>
    </div>
</div>
</body>
</html>
