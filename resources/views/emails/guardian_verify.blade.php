<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Persetujuan Wali Diperlukan</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0f172a;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,.08);">
                <tr>
                    <td style="background:#0f172a;padding:18px 24px;color:#fff;">
                        <strong style="font-size:14px;letter-spacing:.5px;">{{ $p['organization'] ?? 'Pengendali Data' }}</strong>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="font-size:18px;margin:0 0 12px;color:#0f172a;">Persetujuan Anda sebagai {{ $p['relationship_label'] ?? 'wali' }} diperlukan</h1>
                        <p style="margin:0 0 16px;line-height:1.6;color:#334155;font-size:14px;">
                            Halo {{ $p['guardian_name'] ?: 'Bapak/Ibu' }},
                        </p>
                        <p style="margin:0 0 16px;line-height:1.6;color:#334155;font-size:14px;">
                            <strong>{{ $p['organization'] ?? 'Pengendali data' }}</strong>
                            @if(!empty($p['collection_point'])) (melalui {{ $p['collection_point'] }}) @endif
                            meminta persetujuan Anda untuk memproses data pribadi
                            <strong>{{ $p['subject_label'] ?? 'subjek data' }}</strong>
                            untuk tujuan berikut:
                        </p>
                        <ul style="margin:0 0 16px;padding-left:20px;line-height:1.7;color:#334155;font-size:14px;">
                            @forelse(($p['purposes'] ?? []) as $tujuan)
                                <li>{{ $tujuan }}</li>
                            @empty
                                <li>(tidak ada tujuan yang dipilih)</li>
                            @endforelse
                        </ul>
                        <p style="margin:0 0 16px;line-height:1.6;color:#334155;font-size:14px;">
                            Tombol di bawah membawa Anda ke halaman untuk <strong>melihat dan menyetujui</strong>.
                            Membuka tautan ini <em>tidak</em> menyetujui apa pun — persetujuan baru tercatat
                            setelah Anda menekan tombol setuju di halaman tersebut.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:8px 28px 24px;">
                        <a href="{{ $verifyUrl }}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 32px;border-radius:6px;font-weight:700;font-size:14px;">
                            Lihat &amp; Setujui
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 28px 20px;">
                        <p style="margin:0 0 8px;font-size:12px;color:#64748b;line-height:1.5;">
                            Atau salin tautan berikut ke peramban:
                        </p>
                        <p style="margin:0 0 16px;font-size:11px;color:#475569;word-break:break-all;background:#f1f5f9;padding:8px 10px;border-radius:4px;font-family:ui-monospace,monospace;">
                            {{ $verifyUrl }}
                        </p>
                        @if($expiresAt)
                            <p style="margin:0 0 6px;font-size:12px;color:#64748b;">
                                Tautan berlaku hingga: <strong>{{ $expiresAt->format('d F Y H:i') }} WIB</strong>
                            </p>
                        @endif
                        <p style="margin:12px 0 0;font-size:12px;color:#94a3b8;line-height:1.6;">
                            Jika Anda bukan {{ $p['relationship_label'] ?? 'wali' }} dari {{ $p['subject_label'] ?? 'subjek data' }},
                            atau tidak pernah diminta hal ini, abaikan surel ini. Tidak ada yang diproses tanpa persetujuan Anda.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f8fafc;padding:14px 24px;text-align:center;border-top:1px solid #e2e8f0;">
                        <span style="font-size:11px;color:#94a3b8;">Powered by Privasimu Nexus · UU PDP No. 27/2022 · PP 33/2026 Pasal 38</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
