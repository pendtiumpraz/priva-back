<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kini Anda memutuskan sendiri</title>
    <style>
        body { margin:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color:#0f172a; }
        .wrap { max-width:560px; margin:32px auto; padding:0 16px; }
        .card { background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(15,23,42,.08); overflow:hidden; }
        .head { background:#0f172a; color:#fff; padding:16px 24px; font-weight:700; font-size:14px; letter-spacing:.5px; }
        .body { padding:24px; }
        h1 { font-size:18px; margin:0 0 12px; }
        p, li { font-size:14px; line-height:1.6; color:#334155; }
        .point { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin:0 0 10px; }
        .point b { font-size:13px; color:#0f172a; }
        ul { padding-left:18px; margin:6px 0 0; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
        .btn { display:inline-block; border:0; padding:12px 24px; border-radius:6px; font-weight:700; font-size:14px; cursor:pointer; font-family:inherit; }
        .btn-ok { background:#0ea5e9; color:#fff; }
        .btn-no { background:#fff; color:#b91c1c; border:1px solid #fca5a5; }
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
            @if($state === 'confirmed')
                <h1 class="ok">Persetujuan dilanjutkan</h1>
                <p>Tercatat: Anda melanjutkan persetujuan yang ada, kini atas nama Anda sendiri.
                    Anda dapat menariknya kapan saja melalui {{ $p['organization'] ?? 'pengendali data' }}.</p>
            @elseif($state === 'withdrawn')
                <h1 class="ok">Persetujuan ditarik</h1>
                <p>Tercatat: seluruh persetujuan yang dulu diberikan wali Anda telah ditarik.
                    {{ $p['organization'] ?? 'Pengendali data' }} akan menghentikan pemrosesan yang bergantung padanya.</p>
            @elseif($state === 'error')
                <h1 class="err">Tautan tidak dapat diproses</h1>
                <p>{{ $pesan }}</p>
            @else
                <h1>Kini Anda memutuskan sendiri</h1>
                <p>Anda telah berusia 18 tahun. Kewenangan orang tua / wali Anda atas persetujuan ini telah
                    berakhir (PP 33/2026 Pasal 38 ayat 8). Persetujuan yang dulu mereka berikan
                    <strong>tetap berlaku</strong>, untuk:</p>
                @forelse(($p['points'] ?? []) as $titik)
                    <div class="point">
                        <b>{{ $titik['name'] ?? $titik['collection_id'] }}</b>
                        <ul>
                            @forelse(($titik['purposes'] ?? []) as $tujuan)
                                <li>{{ $tujuan }}</li>
                            @empty
                                <li>(tidak ada tujuan aktif)</li>
                            @endforelse
                        </ul>
                    </div>
                @empty
                    <p class="muted">(tidak ada consent yang tercatat atas nama Anda)</p>
                @endforelse
                <div class="actions">
                    <form method="post" action="{{ $confirmUrl }}"><button type="submit" class="btn btn-ok">Lanjutkan persetujuan</button></form>
                    <form method="post" action="{{ $withdrawUrl }}"><button type="submit" class="btn btn-no">Tarik semua persetujuan</button></form>
                </div>
                @if(!empty($p['expires_at']))
                    <p class="muted" style="margin-top:16px;">Tautan berlaku hingga
                        {{ \Illuminate\Support\Carbon::parse($p['expires_at'])->setTimezone('Asia/Jakarta')->format('d F Y H:i') }} WIB.</p>
                @endif
                <p class="muted">Tidak menanggapi = persetujuan tetap berlaku; Anda bisa menariknya kapan saja di kemudian hari.</p>
            @endif
        </div>
        <div class="foot">Powered by Privasimu Nexus · UU PDP No. 27/2022 · PP 33/2026 Pasal 38</div>
    </div>
</div>
</body>
</html>
