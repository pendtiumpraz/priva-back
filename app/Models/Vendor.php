<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Pivots\RopaVendor;
use App\Support\AssignmentScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kolomnya baru (migrasi 2026_09_15_000002) dan belum dikenal analisis statis
 * dari skema — dinyatakan di sini supaya pembacaannya tidak ditandai properti
 * tak terdefinisi. Lihat PenugasanDivisi untuk artinya.
 *
 * @property string|null $origin_division
 */
class Vendor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        // Id pihak ketiga di sistem asal (mis. nomor rekanan di sistem
        // pengadaan). Unik per organisasi → impor ulang & kiriman API
        // memperbarui baris yang sama, bukan membuat kembar.
        'external_ref',
        'type',
        'category',                  // Phase 2 — drives questionnaire bank
        'country',
        'contact_name',
        'contact_email',
        'npwp',
        'alamat',
        'telepon',
        'pic_jabatan',
        'departemen_kontak',
        'bidang',
        'jenis_entitas',
        'website',
        'privacy_policy_url',
        'description',
        'dpa_status',
        'dpa_signed_at',
        'dpa_expires_at',
        'risk_score',
        'risk_level',
        'last_assessed_at',
        'next_assessment_due_at',    // Phase 2 — re-assessment cadence
        'data_shared',
        'services_provided',
        'documents',
        // TPRM Pre-Assessment — PDP scope gate
        'pdp_scope_status',
        'scope_decided_at',
        'scope_decided_by',
        'scope_justification',
        'scope_overridden',
        'scope_approved_by',
        'scope_approved_at',
        // TPRM assignment + division-scoped visibility (mirrors RoPA).
        'assign_group',
        // Lihat catatan yang sama di Ropa: diisi server saat membuat, dibuang
        // dari tiap payload perubahan.
        'origin_division',
        'assignees',
        // Daur hidup pihak ketiga: onboarding → aktif → offboarding.
        'lifecycle_status',
        'owner_user_id',
        'activated_at',
        'terminated_at',
        'termination_reason',
        'offboarding_checklist',
        'offboarded_at',
    ];

    protected $casts = [
        'dpa_signed_at' => 'date',
        'dpa_expires_at' => 'date',
        'last_assessed_at' => 'date',
        'next_assessment_due_at' => 'date',
        'data_shared' => 'array',
        'services_provided' => 'array',
        'documents' => 'array',
        'bidang' => 'array',
        'risk_score' => 'integer',
        // PII Encryption — AES-256-CBC
        'contact_name' => EncryptedString::class,
        'contact_email' => EncryptedString::class,
        'npwp' => EncryptedString::class,
        'telepon' => EncryptedString::class,
        // TPRM Pre-Assessment — PDP scope gate
        'scope_overridden' => 'boolean',
        'scope_decided_at' => 'datetime',
        'scope_approved_at' => 'datetime',
        'assignees' => 'array',
        // Daur hidup
        'activated_at' => 'datetime',
        'terminated_at' => 'datetime',
        'offboarded_at' => 'datetime',
        'offboarding_checklist' => 'array',
    ];

    /**
     * Daur hidup pihak ketiga — status pihak ketiganya sendiri, terpisah dari
     * status ASESMEN. Sebuah asesmen yang disetujui tidak dengan sendirinya
     * membuat pihak ketiga boleh dipakai; itu keputusan tersendiri, dan di
     * sistem ini dijaga oleh syarat "harus punya kontrak yang berlaku".
     */
    public const LIFECYCLE_PROSPECTIVE = 'prospective';

    public const LIFECYCLE_ONBOARDING = 'in_onboarding';

    public const LIFECYCLE_ACTIVE = 'active';

    public const LIFECYCLE_SUSPENDED = 'suspended';

    public const LIFECYCLE_OFFBOARDING = 'offboarding';

    public const LIFECYCLE_TERMINATED = 'terminated';

    public const LIFECYCLES = [
        self::LIFECYCLE_PROSPECTIVE, self::LIFECYCLE_ONBOARDING, self::LIFECYCLE_ACTIVE,
        self::LIFECYCLE_SUSPENDED, self::LIFECYCLE_OFFBOARDING, self::LIFECYCLE_TERMINATED,
    ];

    public const LIFECYCLE_LABELS = [
        self::LIFECYCLE_PROSPECTIVE => 'Calon',
        self::LIFECYCLE_ONBOARDING => 'Proses Onboarding',
        self::LIFECYCLE_ACTIVE => 'Aktif',
        self::LIFECYCLE_SUSPENDED => 'Dihentikan Sementara',
        self::LIFECYCLE_OFFBOARDING => 'Proses Offboarding',
        self::LIFECYCLE_TERMINATED => 'Berakhir',
    ];

    /** Daftar periksa bawaan saat mengakhiri kerja sama (Pasal 47 UU PDP: data dikembalikan atau dimusnahkan). */
    public const OFFBOARDING_STEPS = [
        ['key' => 'data_returned', 'label' => 'Data pribadi dikembalikan atau dimusnahkan'],
        ['key' => 'deletion_evidence', 'label' => 'Bukti pemusnahan/pengembalian diterima'],
        ['key' => 'access_revoked', 'label' => 'Akses sistem dan akun dicabut'],
        ['key' => 'subprocessors_notified', 'label' => 'Subprosesor ikut menghentikan pemrosesan'],
        ['key' => 'final_attestation', 'label' => 'Pernyataan akhir dari pihak ketiga diterima'],
    ];

    // PDP scope gate states.
    public const SCOPE_UNSCREENED = 'unscreened';

    public const SCOPE_IN = 'in_scope';

    public const SCOPE_OUT_PENDING = 'out_of_scope_pending';

    public const SCOPE_OUT = 'out_of_scope';

    /**
     * Peran pihak ketiga menurut UU PDP. Dipakai sebagai peran BAWAAN di
     * registri (`vendors.type`) dan sebagai peran per tautan di pivot
     * `ropa_vendor` — satu pihak ketiga bisa berperan beda di kegiatan berbeda.
     */
    public const ROLE_CONTROLLER = 'controller';

    public const ROLE_PROCESSOR = 'processor';

    public const ROLE_JOINT_CONTROLLER = 'joint_controller';

    public const ROLE_SUB_PROCESSOR = 'sub_processor';

    public const ROLES = [self::ROLE_CONTROLLER, self::ROLE_PROCESSOR, self::ROLE_JOINT_CONTROLLER, self::ROLE_SUB_PROCESSOR];

    /** Label non-teknis per peran — dipakai peta koneksi, ekspor, dan laporan. */
    public const ROLE_LABELS = [
        self::ROLE_CONTROLLER => 'Pengendali',
        self::ROLE_PROCESSOR => 'Prosesor',
        self::ROLE_JOINT_CONTROLLER => 'Pengendali Bersama',
        self::ROLE_SUB_PROCESSOR => 'Subprosesor',
    ];

    /** Ejaan lama/bebas di `vendors.type` → peran baku. */
    private const ROLE_ALIASES = [
        'pengendali' => self::ROLE_CONTROLLER,
        'pemroses' => self::ROLE_PROCESSOR,
        'joint' => self::ROLE_JOINT_CONTROLLER,
        'joint-controller' => self::ROLE_JOINT_CONTROLLER,
        'pengendali_bersama' => self::ROLE_JOINT_CONTROLLER,
        'sub-processor' => self::ROLE_SUB_PROCESSOR,
        'subprocessor' => self::ROLE_SUB_PROCESSOR,
        'subprosesor' => self::ROLE_SUB_PROCESSOR,
    ];

    /** Peran baku dari nilai apa pun; `null` bila tidak dikenali. */
    public static function normalizeRole(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $key = strtolower(trim($value));
        if (in_array($key, self::ROLES, true)) {
            return $key;
        }

        return self::ROLE_ALIASES[$key] ?? null;
    }

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[$role] ?? self::ROLE_LABELS[self::ROLE_PROCESSOR];
    }

    /**
     * Peran TENANT sebagai lawan dari peran pihak ketiga pada kegiatan yang sama.
     *
     * Kewajiban UU PDP mengikuti peran, dan peran itu berpasangan: kalau pihak
     * ketiga memproses atas perintah kita, kitalah pengendalinya; sebaliknya
     * kalau pihak ketiga yang menentukan tujuan dan cara pemrosesan, justru
     * KITA yang jadi prosesor — dan kewajiban kita berbeda sama sekali. Pasangan
     * ini ditaruh di sini supaya backend dan antarmuka tidak menyimpulkannya
     * sendiri-sendiri.
     *
     * Subprosesor dipasangkan ke prosesor: bila pihak ketiga adalah subprosesor,
     * kita berada di posisi prosesor yang mengalihdayakan sebagian pemrosesan.
     */
    public const ROLE_TENANT_COUNTERPART = [
        self::ROLE_PROCESSOR => self::ROLE_CONTROLLER,
        self::ROLE_CONTROLLER => self::ROLE_PROCESSOR,
        self::ROLE_JOINT_CONTROLLER => self::ROLE_JOINT_CONTROLLER,
        self::ROLE_SUB_PROCESSOR => self::ROLE_PROCESSOR,
    ];

    /** Peran tenant bila pihak ketiga berperan `$vendorRole`; null bila tak dikenali. */
    public static function tenantRoleFor(?string $vendorRole): ?string
    {
        return self::ROLE_TENANT_COUNTERPART[$vendorRole] ?? null;
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** Kebalikan Ropa::vendors() — kegiatan pemrosesan yang melibatkan pihak ketiga ini. */
    public function ropas()
    {
        return $this->belongsToMany(Ropa::class, 'ropa_vendor', 'vendor_id', 'ropa_id')
            ->using(RopaVendor::class)
            ->withPivot('role', 'purpose', 'data_shared', 'contract_ref', 'notes', 'org_id')
            ->withTimestamps();
    }

    /** Kontrak dengan pihak ketiga ini (PKS, DPA, NDA, dll.). */
    public function contracts()
    {
        return $this->hasMany(VendorContract::class, 'vendor_id')->orderByDesc('start_at');
    }

    /**
     * Kontrak yang sedang menaungi hari ini dan berkasnya ada. Inilah syarat
     * pihak ketiga boleh berstatus aktif — "yang sudah onboarding wajib punya
     * kontrak" ditegakkan di sini, bukan sekadar diingatkan.
     */
    public function activeContract(): ?VendorContract
    {
        return $this->contracts()
            ->where('status', '!=', VendorContract::STATUS_TERMINATED)
            ->get()
            ->first(fn (VendorContract $c) => $c->has_file && $c->coversToday());
    }

    public function assessments()
    {
        return $this->hasMany(VendorAssessment::class, 'vendor_id')->orderBy('created_at', 'desc');
    }

    public function getLatestAssessment()
    {
        return $this->assessments()->first();
    }

    public function preAssessments()
    {
        return $this->hasMany(VendorPreAssessment::class, 'vendor_id')->orderBy('created_at', 'desc');
    }

    /** Latest (non-trashed) pre-assessment row for this vendor. */
    public function latestPreAssessment()
    {
        return $this->preAssessments()->first();
    }

    /**
     * Division-scoped visibility.
     *
     * Aturannya sama persis dengan RoPA/DPIA dan tinggal di AssignmentScope.
     * Dulu badan metode ini adalah SALINAN TANGAN dari trait AssignmentVisibility
     * yang bedanya hanya satu klausa — bentuk duplikasi yang pasti menyimpang
     * cepat atau lambat. Bedanya sekarang dinyatakan sebagai argumen: pihak
     * ketiga tidak punya kolom `created_by`, jadi klausa pembuat dimatikan.
     *
     * Batas tenant tetap dijaga `where('org_id', ...)` pemanggil — scope ini
     * hanya menambah WHERE, tidak pernah melonggarkan apa pun.
     */
    public function scopeVisibleTo($query, $user)
    {
        AssignmentScope::terapkan($query, $user, pakaiCreatedBy: false);

        return $query;
    }

    /**
     * Delimiter multi-divisi pada `assign_group` — HARUS identik dengan
     * konstanta FE `ASSIGN_DIV_DELIM` (AssignScopeModal.tsx).
     */
    public const ASSIGN_DIV_DELIM = AssignmentScope::DELIM;
}
