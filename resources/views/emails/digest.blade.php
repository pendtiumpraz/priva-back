@php
    $grouped = $notifications->groupBy('kind');
    $kindMeta = [
        'alert'   => ['color' => '#ef4444', 'label' => 'Alert'],
        'warning' => ['color' => '#f59e0b', 'label' => 'Warning'],
        'info'    => ['color' => '#3b82f6', 'label' => 'Info'],
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Digest Notifikasi Privasimu</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:22px 24px;color:#ffffff;">
                            <div style="font-size:11px;font-weight:800;letter-spacing:1.2px;opacity:0.92;">
                                DIGEST {{ strtoupper($frequency) }} · {{ $notifications->count() }} NOTIFIKASI
                            </div>
                            <div style="font-size:20px;font-weight:700;margin-top:6px;">
                                Ringkasan Privasimu Nexus
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 24px;">
                            <p style="margin:0 0 16px 0;font-size:14px;">Halo {{ $recipientName }},</p>
                            <p style="margin:0 0 18px 0;font-size:14px;color:#374151;line-height:1.6;">
                                Berikut ringkasan notifikasi yang Anda pilih untuk dikirim sebagai digest:
                            </p>

                            @foreach($grouped as $kind => $items)
                                @php $meta = $kindMeta[$kind] ?? ['color'=>'#6b7280','label'=>ucfirst($kind)]; @endphp
                                <div style="margin-bottom:20px;">
                                    <div style="font-size:12px;font-weight:800;color:{{ $meta['color'] }};text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px;">
                                        {{ $meta['label'] }} ({{ $items->count() }})
                                    </div>
                                    @foreach($items as $n)
                                        <div style="padding:10px 12px;border-left:3px solid {{ $meta['color'] }};background:#f9fafb;border-radius:4px;margin-bottom:6px;">
                                            <div style="font-size:13px;font-weight:700;color:#111827;">{{ $n->title }}</div>
                                            <div style="font-size:12px;color:#6b7280;margin-top:2px;">{{ $n->description }}</div>
                                            <div style="font-size:10px;color:#9ca3af;margin-top:4px;text-transform:uppercase;letter-spacing:0.5px;">
                                                {{ $n->module }} · {{ $n->severity }} · {{ $n->created_at?->diffForHumans() }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach

                            <p style="margin:20px 0;text-align:center;">
                                <a href="{{ rtrim($appUrl,'/') }}/notifications"
                                   style="background:#6366f1;color:#ffffff;padding:10px 22px;border-radius:8px;font-weight:700;text-decoration:none;display:inline-block;font-size:14px;">
                                    Buka Semua Notifikasi
                                </a>
                            </p>

                            <p style="margin:24px 0 0 0;font-size:12px;color:#9ca3af;text-align:center;">
                                <a href="{{ rtrim($appUrl,'/') }}/settings/notifications" style="color:#9ca3af;text-decoration:underline;">Atur preferensi / unsubscribe</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
