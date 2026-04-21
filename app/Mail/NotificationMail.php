<?php

namespace App\Mail;

use App\Models\SecurityAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email sender for a single notification. Used for instant (per-event)
 * delivery. Daily/weekly digest uses NotificationDigestMail instead.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SecurityAlert $notification,
        public string $recipientName = 'there'
    ) {}

    public function envelope(): Envelope
    {
        $kind = strtoupper($this->notification->kind ?? 'alert');
        $prefix = match ($kind) {
            'ALERT' => '[ALERT]',
            'WARNING' => '[WARNING]',
            'INFO' => '[INFO]',
            default => '',
        };
        return new Envelope(
            subject: trim("{$prefix} " . ($this->notification->title ?: 'Privasimu Notification')),
        );
    }

    public function content(): Content
    {
        // Build a signed unsubscribe URL tied to this user + (kind, module, email channel).
        // Signature expires in 30 days for security.
        $unsubscribeUrl = null;
        if ($this->notification->recipient_id) {
            try {
                $unsubscribeUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'notifications.unsubscribe',
                    now()->addDays(30),
                    [
                        'user' => $this->notification->recipient_id,
                        'kind' => $this->notification->kind ?? 'info',
                        'module' => $this->notification->module ?? '*',
                        'channel' => 'email',
                    ]
                );
            } catch (\Throwable $e) {
                \Log::warning('unsubscribe URL build failed: ' . $e->getMessage());
            }
        }

        return new Content(
            view: 'emails.notification',
            with: [
                'notification' => $this->notification,
                'recipientName' => $this->recipientName,
                'appUrl' => config('app.frontend_url', config('app.url', 'http://localhost:3000')),
                'unsubscribeUrl' => $unsubscribeUrl,
            ],
        );
    }
}
