<?php

namespace App\Mail;

use App\Models\GuardianConsent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tautan persetujuan untuk wali — PP 33/2026 Pasal 38.
 *
 * Tautannya membawa wali ke halaman yang MENAMPILKAN apa yang diminta; membuka
 * tautan tidak menyetujui apa pun. Itu disebut tegas di badan surel, supaya
 * wali tahu bahwa yang menyetujui adalah tombol di halaman berikutnya — bukan
 * klik pada surel ini.
 *
 * Berlaku 24 jam (guardian_consents.verification_expires_at). Di lingkungan
 * tanpa SMTP (MAIL_MAILER=log) isi surel jatuh ke laravel.log.
 *
 * @property array<string, mixed> $pratinjau
 */
class GuardianVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $pratinjau
     */
    public function __construct(
        public GuardianConsent $kewenangan,
        public string $verifyUrl,
        public array $pratinjau,
    ) {}

    public function envelope(): Envelope
    {
        $org = $this->pratinjau['organization'] ?? 'Pengendali Data';

        return new Envelope(
            subject: "Persetujuan Anda Diperlukan — {$org}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guardian_verify',
            with: [
                'p' => $this->pratinjau,
                'verifyUrl' => $this->verifyUrl,
                'expiresAt' => $this->kewenangan->verification_expires_at?->setTimezone('Asia/Jakarta'),
            ],
        );
    }
}
