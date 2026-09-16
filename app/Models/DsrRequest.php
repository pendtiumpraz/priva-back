<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Exceptions\BuktiWaliBelumDiterima;
use App\Models\Concerns\AssignmentVisibility;
use App\Models\Concerns\BelongsToOrg;
use App\Support\KunciPencarian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string|null $org_id
 * @property string|null $request_id
 * @property string|null $request_type
 * @property string|null $requester_email
 * @property string|null $requester_type
 * @property string|null $requester_relation
 * @property string|null $subject_class
 * @property string|null $subject_identifier
 * @property string|null $subject_identifier_hash
 * @property string|null $guardian_consent_id
 * @property string|null $guardian_proof_status
 * @property string|null $guardian_proof_reason
 * @property string|null $guardian_proof_verified_by
 * @property string|null $status
 * @property Carbon|null $guardian_proof_verified_at
 */
class DsrRequest extends Model
{
    use AssignmentVisibility, BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'app_id', 'request_id', 'request_type', 'requester_name', 'requester_email',
        'requester_phone', 'description', 'subject_data',
        'status', 'verification_status', 'verification_token', 'verification_expires_at',
        'verification_method', 'verified_at',
        'response', 'rejection_reason',
        'deadline_at', 'responded_at', 'closed_at', 'closed_reason',
        // `assigned_to` = SATU penanggung jawab, dipakai rute notifikasi.
        // `assign_group`/`assignees` = keterlihatan per divisi — beda hal,
        // sengaja tidak digabung.
        'assigned_to', 'created_by',
        'assign_group', 'assignees', 'origin_division',
        // Diisi OTOMATIS dari `requester_email` (lihat booted()). Ada di sini
        // hanya supaya jalur yang mengoper larik penuh tidak tertolak;
        // nilainya selalu ditimpa server.
        'requester_email_hash',
        // Pasal 39 ayat (5): "Penyandang Disabilitas DAN/ATAU wali ... dapat
        // mengajukan". Kata dan/atau itu mengunci bawaannya 'subjek' — portal
        // DSR tidak boleh mewajibkan wali, dan jalur diri-sendiri harus mulus.
        'requester_type', 'requester_relation', 'subject_class',
        // Penanda subjek yang DIWAKILI (surel/ID anak), tersandi. Hash-nya
        // diturunkan otomatis (booted()), sama seperti requester_email_hash.
        //
        // TIDAK ADA di sini, dan memang tidak boleh: guardian_consent_id dan
        // guardian_proof_* — universal CRUD menyalin payload apa adanya, dan
        // gerbang keamanan tidak boleh bisa dilepas lewat payload. Ditulis
        // hanya oleh App\Services\Dsr\BuktiWali.
        'subject_identifier',
        'nda_signed_at', 'nda_signed_doc_id',
        'subject_certificate_doc_id', 'internal_certificate_doc_id',
        'completion_certificate_doc_id',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'responded_at' => 'datetime',
        'closed_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'nda_signed_at' => 'datetime',
        'guardian_proof_verified_at' => 'datetime',
        'subject_data' => 'array',
        'assignees' => 'array',
        // PII Encryption — AES-256-CBC
        'requester_name' => EncryptedString::class,
        'requester_email' => EncryptedString::class,
        'requester_phone' => EncryptedString::class,
        'description' => EncryptedString::class,
        'subject_identifier' => EncryptedString::class,
    ];

    /**
     * Jaga `requester_email_hash` selalu sepakat dengan `requester_email`.
     *
     * Dipasang di MODEL, bukan di tiap pemanggil, karena permohonan DSR lahir
     * dari banyak pintu: formulir publik, kunci API mitra, universal CRUD,
     * kanal surel masuk, dan agen AI. Menaruhnya di satu-dua controller berarti
     * pintu yang terlupa menghasilkan baris tanpa hash — dan baris itu tidak
     * akan pernah ikut terperiksa sebagai duplikat, tanpa tanda apa pun.
     *
     * Kolomnya TIDAK boleh datang dari luar: ia selalu diturunkan dari surel.
     *
     * Alasan yang sama menaruh GERBANG BUKTI WALI di sini: perubahan status
     * datang dari pintu yang sama banyaknya, dan gerbang yang hidup di satu
     * controller akan dilewati pintu yang lain tanpa tanda apa pun.
     */
    protected static function booted(): void
    {
        static::saving(function (self $dsr) {
            if ($dsr->isDirty('requester_email') || $dsr->requester_email_hash === null) {
                $dsr->attributes['requester_email_hash'] = KunciPencarian::hash($dsr->requester_email);
            }
            if ($dsr->isDirty('subject_identifier') || ($dsr->subject_identifier !== null && $dsr->subject_identifier_hash === null)) {
                $dsr->attributes['subject_identifier_hash'] = KunciPencarian::hash($dsr->subject_identifier);
            }

            // Pasal 38 ayat (5)–(7): permohonan wali atas hak yang merusak
            // tidak boleh masuk eksekusi sebelum buktinya diterima.
            if ($dsr->isDirty('status')
                && in_array($dsr->status, self::STATUS_EKSEKUSI, true)
                && $dsr->buktiWaliMenghalangi()) {
                throw new BuktiWaliBelumDiterima($dsr);
            }
        });
    }

    /**
     * Permohonan AKTIF dari surel yang sama.
     *
     * Satu-satunya cara yang benar memeriksa duplikat. Mencari lewat
     * `where('requester_email', ...)` tidak akan pernah cocok — kolomnya
     * tersandi dengan IV acak.
     *
     * @param  Builder<DsrRequest>  $query
     */
    public function scopeSurelAktif($query, string $surel)
    {
        return $query
            ->where('requester_email_hash', KunciPencarian::hash($surel))
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'closed']);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** @return BelongsTo<DsrApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(DsrApp::class, 'app_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopes()
    {
        return $this->hasMany(DsrRequestScope::class, 'dsr_request_id');
    }

    public function executions()
    {
        return $this->hasMany(DsrExecution::class, 'dsr_request_id');
    }

    public function ndaSignedDoc()
    {
        return $this->belongsTo(Document::class, 'nda_signed_doc_id');
    }

    public function subjectCertificate()
    {
        return $this->belongsTo(Document::class, 'subject_certificate_doc_id');
    }

    public function internalCertificate()
    {
        return $this->belongsTo(Document::class, 'internal_certificate_doc_id');
    }

    /** @return BelongsTo<GuardianConsent, $this> */
    public function guardianConsent(): BelongsTo
    {
        return $this->belongsTo(GuardianConsent::class, 'guardian_consent_id');
    }

    /**
     * Check if all executions are final (executed/skipped) — bisa close DSR.
     * Failed executions block completion (admin harus retry atau mark skipped explicit).
     */
    public function allExecutionsComplete(): bool
    {
        $executions = $this->executions()->get();
        if ($executions->isEmpty()) {
            return false;
        }

        return $executions->every(fn ($e) => $e->countsAsComplete());
    }

    /**
     * Status flow:
     *   pending_verification → verified → pending_review → in_progress
     *   → pending_execution → completed | rejected | cancelled
     */
    public const VALID_STATUSES = [
        'pending_verification', 'verified', 'pending_review',
        'in_progress', 'pending_execution', 'completed',
        'rejected', 'cancelled',
        // Legacy statuses kept for backward-compat
        'new', 'new_reply', 'replied', 'closed',
    ];

    /** Status yang berarti "permohonan sedang/sudah dijalankan" — gerbang bukti wali berlaku di sini. */
    public const STATUS_EKSEKUSI = ['in_progress', 'pending_execution', 'completed'];

    public const REQUEST_TYPES = [
        'access', 'correction', 'rectification', 'deletion', 'erasure',
        'portability', 'restriction', 'objection', 'withdraw_consent', 'info',
        // Keberatan atas keputusan yang HANYA didasarkan pemrosesan otomatis /
        // pemrofilan (PP 33/2026 Pasal 93). Ditangani via alur tinjauan khusus
        // (DsrAutomatedDecisionController) untuk memenuhi Pasal 94(3) & 95.
        'automated_decision_objection',
    ];

    public const TYPE_AUTOMATED_DECISION = 'automated_decision_objection';

    /**
     * Hak yang MERUSAK bila dijalankan atas nama orang yang salah — garis
     * Paradoks Wali (lihat App\Services\Dsr\BuktiWali). Akses, portabilitas,
     * dan info sengaja TIDAK ada di sini: tidak diblokir otomatis, tetapi
     * bukti yang menunggu terlihat jelas dan DPO yang memutuskan.
     */
    public const HAK_MERUSAK = [
        'deletion', 'erasure', 'withdraw_consent', 'restriction', 'objection',
        'correction', 'rectification', 'automated_decision_objection',
    ];

    /**
     * Siapa yang mengajukan — Pasal 39 ayat (5) dan Pasal 38 ayat (5)–(7).
     *
     * Bawaannya SUBJEK, dan itu bukan sekadar nilai default yang nyaman: kalau
     * portal DSR mewajibkan wali, kita melanggar pasal yang sedang kita bantu
     * penuhi. Jalur 'subjek' harus mulus — tanpa pertanyaan tambahan, tanpa
     * verifikasi ekstra dibanding pemohon lain.
     */
    public const PEMOHON_SUBJEK = 'subjek';

    public const PEMOHON_WALI = 'wali';

    public const PEMOHON_PENDAMPING = 'pendamping';

    public const PEMOHON = [self::PEMOHON_SUBJEK, self::PEMOHON_WALI, self::PEMOHON_PENDAMPING];

    /** Keadaan bukti kewenangan wali. */
    public const BUKTI_TIDAK_PERLU = 'tidak_perlu';

    public const BUKTI_OTOMATIS = 'otomatis';

    public const BUKTI_MENUNGGU = 'menunggu';

    public const BUKTI_DITERIMA = 'diterima';

    public const BUKTI_DITOLAK = 'ditolak';

    public const BUKTI = [
        self::BUKTI_TIDAK_PERLU, self::BUKTI_OTOMATIS, self::BUKTI_MENUNGGU,
        self::BUKTI_DITERIMA, self::BUKTI_DITOLAK,
    ];

    /**
     * Permohonan ini butuh bukti kewenangan wali?
     *
     * Hanya jalur 'wali'. Pendamping BUKAN pengambil keputusan — ia membantu
     * subjek memahami, dan subjeknya sendiri yang mengajukan. Menuntut bukti
     * kewenangan dari pendamping akan memperlakukannya seperti wali, lalu
     * menghambat orang yang sebenarnya mengajukan sendiri.
     */
    public function butuhBuktiWali(): bool
    {
        return $this->requester_type === self::PEMOHON_WALI;
    }

    /** Bukti kewenangan walinya sudah diterima — otomatis atau oleh DPO? */
    public function buktiWaliDiterima(): bool
    {
        return in_array($this->guardian_proof_status, [self::BUKTI_OTOMATIS, self::BUKTI_DITERIMA], true);
    }

    public function hakMerusak(): bool
    {
        return in_array($this->request_type, self::HAK_MERUSAK, true);
    }

    /**
     * Gerbang: permohonan WALI atas hak MERUSAK yang buktinya belum diterima.
     *
     * Jenis pemohon dibaca dari nilai ASLI di basis data bila sedang diubah —
     * mengganti 'wali' menjadi 'subjek' dalam permintaan yang sama dengan
     * perubahan status tidak boleh melepas gerbang.
     */
    public function buktiWaliMenghalangi(): bool
    {
        $tipe = $this->exists && $this->isDirty('requester_type')
            ? $this->getOriginal('requester_type')
            : $this->requester_type;

        return $tipe === self::PEMOHON_WALI
            && $this->hakMerusak()
            && ! $this->buktiWaliDiterima();
    }
}
