<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DsrApp;
use App\Models\DsrInboundChannel;
use App\Models\DsrRequest;
use Illuminate\Support\Str;

/**
 * Pembuatan permohonan subjek data dari kanal selain formulir publik.
 *
 * Permohonan yang masuk lewat surel dibuat dengan status BELUM TERVERIFIKASI,
 * sama seperti formulir publik. Alamat pengirim surel bukan bukti kepemilikan
 * identitas — surel dapat dipalsukan, dan meloloskan verifikasi hanya karena
 * permohonannya datang lewat surel akan membuat kanal ini menjadi jalan pintas
 * terhadap seluruh mekanisme verifikasi yang sudah ada.
 */
class DsrIntakeService
{
    /**
     * Kata kunci penentu jenis permohonan, diperiksa berurutan.
     *
     * Urutannya penting: "hapus" diperiksa sebelum "akses" karena kalimat
     * seperti "saya ingin mengakses lalu menghapus data saya" harus dibaca
     * sebagai permohonan penghapusan — jenis yang konsekuensinya lebih besar.
     */
    private const TYPE_KEYWORDS = [
        'deletion' => ['hapus', 'penghapusan', 'delete', 'erasure', 'dilupakan'],
        'rectification' => ['koreksi', 'perbaikan', 'ubah data', 'rectif', 'correct'],
        'restriction' => ['pembatasan', 'batasi', 'restrict', 'hentikan pemrosesan'],
        'objection' => ['keberatan', 'menolak', 'object'],
        'portability' => ['portabilitas', 'pindahkan data', 'portab'],
        'access' => ['akses', 'salinan', 'access', 'copy', 'lihat data'],
    ];

    /**
     * Buat permohonan dari pesan surel.
     *
     * @param  array{from?: string, from_name?: string, subject?: string, body?: string}  $message
     * @return array{created: bool, dsr_id?: string, request_id?: string, reason?: string}
     */
    public function fromEmail(DsrInboundChannel $channel, array $message): array
    {
        $from = trim((string) ($message['from'] ?? ''));
        if ($from === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $channel->increment('total_rejected');

            return ['created' => false, 'reason' => 'Alamat pengirim tidak sah.'];
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        $body = trim((string) ($message['body'] ?? ''));

        if ($subject === '' && $body === '') {
            $channel->increment('total_rejected');

            return ['created' => false, 'reason' => 'Pesan kosong.'];
        }

        $app = $channel->app_id
            ? DsrApp::withoutGlobalScope('org')->find($channel->app_id)
            : DsrApp::withoutGlobalScope('org')->where('org_id', $channel->org_id)->first();

        $dsr = DsrRequest::create([
            'org_id' => $channel->org_id,
            'app_id' => $app?->id,
            'request_id' => $this->nextRequestId(),
            'request_type' => $this->detectType($subject.' '.$body),
            'requester_name' => trim((string) ($message['from_name'] ?? '')) ?: $this->nameFromEmail($from),
            'requester_email' => $from,
            'description' => trim($subject."\n\n".$body),
            // Sama seperti formulir publik: identitas belum terbukti.
            'status' => 'pending_verification',
            'verification_status' => 'pending',
            'verification_token' => Str::random(64),
            'verification_expires_at' => now()->addHours(24),
            'verification_method' => 'email_otp',
            // Tenggat dihitung dari saat pesan diterima, bukan dari saat
            // petugas membukanya — jam kewajiban sudah berjalan sejak pemohon
            // mengirimkannya.
            'deadline_at' => now()->addHours(72),
            'assigned_to' => $app?->default_assignee_user_id,
        ]);

        $channel->increment('total_received');

        AuditLog::log('dsr', $dsr->id, 'received_via_email', [
            'channel' => $channel->name,
            'from' => $from,
            'detected_type' => $dsr->request_type,
        ], 'intake');

        return ['created' => true, 'dsr_id' => $dsr->id, 'request_id' => $dsr->request_id];
    }

    /**
     * Tebak jenis permohonan dari teks pesan.
     *
     * Hasilnya sengaja diperlakukan sebagai dugaan awal, bukan keputusan:
     * petugas tetap dapat mengubahnya. Menolak permohonan yang jenisnya tidak
     * terbaca akan jauh lebih merugikan daripada menebak keliru, karena
     * penolakan berarti kewajiban 3x24 jam terlewat tanpa ada yang mengetahui.
     */
    public function detectType(string $text): string
    {
        $lower = mb_strtolower($text);
        foreach (self::TYPE_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $type;
                }
            }
        }

        return 'access';
    }

    private function nameFromEmail(string $email): string
    {
        $local = explode('@', $email)[0] ?? $email;
        $clean = trim(preg_replace('/[._\-]+/', ' ', $local) ?: $local);

        return $clean !== '' ? Str::title($clean) : $email;
    }

    /**
     * Nomor permohonan berikutnya.
     *
     * request_id unik secara GLOBAL, jadi penghitungnya pun harus global. Versi
     * lama memfilter ->where('org_id', $orgId) sehingga dua org sama-sama
     * menghasilkan DSR-YYYY-001 dan retry regenerasi nilai yang sama (F-03).
     * Sekarang lewat RegistrationCodeService yang menghitung lintas-tenant.
     */
    private function nextRequestId(): string
    {
        return app(RegistrationCodeService::class)->nextGlobal('DSR', DsrRequest::class);
    }
}
