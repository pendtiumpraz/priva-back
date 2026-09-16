<?php

namespace App\Services\Consent;

use App\Mail\GuardianVerificationMail;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Models\VerificationMethod;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Alur wali dua langkah — PP 33/2026 Pasal 38 ayat (2) & (4).
 *
 *   1. ajukan()     Subjek (atau aplikasi tenant) menyatakan: ini anak, ini
 *                   walinya, ini pilihan consent-nya. Sistem membuat subjek,
 *                   wali, dan kewenangan yang MENUNGGU, lalu mengirim tautan
 *                   ke surel wali. Belum ada satu baris pun di ledger.
 *   2. pratinjau()  Wali membuka tautan dan MELIHAT apa yang diminta darinya.
 *                   Tidak mengubah apa pun — pemindai tautan di server surel
 *                   membuka tautan sebelum manusianya, dan "membuka" tidak
 *                   boleh berarti "menyetujui".
 *   3. konfirmasi() Wali menekan "Saya menyetujui". Barulah kewenangan
 *                   terverifikasi dan baris ledger lahir, dengan pernyataan
 *                   persis yang ia lihat.
 *
 * Kewenangan yang sudah terverifikasi TIDAK diverifikasi ulang pada pengajuan
 * berikutnya untuk pasangan (subjek, wali) yang sama — tapi TINDAKAN
 * menyetujuinya tetap harus dilakukan wali untuk tiap penangkapan. Kewenangan
 * yang berdiri bukan persetujuan yang berdiri: Pasal 38 meminta persetujuan
 * wali atas pemrosesannya, bukan izin umum sekali untuk selamanya.
 *
 * Yang dicatat dari verifikasi adalah HASILNYA — `otp_email`, keyakinan
 * `rendah`, waktu, IP. Bukan salinan surel, bukan identitas apa pun.
 */
final class LayananWali
{
    public const MASA_BERLAKU_JAM = 24;

    public const JEDA_KIRIM_ULANG_DETIK = 120;

    public const KANAL_BELUM_DIDUKUNG = 'KANAL_WALI_BELUM_DIDUKUNG';

    public const TERLALU_CEPAT = 'TERLALU_CEPAT';

    public const TOKEN_TIDAK_DIKENAL = 'TOKEN_TIDAK_DIKENAL';

    public const TOKEN_KEDALUWARSA = 'TOKEN_KEDALUWARSA';

    public const TIDAK_ADA_YANG_MENUNGGU = 'TIDAK_ADA_YANG_MENUNGGU';

    public const KEWENANGAN_DICABUT = 'KEWENANGAN_DICABUT';

    public const HUBUNGAN_LABEL = [
        'orang_tua' => 'orang tua',
        'wali_sah' => 'wali sah',
        'pendamping' => 'pendamping',
        'lainnya' => 'wali',
    ];

    /**
     * Langkah 1 — catat niat, kirim tautan ke wali.
     *
     * @param  array<string, mixed>  $data  sudah tervalidasi oleh pemanggil
     */
    public function ajukan(ConsentCollectionPoint $cp, array $data, string $ip, ?string $userAgent, string $sumber): GuardianConsent
    {
        $kontak = trim((string) ($data['guardian']['contact'] ?? ''));

        // Diperiksa SEBELUM transaksi: kanal yang tidak bisa kita layani tidak
        // boleh meninggalkan subjek dan wali setengah jadi.
        if (! str_contains($kontak, '@')) {
            $this->tolak(
                self::KANAL_BELUM_DIDUKUNG,
                'Untuk saat ini verifikasi wali hanya tersedia lewat surel. Kanal telepon menyusul.',
                422,
                'guardian.contact',
            );
        }

        $kewenangan = DB::transaction(function () use ($cp, $data, $kontak, $ip, $sumber) {
            $subjek = ConsentSubject::temukanAtauBuat($cp->org_id, (string) $data['user_identifier'], [
                'subject_class' => $data['subject_class'],
                'transition_date' => $data['transition_date'] ?? null,
                'subject_own_channel' => $data['subject_own_channel'] ?? null,
            ]);

            // Subjek yang sudah ada: lengkapi yang masih kosong, jangan timpa.
            // Tanggal peralihan yang sudah tercatat adalah fakta yang sudah
            // dipakai antrean; menimpanya dari pengajuan baru membuka jalan
            // "memundurkan" kedewasaan seorang anak lewat formulir.
            $lengkapi = [];
            if ($subjek->transition_date === null && ! empty($data['transition_date'])) {
                $lengkapi['transition_date'] = $data['transition_date'];
            }
            if ($subjek->subject_own_channel === null && ! empty($data['subject_own_channel'])) {
                $lengkapi['subject_own_channel'] = $data['subject_own_channel'];
            }
            if ($lengkapi !== []) {
                $subjek->forceFill($lengkapi)->save();
            }

            $wali = Guardian::temukanAtauBuat($cp->org_id, $kontak, [
                'name' => $data['guardian']['name'],
                'relationship' => $data['guardian']['relationship'],
                'relationship_note' => $data['guardian']['relationship_note'] ?? null,
            ]);

            $kw = GuardianConsent::withoutGlobalScope('org')
                ->where('org_id', $cp->org_id)
                ->where('consent_subject_id', $subjek->id)
                ->where('guardian_id', $wali->id)
                ->whereNull('revoked_at')
                ->orderByDesc('created_at')
                ->first();

            if (! $kw) {
                $kw = GuardianConsent::create([
                    'org_id' => $cp->org_id,
                    'consent_subject_id' => $subjek->id,
                    'guardian_id' => $wali->id,
                    'collection_point_id' => $cp->id,
                ]);
            }

            $kw->forceFill([
                'collection_point_id' => $cp->id,
                'pending_capture' => [
                    'user_identifier' => (string) $data['user_identifier'],
                    'consented_items' => $data['consented_items'],
                    'policy_version' => $data['policy_version'] ?? '1.0',
                    'name' => $data['name'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'external_user_ref' => $data['external_user_ref'] ?? null,
                    'source_form' => $data['source_form'] ?? null,
                    'requested_from' => $sumber,
                    'requested_ip' => $ip,
                    'requested_at' => now()->toIso8601String(),
                ],
            ])->save();

            return $kw;
        });

        $this->kirimTautan($kewenangan);

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $kewenangan->id,
            'action' => 'guardian_consent.request',
            'user_name' => 'publik',
            'user_role' => $sumber,
            'changes' => [
                'collection_point_id' => $cp->id,
                'subject_class' => $data['subject_class'],
                'relationship' => $data['guardian']['relationship'],
            ],
            'ip_address' => $ip,
        ]);

        return $kewenangan;
    }

    /**
     * Langkah 2 — apa yang dilihat wali. Murni baca.
     *
     * @return array<string, mixed>
     */
    public function pratinjau(GuardianConsent $kw): array
    {
        $kw->loadMissing(['guardian', 'consentSubject', 'collectionPoint']);

        $cp = $kw->collectionPoint;
        $org = $cp ? Organization::find($cp->org_id) : null;
        $wali = $kw->guardian;
        $subjek = $kw->consentSubject;
        $pending = $kw->pending_capture ?? [];

        $tujuan = $this->judulTujuan($cp, (array) ($pending['consented_items'] ?? []));
        // `??` sudah menelan null di sebelah kirinya — `?->` di sini mubazir.
        $hubungan = self::HUBUNGAN_LABEL[$wali->relationship ?? ''] ?? 'wali';

        return [
            'guardian_consent_id' => $kw->id,
            'organization' => $org?->name,
            'collection_point' => $cp?->name,
            'guardian_name' => $wali?->name,
            'relationship' => $wali?->relationship,
            'relationship_label' => $hubungan,
            'subject_label' => $subjek?->subject_label,
            'subject_class' => $subjek?->subject_class,
            'purposes' => $tujuan,
            'statement' => $this->pernyataan(
                (string) $wali?->name,
                $hubungan,
                (string) $subjek?->subject_label,
                (string) ($org->name ?? 'pengendali data'),
                (string) ($cp->name ?? ''),
                $tujuan,
            ),
            'expires_at' => $kw->verification_expires_at?->toIso8601String(),
            'already_verified' => $kw->verified_at !== null,
            'has_pending' => $pending !== [],
        ];
    }

    /**
     * Langkah 3 — wali menyetujui. Di sinilah ledger ditulis.
     */
    public function konfirmasi(string $tokenMentah, string $ip, ?string $userAgent): ConsentLog
    {
        $kw = GuardianConsent::denganToken($tokenMentah);

        if (! $kw) {
            $this->tolak(self::TOKEN_TIDAK_DIKENAL, 'Tautan tidak dikenal atau sudah pernah dipakai.', 404);
        }
        if ($kw->tokenKedaluwarsa()) {
            $this->tolak(self::TOKEN_KEDALUWARSA, 'Tautan sudah kedaluwarsa. Minta tautan baru dari aplikasi yang mengajukannya.', 410);
        }
        if ($kw->revoked_at !== null) {
            $this->tolak(self::KEWENANGAN_DICABUT, 'Kewenangan wali ini sudah dicabut.', 422);
        }

        $pending = $kw->pending_capture ?? [];
        $cp = $kw->collectionPoint;
        if ($pending === [] || ! $cp) {
            $this->tolak(self::TIDAK_ADA_YANG_MENUNGGU, 'Tidak ada permintaan persetujuan yang menunggu pada tautan ini.', 409);
        }

        $pratinjau = $this->pratinjau($kw);

        $log = DB::transaction(function () use ($kw, $cp, $pending, $pratinjau, $ip, $userAgent) {
            $isi = [
                // Sekali pakai: token mati begitu dipakai, apa pun hasilnya.
                'verification_token_hash' => null,
                'verification_expires_at' => null,
                'pending_capture' => null,
                'statement_shown' => $pratinjau['statement'],
                'ip_address' => $ip,
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            ];

            // Verifikasi pertama untuk pasangan ini. Pengajuan berikutnya
            // memakai kewenangan yang sama — verified_at aslinya dipertahankan.
            if ($kw->verified_at === null) {
                $isi += [
                    'verified_at' => now(),
                    'verification_method_code' => 'otp_email',
                    'verification_driver' => VerificationMethod::DRIVER_OTP,
                    'verification_confidence' => 'rendah',
                ];
            }

            $kw->forceFill($isi)->save();

            $consented = (array) ($pending['consented_items'] ?? []);
            $purposeKeys = [];
            foreach ($consented as $k => $v) {
                if ($v === true || $v === 'true' || $v === 1 || $v === '1') {
                    $purposeKeys[] = (string) $k;
                }
            }

            $penanda = (string) ($pending['user_identifier'] ?? '');
            $email = filter_var($penanda, FILTER_VALIDATE_EMAIL) ? strtolower(trim($penanda)) : null;

            $ua = UserAgentParser::parse($userAgent);
            $geo = IpGeoResolver::resolve($ip);

            return ConsentLog::create([
                'org_id' => $cp->org_id,
                'collection_id' => $cp->id,
                'user_identifier' => $penanda,
                'email' => $email,
                'name' => $pending['name'] ?? null,
                'phone' => $pending['phone'] ?? null,
                'external_user_ref' => $pending['external_user_ref'] ?? null,
                'consented_items' => $consented,
                'purpose_keys' => $purposeKeys,
                'policy_version' => $pending['policy_version'] ?? '1.0',
                'source_form' => $pending['source_form'] ?? 'guardian_verify',
                // IP dan peramban WALI saat menyetujui — bukan milik subjek
                // saat mengisi formulir. Yang bertindak di baris ini adalah wali.
                'ip_address' => $ip,
                'ip_country' => $geo['country'],
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                'browser_name' => $ua['browser_name'],
                'browser_version' => $ua['browser_version'],
                'os_name' => $ua['os_name'],
                'device_type' => $ua['device_type'],
                'guardian_consent_id' => $kw->id,
                'subject_class' => $kw->consentSubject?->subject_class,
            ]);
        });

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $log->id,
            'action' => 'guardian_consent.confirm',
            'user_name' => 'wali (tautan surel)',
            'user_role' => 'guardian',
            'changes' => [
                'guardian_consent_id' => $kw->id,
                'subject_class' => $log->subject_class,
                'verification_method_code' => 'otp_email',
            ],
            'ip_address' => $ip,
        ]);

        $this->sebarkan($cp, $log, $kw);

        return $log;
    }

    /**
     * Kirim ulang tautan dari dashboard.
     *
     * Hanya untuk kewenangan yang masih punya pilihan menunggu. Yang sudah
     * dicabut, atau yang tidak menunggu apa pun (sudah disetujui, atau belum
     * pernah diajukan pilihan), ditolak TERBUKA — bukan "terkirim" palsu.
     */
    public function kirimUlang(GuardianConsent $kw): void
    {
        if ($kw->revoked_at !== null) {
            $this->tolak(self::KEWENANGAN_DICABUT, 'Kewenangan wali ini sudah dicabut.', 422);
        }
        if (($kw->pending_capture ?? []) === []) {
            $this->tolak(self::TIDAK_ADA_YANG_MENUNGGU, 'Tidak ada pilihan consent yang menunggu wali pada kewenangan ini.', 409);
        }

        $this->kirimTautan($kw);
    }

    // ───────────────────────── internal ─────────────────────────

    private function kirimTautan(GuardianConsent $kw): void
    {
        $kunci = 'guardian-link:'.$kw->id;

        // Pengajuan ulang beruntun (tombol ditekan dua kali, skrip yang
        // mengulang) tidak boleh membanjiri kotak surel wali. Ditolak TERBUKA
        // dengan 429 — bukan diam-diam tidak mengirim, yang akan terlihat
        // persis seperti surel yang tersesat.
        if (RateLimiter::tooManyAttempts($kunci, 1)) {
            $this->tolak(
                self::TERLALU_CEPAT,
                'Tautan verifikasi baru saja dikirim ke wali. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
                429,
            );
        }
        RateLimiter::hit($kunci, self::JEDA_KIRIM_ULANG_DETIK);

        $mentah = $kw->terbitkanToken(self::MASA_BERLAKU_JAM);
        $url = url('/api/public/consent/guardian/verify/'.$mentah);

        $kw->loadMissing('guardian');

        Mail::to($kw->guardian->contact)->queue(
            new GuardianVerificationMail($kw, $url, $this->pratinjau($kw)),
        );
    }

    /**
     * Webhook dan CRM — sama seperti jalur widget, dengan `source` yang jujur
     * dan satu bidang tambahan supaya penerima tahu ini consent lewat wali.
     */
    private function sebarkan(ConsentCollectionPoint $cp, ConsentLog $log, GuardianConsent $kw): void
    {
        app(PenyebarConsent::class)->sebarkan($cp, $log, 'guardian_verify', ['guardian_consent_id' => $kw->id]);
    }

    /**
     * Dua bentuk yang sama-sama sah di ledger: peta {id: bool} (widget baru)
     * dan daftar id yang disetujui (embed v1) — lihat ConsentLog::labeledConsentedItems.
     *
     * @param  array<int|string, mixed>  $consented
     * @return list<string>
     */
    private function judulTujuan(?ConsentCollectionPoint $cp, array $consented): array
    {
        $peta = $cp ? ConsentItem::titleMap([$cp->id]) : [];
        $tujuan = [];

        if (array_is_list($consented)) {
            foreach ($consented as $id) {
                $tujuan[] = (string) ($peta[$id] ?? $id);
            }

            return $tujuan;
        }

        foreach ($consented as $id => $v) {
            if ($v === true || $v === 'true' || $v === 1 || $v === '1') {
                $tujuan[] = (string) ($peta[$id] ?? $id);
            }
        }

        return $tujuan;
    }

    /**
     * Kalimat yang dilihat — dan nanti disimpan verbatim sebagai bukti bahwa
     * yang disetujui memang spesifik, bukan "saya setuju".
     *
     * @param  list<string>  $tujuan
     */
    private function pernyataan(string $wali, string $hubungan, string $subjek, string $org, string $titik, array $tujuan): string
    {
        $daftar = $tujuan === [] ? '(tidak ada tujuan yang dipilih)' : implode('; ', $tujuan);
        $lewat = $titik !== '' ? " melalui {$titik}" : '';

        return "Saya, {$wali}, selaku {$hubungan} dari {$subjek}, menyatakan berwenang memberikan persetujuan "
            ."dan menyetujui pemrosesan data pribadi {$subjek} oleh {$org}{$lewat} untuk tujuan: {$daftar}. "
            .'Persetujuan ini dapat saya tarik kembali kapan saja.';
    }

    private function tolak(string $kode, string $pesan, int $status, ?string $bidang = null): never
    {
        $isi = ['error' => $pesan, 'code' => $kode];
        if ($bidang !== null) {
            $isi['errors'] = [$bidang => [$pesan]];
        }

        throw new HttpResponseException(
            response()->json($isi, $status)->header('Access-Control-Allow-Origin', '*'),
        );
    }
}
