<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Verifikasi Permintaan</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0f172a;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,.08);">
                <tr>
                    <td style="background:{{ $branding['primary_color'] ?? '#0f172a' }};padding:18px 24px;color:#fff;">
                        <strong style="font-size:14px;letter-spacing:.5px;">{{ $app?->name ?? 'Aplikasi' }}</strong>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <h1 style="font-size:18px;margin:0 0 12px;color:#0f172a;">Verifikasi Permintaan Anda</h1>
                        <p style="margin:0 0 16px;line-height:1.6;color:#334155;font-size:14px;">
                            Halo {{ $dsr->requester_name ?: 'Pemohon' }},
                        </p>
                        <p style="margin:0 0 16px;line-height:1.6;color:#334155;font-size:14px;">
                            Kami menerima permintaan hak subjek data Anda dengan nomor
                            <strong>{{ $dsr->request_id }}</strong>.
                            Untuk memastikan permintaan ini benar-benar dari Anda, klik tombol
                            di bawah dalam waktu 24 jam:
                        </p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:8px 28px 24px;">
                        <a href="{{ $verifyUrl }}" style="display:inline-block;background:{{ $branding['accent_color'] ?? '#0ea5e9' }};color:#fff;text-decoration:none;padding:12px 32px;border-radius:6px;font-weight:700;font-size:14px;">
                            ✓ Verifikasi Identitas
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 28px 20px;">
                        <p style="margin:0 0 8px;font-size:12px;color:#64748b;line-height:1.5;">
                            Atau salin link berikut ke browser:
                        </p>
                        <p style="margin:0 0 16px;font-size:11px;color:#475569;word-break:break-all;background:#f1f5f9;padding:8px 10px;border-radius:4px;font-family:ui-monospace,monospace;">
                            {{ $verifyUrl }}
                        </p>
                        @if($expiresAt)
                            <p style="margin:0 0 6px;font-size:12px;color:#64748b;">
                                Link valid hingga: <strong>{{ $expiresAt->format('d F Y H:i') }} WIB</strong>
                            </p>
                        @endif
                        <p style="margin:12px 0 0;font-size:12px;color:#94a3b8;line-height:1.6;">
                            Jika Anda tidak pernah mengajukan permintaan ini, abaikan email ini.
                            Tidak ada tindakan yang dilakukan tanpa verifikasi Anda.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f8fafc;padding:14px 24px;text-align:center;border-top:1px solid #e2e8f0;">
                        <span style="font-size:11px;color:#94a3b8;">Powered by Privasimu Nexus · UU PDP No. 27/2022</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
