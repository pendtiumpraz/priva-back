<?php

namespace App\Mail;

use App\Models\ConsentSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tautan keputusan untuk subjek yang baru genap 18 — PP 33/2026 Pasal 38 ayat (8).
 *
 * Dikirim ke kanal MILIK subjek, bukan ke walinya. Isinya jujur: kewenangan
 * wali sudah berakhir, consent yang dulu diberikan tetap berlaku, dan
 * keputusannya — melanjutkan atau menarik — ada di tangan subjek. Membuka
 * tautan tidak memutuskan apa pun; tombol di halamannya yang memutuskan.
 *
 * @property array<string, mixed> $pratinjau
 */
class PeralihanDewasaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $pratinjau
     */
    public function __construct(
        public ConsentSubject $subjek,
        public string $decisionUrl,
        public array $pratinjau,
    ) {}

    public function envelope(): Envelope
    {
        $org = $this->pratinjau['organization'] ?? 'Pengendali Data';

        return new Envelope(
            subject: "Kini Anda memutuskan sendiri — {$org}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transition_adult',
            with: [
                'p' => $this->pratinjau,
                'decisionUrl' => $this->decisionUrl,
                'expiresAt' => $this->subjek->transition_token_expires_at?->setTimezone('Asia/Jakarta'),
            ],
        );
    }
}
