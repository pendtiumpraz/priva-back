@php
    $kind = $notification->kind ?? 'alert';
    $severity = $notification->severity ?? 'medium';
    $kindColor = match($kind) {
        'alert' => '#ef4444',
        'warning' => '#f59e0b',
        'info' => '#3b82f6',
        default => '#6b7280',
    };
    $kindLabel = match($kind) {
        'alert' => 'ALERT',
        'warning' => 'WARNING',
        'info' => 'INFO',
        default => 'NOTICE',
    };
    $severityLabel = strtoupper($severity);
    $meta = is_array($notification->metadata) ? $notification->metadata : [];
    $actionUrl = $notification->action_url ?? null;
    $fullActionUrl = $actionUrl && !str_starts_with($actionUrl, 'http')
        ? rtrim($appUrl, '/') . $actionUrl
        : $actionUrl;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $notification->title }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                    <tr>
                        <td style="background:{{ $kindColor }};padding:18px 24px;color:#ffffff;">
                            <div style="font-size:11px;font-weight:800;letter-spacing:1.2px;opacity:0.92;">
                                {{ $kindLabel }} · {{ $severityLabel }} · {{ strtoupper($notification->module ?? 'SYSTEM') }}
                            </div>
                            <div style="font-size:18px;font-weight:700;margin-top:6px;line-height:1.3;">
                                {{ $notification->title }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px 0;font-size:14px;">Halo {{ $recipientName }},</p>
                            <p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#374151;">
                                {{ $notification->description }}
                            </p>

                            @if(!empty($meta['admin_name']) || !empty($meta['admin_email']) || !empty($meta['admin_phone']))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border-radius:8px;padding:12px 14px;margin:16px 0;font-size:13px;color:#4b5563;">
                                    @if(!empty($meta['admin_name']))<tr><td><strong>Contact:</strong> {{ $meta['admin_name'] }}</td></tr>@endif
                                    @if(!empty($meta['admin_email']))<tr><td>Email: <a href="mailto:{{ $meta['admin_email'] }}" style="color:{{ $kindColor }};">{{ $meta['admin_email'] }}</a></td></tr>@endif
                                    @if(!empty($meta['admin_phone']))<tr><td>Phone: {{ $meta['admin_phone'] }}</td></tr>@endif
                                    @if(!empty($meta['expires_at']))<tr><td>Expires: {{ \Carbon\Carbon::parse($meta['expires_at'])->format('d M Y') }}</td></tr>@endif
                                </table>
                            @endif

                            @if($fullActionUrl)
                                <p style="margin:20px 0;">
                                    <a href="{{ $fullActionUrl }}"
                                       style="background:{{ $kindColor }};color:#ffffff;padding:10px 22px;border-radius:8px;font-weight:700;text-decoration:none;display:inline-block;font-size:14px;">
                                        {{ str_starts_with($actionUrl ?? '', 'https://wa.me/') ? '💬 Follow-up via WhatsApp' : 'Buka Detail' }}
                                    </a>
                                </p>
                            @endif

                            <p style="margin:20px 0 0 0;font-size:12px;color:#9ca3af;">
                                Dikirim otomatis oleh sistem Privasimu Nexus ·
                                <a href="{{ rtrim($appUrl,'/') }}/settings/notifications" style="color:#9ca3af;text-decoration:underline;">atur preferensi</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
