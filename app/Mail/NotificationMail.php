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
        return new Content(
            view: 'emails.notification',
            with: [
                'notification' => $this->notification,
                'recipientName' => $this->recipientName,
                'appUrl' => config('app.frontend_url', config('app.url', 'http://localhost:3000')),
            ],
        );
    }
}
