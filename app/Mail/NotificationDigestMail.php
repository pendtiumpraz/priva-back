<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Digest email: single message summarizing N notifications the user
 * opted to receive as `daily` or `weekly` rather than `instant`.
 */
class NotificationDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $notifications,
        public string $recipientName,
        public string $frequency = 'daily'
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->notifications->count();
        $label = $this->frequency === 'weekly' ? 'Mingguan' : 'Harian';
        return new Envelope(
            subject: "[Digest {$label}] {$count} notifikasi Privasimu",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.digest',
            with: [
                'notifications' => $this->notifications,
                'recipientName' => $this->recipientName,
                'frequency' => $this->frequency,
                'appUrl' => config('app.frontend_url', config('app.url', 'http://localhost:3000')),
            ],
        );
    }
}
