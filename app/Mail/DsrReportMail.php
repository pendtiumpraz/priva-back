<?php

namespace App\Mail;

use App\Models\DsrRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Laporan hasil penanganan permohonan subjek data, dikirim ke pemohon.
 *
 * Lampiran PDF dibawa sebagai byte, bukan sebagai jalur berkas, supaya surel
 * tetap dapat dikirim dari antrean di mesin lain yang tidak memiliki berkas
 * sementaranya.
 */
class DsrReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DsrRequest $dsr,
        public string $bodyText,
        public ?string $pdfBytes = null,
        public ?string $pdfName = null,
        public ?string $orgName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Hasil Permohonan Subjek Data — '.$this->dsr->request_id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dsr-report',
            with: [
                'dsr' => $this->dsr,
                'bodyText' => $this->bodyText,
                'orgName' => $this->orgName,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (! $this->pdfBytes) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->pdfName ?: 'laporan-dsr.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
