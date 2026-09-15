<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\AssignmentVisibility;
use App\Models\Concerns\BelongsToOrg;
use App\Support\KunciPencarian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'subject_data' => 'array',
        'assignees' => 'array',
        // PII Encryption — AES-256-CBC
        'requester_name' => EncryptedString::class,
        'requester_email' => EncryptedString::class,
        'requester_phone' => EncryptedString::class,
        'description' => EncryptedString::class,
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
     */
    protected static function booted(): void
    {
        static::saving(function (self $dsr) {
            if ($dsr->isDirty('requester_email') || $dsr->requester_email_hash === null) {
                $dsr->attributes['requester_email_hash'] = KunciPencarian::hash($dsr->requester_email);
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

    public function app()
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
}
