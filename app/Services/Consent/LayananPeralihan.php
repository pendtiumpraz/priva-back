<?php

namespace App\Services\Consent;

use App\Mail\PeralihanDewasaMail;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\ConsentSubject;
use App\Models\GuardianConsent;
use App\Models\Organization;
use App\Support\KelasSubjek;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Peralihan anak → dewasa — PP 33/2026 Pasal 38 ayat (8).
 *
 * Yang BERAKHIR saat anak genap 18 adalah kewenangan walinya; dasar hukum
 * consent-nya tidak hilang karena ulang tahun. Maka urutannya:
 *
 *   1. jalankan()   antrean harian: tiap subjek yang siapBeralih() →
 *                   kewenangan wali dicabut (`peralihan_dewasa`), keadaan
 *                   `menunggu_konfirmasi`, dan — bila ada kanal MILIK subjek —
 *                   tautan keputusan dikirim ke sana.
 *   2. pratinjau()  subjek (kini dewasa) MELIHAT consent apa saja yang dulu
 *                   diberikan walinya, per titik pengumpulan. Murni baca.
 *   3. konfirmasi() melanjutkan: subjek menjadi `dewasa`, consent tetap.
 *      tarik()      menarik: tiap titik mendapat baris ledger baru dengan
 *                   semua item FALSE — bentuk penarikan yang dikenali
 *                   ConsentStateResolver ("yang terbaru menang").
 *
 * Yang tidak menanggapi TIDAK dicabut otomatis. Ia masuk antrean kerja
 * pengendali (keadaan menunggu, tanpa keputusan). Menghapus persetujuan yang
 * sah tanpa diminta bukan perlindungan — itu keputusan sepihak atas nama
 * orang lain, dan orang itu baru saja memperoleh haknya untuk memutuskan.
 */
final class LayananPeralihan
{
    public const MASA_BERLAKU_HARI = 30;

    public const JEDA_KIRIM_ULANG_DETIK = 120;

    public const TOKEN_TIDAK_DIKENAL = 'TOKEN_TIDAK_DIKENAL';

    public const TOKEN_KEDALUWARSA = 'TOKEN_KEDALUWARSA';

    public const SUDAH_DIPUTUSKAN = 'SUDAH_DIPUTUSKAN';

    public const TANPA_KANAL = 'TANPA_KANAL_SUBJEK';

    public const BUKAN_MENUNGGU = 'BUKAN_MENUNGGU_KONFIRMASI';

    public const TERLALU_CEPAT = 'TERLALU_CEPAT';

    /**
     * Antrean. Mengembalikan hitungan: diproses, surel terkirim, tanpa kanal.
     *
     * @return array{diproses: int, terkirim: int, tanpa_kanal: int}
     */
    public function jalankan(?Carbon $pada = null, bool $dryRun = false): array
    {
        $pada ??= now();
        $hasil = ['diproses' => 0, 'terkirim' => 0, 'tanpa_kanal' => 0];

        // org_id tidak disaring: antrean berjalan untuk SEMUA tenant, dari
        // konteks artisan tanpa tenant. Tiap subjek membawa org_id-nya sendiri.
        $calon = ConsentSubject::withoutGlobalScope('org')
            ->where('subject_class', KelasSubjek::ANAK)
            ->whereNull('transition_state')
            ->whereNotNull('transition_date')
            ->whereDate('transition_date', '<=', $pada->toDateString())
            ->orderBy('transition_date')
            ->get();

        foreach ($calon as $subjek) {
            if (! $subjek->siapBeralih($pada)) {
                continue;
            }
            $hasil['diproses']++;
            if ($dryRun) {
                continue;
            }
            if ($this->beralih($subjek)) {
                $hasil['terkirim']++;
            } else {
                $hasil['tanpa_kanal']++;
            }
        }

        return $hasil;
    }

    /**
     * Satu subjek: cabut kewenangan wali, tandai menunggu, kirim tautan bila
     * ada kanal milik subjek. Mengembalikan true bila surel dikirim.
     */
    public function beralih(ConsentSubject $subjek): bool
    {
        DB::transaction(function () use ($subjek) {
            $subjek->forceFill(['transition_state' => KelasSubjek::TRANSISI_MENUNGGU])->save();

            foreach ($subjek->waliBerwenang()->get() as $kw) {
                $kw->cabut('peralihan_dewasa');
            }
        });

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $subjek->id,
            'action' => 'consent_subject.transition_start',
            'user_name' => 'antrean peralihan',
            'user_role' => 'system',
            'changes' => ['transition_date' => $subjek->transition_date?->toDateString()],
        ]);

        return $this->kirimTautan($subjek, false);
    }

    /**
     * Kirim ulang tautan dari dashboard — hanya untuk yang menunggu dan punya kanal.
     */
    public function kirimUlang(ConsentSubject $subjek): void
    {
        if ($subjek->transition_state !== KelasSubjek::TRANSISI_MENUNGGU) {
            $this->tolak(self::BUKAN_MENUNGGU, 'Subjek ini tidak sedang menunggu konfirmasi peralihan.', 409);
        }
        if (! $this->punyaKanalSurel($subjek)) {
            $this->tolak(self::TANPA_KANAL, 'Subjek ini tidak punya kanal surel miliknya sendiri — hubungi lewat jalur lain.', 422);
        }

        $this->kirimTautan($subjek, true);
    }

    /**
     * Apa yang dilihat subjek dewasa di tautan. Murni baca.
     *
     * @return array<string, mixed>
     */
    public function pratinjau(ConsentSubject $subjek): array
    {
        $org = Organization::find($subjek->org_id);
        $points = [];

        foreach ($this->titikTerakhir($subjek) as $baris) {
            /** @var ConsentLog $log */
            $log = $baris['log'];
            /** @var ConsentCollectionPoint $cp */
            $cp = $baris['cp'];

            $tujuan = [];
            foreach ($log->labeledConsentedItems() as $judul => $v) {
                if ($v) {
                    $tujuan[] = (string) $judul;
                }
            }

            $points[] = [
                'collection_id' => $cp->collection_id,
                'name' => $cp->name,
                'purposes' => $tujuan,
                'last_at' => $log->created_at?->toIso8601String(),
            ];
        }

        return [
            'organization' => $org?->name,
            'subject_label' => $subjek->subject_label,
            'transition_date' => $subjek->transition_date?->toDateString(),
            'state' => $subjek->transition_state,
            'expires_at' => $subjek->transition_token_expires_at?->toIso8601String(),
            'already_decided' => $subjek->transition_confirmed_at !== null,
            'points' => $points,
        ];
    }

    /** Melanjutkan: subjek menjadi dewasa, consent tetap berlaku. */
    public function konfirmasi(string $tokenMentah, string $ip, ?string $userAgent): ConsentSubject
    {
        $subjek = $this->subjekDariToken($tokenMentah);

        $subjek->forceFill([
            'transition_state' => KelasSubjek::TRANSISI_DIKONFIRMASI,
            'subject_class' => KelasSubjek::DEWASA,
            'transition_confirmed_at' => now(),
            'transition_token_hash' => null,
            'transition_token_expires_at' => null,
        ])->save();

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $subjek->id,
            'action' => 'consent_subject.transition_confirm',
            'user_name' => 'subjek (tautan surel)',
            'user_role' => 'subject',
            'changes' => ['user_agent' => $userAgent ? substr($userAgent, 0, 200) : null],
            'ip_address' => $ip,
        ]);

        return $subjek;
    }

    /**
     * Menarik: tiap titik pengumpulan yang dulu disetujui wali mendapat baris
     * ledger baru dengan semua item FALSE, bersumber `transition_withdraw`.
     */
    public function tarik(string $tokenMentah, string $ip, ?string $userAgent): ConsentSubject
    {
        $subjek = $this->subjekDariToken($tokenMentah);
        $titik = $this->titikTerakhir($subjek);

        /** @var list<array{0: ConsentCollectionPoint, 1: ConsentLog}> $dibuat */
        $dibuat = [];

        DB::transaction(function () use ($subjek, $titik, $ip, $userAgent, &$dibuat) {
            $subjek->forceFill([
                'transition_state' => KelasSubjek::TRANSISI_DITARIK,
                'subject_class' => KelasSubjek::DEWASA,
                'transition_confirmed_at' => now(),
                'transition_token_hash' => null,
                'transition_token_expires_at' => null,
            ])->save();

            foreach ($titik as $baris) {
                /** @var ConsentLog $lama */
                $lama = $baris['log'];
                /** @var ConsentCollectionPoint $cp */
                $cp = $baris['cp'];

                // Semua item yang pernah disebut → false. Bentuk peta {id: bool}
                // supaya ConsentStateResolver membacanya sebagai penarikan
                // eksplisit per item, bukan "tidak pernah ditanya".
                $semuaTidak = [];
                $isi = (array) ($lama->consented_items ?? []);
                if (array_is_list($isi)) {
                    foreach ($isi as $id) {
                        $semuaTidak[(string) $id] = false;
                    }
                } else {
                    foreach ($isi as $k => $v) {
                        $semuaTidak[(string) $k] = false;
                    }
                }

                $log = ConsentLog::create([
                    'org_id' => $cp->org_id,
                    'collection_id' => $cp->id,
                    'user_identifier' => $lama->user_identifier,
                    'email' => $lama->email,
                    'consented_items' => $semuaTidak,
                    'purpose_keys' => [],
                    'policy_version' => $lama->policy_version,
                    'source_form' => 'transition_withdraw',
                    'ip_address' => $ip,
                    'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                    // Ia menarik SEBAGAI orang dewasa — atas namanya sendiri.
                    'subject_class' => KelasSubjek::DEWASA,
                    'guardian_consent_id' => null,
                ]);

                $dibuat[] = [$cp, $log];
            }
        });

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $subjek->id,
            'action' => 'consent_subject.transition_withdraw',
            'user_name' => 'subjek (tautan surel)',
            'user_role' => 'subject',
            'changes' => ['collection_points' => count($dibuat)],
            'ip_address' => $ip,
        ]);

        $penyebar = app(PenyebarConsent::class);
        foreach ($dibuat as [$cp, $log]) {
            $penyebar->sebarkan($cp, $log, 'transition_withdraw', ['transition' => KelasSubjek::TRANSISI_DITARIK]);
        }

        return $subjek;
    }

    // ───────────────────────── internal ─────────────────────────

    private function kirimTautan(ConsentSubject $subjek, bool $batasi): bool
    {
        if (! $this->punyaKanalSurel($subjek)) {
            return false;
        }

        if ($batasi) {
            $kunci = 'transition-link:'.$subjek->id;
            if (RateLimiter::tooManyAttempts($kunci, 1)) {
                $this->tolak(self::TERLALU_CEPAT, 'Tautan baru saja dikirim. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.', 429);
            }
            RateLimiter::hit($kunci, self::JEDA_KIRIM_ULANG_DETIK);
        }

        $mentah = $subjek->terbitkanTokenPeralihan(self::MASA_BERLAKU_HARI);
        $url = url('/api/public/consent/transition/'.$mentah);

        Mail::to((string) $subjek->subject_own_channel)->queue(
            new PeralihanDewasaMail($subjek, $url, $this->pratinjau($subjek)),
        );

        $subjek->forceFill(['transition_notified_at' => now()])->save();

        return true;
    }

    private function punyaKanalSurel(ConsentSubject $subjek): bool
    {
        $kanal = (string) ($subjek->subject_own_channel ?? '');

        return $kanal !== '' && str_contains($kanal, '@');
    }

    private function subjekDariToken(string $tokenMentah): ConsentSubject
    {
        $subjek = ConsentSubject::denganTokenPeralihan($tokenMentah);

        if (! $subjek) {
            $this->tolak(self::TOKEN_TIDAK_DIKENAL, 'Tautan tidak dikenal atau sudah pernah dipakai.', 404);
        }
        if ($subjek->transition_confirmed_at !== null) {
            $this->tolak(self::SUDAH_DIPUTUSKAN, 'Keputusan atas peralihan ini sudah tercatat.', 409);
        }
        if ($subjek->tokenPeralihanKedaluwarsa()) {
            $this->tolak(self::TOKEN_KEDALUWARSA, 'Tautan sudah kedaluwarsa. Hubungi pengendali data untuk tautan baru.', 410);
        }

        return $subjek;
    }

    /**
     * Baris ledger TERAKHIR per titik pengumpulan yang pernah disetujui wali
     * atas subjek ini — "yang terbaru menang", sama seperti resolver.
     *
     * @return array<string, array{cp: ConsentCollectionPoint, log: ConsentLog}>
     */
    private function titikTerakhir(ConsentSubject $subjek): array
    {
        $ids = GuardianConsent::withoutGlobalScope('org')
            ->where('org_id', $subjek->org_id)
            ->where('consent_subject_id', $subjek->id)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $logs = ConsentLog::withoutGlobalScope('org')
            ->where('org_id', $subjek->org_id)
            ->whereIn('guardian_consent_id', $ids)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $terakhir = [];
        foreach ($logs as $log) {
            $terakhir[(string) $log->collection_id] = $log;
        }

        $hasil = [];
        foreach ($terakhir as $cpId => $log) {
            $cp = ConsentCollectionPoint::query()->where('org_id', $subjek->org_id)->find($cpId);
            if ($cp) {
                $hasil[$cpId] = ['cp' => $cp, 'log' => $log];
            }
        }

        return $hasil;
    }

    private function tolak(string $kode, string $pesan, int $status): never
    {
        throw new HttpResponseException(
            response()->json(['error' => $pesan, 'code' => $kode], $status)
                ->header('Access-Control-Allow-Origin', '*'),
        );
    }
}
