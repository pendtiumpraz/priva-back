<?php

namespace App\Mail;

use App\Models\DsrApp;
use App\Models\DsrRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to DSR submitter so they can verify ownership of the email.
 * Link valid 24 jam (cek dsr_requests.verification_expires_at).
 *
 * In dev / no-SMTP environment (MAIL_MAILER=log), email body lands in
 * laravel.log — caller is expected to also surface the URL via DPO UI
 * for manual delivery (WhatsApp, SMS, etc).
 */
class DsrVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DsrRequest $dsr,
        public string $verifyUrl,
        public ?DsrApp $app = null,
    ) {}

    public function envelope(): Envelope
    {
        $appName = $this->app?->name ?: 'Aplikasi';
        return new Envelope(
            subject: "Verifikasi Permintaan Hak Subjek Data — {$this->dsr->request_id} ({$appName})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dsr_verify',
            with: [
                'dsr' => $this->dsr,
                'app' => $this->app,
                'verifyUrl' => $this->verifyUrl,
                'expiresAt' => $this->dsr->verification_expires_at?->setTimezone('Asia/Jakarta'),
                'branding' => $this->app?->branding ?? [],
            ],
        );
    }
}
