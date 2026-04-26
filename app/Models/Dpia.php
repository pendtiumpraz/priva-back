<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\User;

class Dpia extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'regulation_code', 'category_id', 'custom_number', 'registration_number',
        'ropa_id', 'risk_level', 'status',
        'description', 'risk_assessment', 'mitigation_measures',
        'mitigation_tracking',
        'assign_group', 'assignees',
        'wizard_data', 'raci_matrix', 'progress',
        'approver_id', 'approved_at', 'created_by',
        'assigned_roles',
    ];

    protected $casts = [
        'risk_assessment' => 'array',
        'mitigation_measures' => 'array',
        'mitigation_tracking' => 'array',
        'wizard_data' => 'array',
        'raci_matrix' => 'array',
        'assigned_roles' => 'array',
        'assignees' => 'array',
        'approved_at' => 'datetime',
        'progress' => 'float',
    ];

    /**
     * DPIA Wizard: 3 sections matching live platform
     */
    public const WIZARD_SECTIONS = [
        1 => ['key' => 'informasi_dpia', 'label' => 'Informasi DPIA'],
        2 => ['key' => 'koneksi_ropa', 'label' => 'Koneksi ROPA'],
        3 => ['key' => 'potensi_risiko', 'label' => 'Potensi Risiko'],
    ];

    /**
     * 21 risk assessment categories matching live platform
     */
    public const RISK_CATEGORIES = [
        'Dasar Hukum Pemrosesan',
        'Pemrosesan Data Pribadi yang Sah',
        'Kesesuaian Tujuan Pemrosesan',
        'Minimisasi Data',
        'Keakuratan Data',
        'Pembatasan Penyimpanan',
        'Integritas dan Kerahasiaan',
        'Akuntabilitas',
        'Hak Subjek Data - Akses',
        'Hak Subjek Data - Koreksi',
        'Hak Subjek Data - Hapus',
        'Hak Subjek Data - Portabilitas',
        'Persetujuan dan Consent',
        'Transfer Data Lintas Batas',
        'Enkripsi dan Pseudonymization',
        'Kontrol Akses',
        'Monitoring dan Logging',
        'Retensi Data',
        'Manajemen Insiden',
        'Pelatihan dan Kesadaran',
        'Penilaian Dampak Berkala',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class);
    }

    /**
     * Many-to-many: 1 DPIA bisa cover banyak ROPA processing activities
     * (e.g. DPIA "Marketing Stack" cover ROPA outreach + retargeting + lookalike).
     * Source: dpia.wizard_data.koneksi_ropa.connected_ropas → synced ke pivot via
     * ModuleCrudController::syncDpiaRopas().
     */
    public function ropas()
    {
        return $this->belongsToMany(Ropa::class, 'dpia_ropa', 'dpia_id', 'ropa_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'record_id')
            ->where('module', 'dpia')
            ->orderBy('created_at', 'desc');
    }

    public function category()
    {
        return $this->belongsTo(ProcessingCategory::class, 'category_id');
    }

    /**
     * Auto-trigger RTP generation saat DPIA transition ke 'approved'.
     *
     * Kenapa di sini (model booted) bukan di controller:
     *   - Fire di semua code path yang update status (controller, console, observer)
     *   - Idempotent — cek existing items sebelum generate (skip kalau sudah ada)
     *   - Graceful fail — wrap try/catch supaya DPIA save tidak rollback
     */
    protected static function booted(): void
    {
        static::updated(function (Dpia $dpia) {
            try {
                // Guard: kolom mitigation_tracking mungkin belum ada kalau migration
                // belum run (dev env partial migration). Early return supaya save
                // tidak gagal karena observer crash.
                if (!Schema::hasColumn('dpias', 'mitigation_tracking')) return;

                $oldStatus = $dpia->getOriginal('status');
                $newStatus = $dpia->status;

                // Trigger hanya saat transition NON-approved → approved
                if ($newStatus !== 'approved' || $oldStatus === 'approved') return;

                // Skip kalau sudah ada mitigation_tracking (jangan override manual edits)
                $existing = $dpia->mitigation_tracking ?? [];
                if (!empty($existing)) return;

                $generated = self::buildRtpItemsFromDpia($dpia);

                if (!empty($generated)) {
                    // saveQuietly supaya tidak trigger updated event lagi (infinite loop)
                    $dpia->mitigation_tracking = $generated;
                    $dpia->saveQuietly();

                    try {
                        \App\Models\AuditLog::create([
                            'org_id'    => $dpia->org_id,
                            'user_id'   => $dpia->approver_id ?? $dpia->created_by,
                            'module'    => 'dpia',
                            'record_id' => $dpia->id,
                            'action'    => 'rtp.auto_generate_on_approve',
                            'details'   => ['generated_count' => count($generated)],
                        ]);
                    } catch (\Throwable $e) { /* audit log non-critical */ }
                }
            } catch (\Throwable $e) {
                \Log::warning('RTP auto-generate on DPIA approve failed: ' . $e->getMessage(), [
                    'dpia_id' => $dpia->id ?? null,
                ]);
            }
        });
    }

    /**
     * Build RTP items dari berbagai sumber (mitigation_measures → risk_assessment
     * → wizard_data.potensi_risiko). Return empty array kalau tidak ada source
     * data sama sekali.
     *
     * Priority:
     *   1. mitigation_measures[] (column top-level — explicit mitigation)
     *   2. wizard_data.potensi_risiko[category].risk_events[] (wizard section 3
     *      — 1 risk event = 1 treatment item, action derived dari penanganan)
     *
     * Owner default:
     *   Try resolve first PIC dari wizard.informasi_dpia.pic_list via email →
     *   match ke User.id di tenant. Fallback to null (user assigns manually).
     *   RTP owner = R (Responsible) di RACI — eksekusi action. PIC adalah
     *   kandidat paling masuk akal (process owner day-to-day), bukan DPO
     *   (DPO = A Accountable, oversight level).
     */
    public static function buildRtpItemsFromDpia(Dpia $dpia): array
    {
        $now = now()->toIso8601String();
        $actor = $dpia->approver_id ?? $dpia->created_by;
        $defaultOwnerId = self::resolveDefaultRtpOwner($dpia);
        $generated = [];

        // Source 1: mitigation_measures column
        $measures = $dpia->mitigation_measures ?? [];
        $risks = $dpia->risk_assessment ?? [];

        if (!empty($measures)) {
            foreach ($measures as $idx => $measure) {
                $actionText = is_array($measure) ? ($measure['action'] ?? json_encode($measure)) : (string) $measure;
                $riskEvent = $risks[$idx]['risk_event'] ?? $risks[$idx]['event'] ?? ('Risk event #' . ($idx + 1));
                $category = $risks[$idx]['category'] ?? null;
                $likelihood = $risks[$idx]['likelihood'] ?? null;
                $impact = $risks[$idx]['impact'] ?? null;
                $generated[] = self::buildRtpItem($actionText, $riskEvent, $category, $likelihood, $impact, $now, $actor, 'reduce', $defaultOwnerId);
            }
            return $generated;
        }

        // Source 2: wizard_data.potensi_risiko — actual place risk events stored
        // in Privasimu DPIA wizard. Structure:
        //   potensi_risiko: {
        //     "Dasar Hukum Pemrosesan": {
        //       answer: "sebagian",
        //       description: "...",
        //       risk_events: [
        //         { risk_event: "...", dampak: 4, probabilitas: 3, penanganan: "mitigate", notes: "..." }
        //       ]
        //     }
        //   }
        $wizard = $dpia->wizard_data ?? [];
        $potensiRisiko = $wizard['potensi_risiko'] ?? [];

        foreach ($potensiRisiko as $categoryName => $categoryData) {
            $events = $categoryData['risk_events'] ?? [];
            foreach ($events as $ev) {
                // Hanya risk events dengan penanganan='mitigate' yang butuh RTP execution tracking.
                // Accept/transfer/terminate = decisions yang tidak punya ongoing action:
                //   - accept: risiko diterima apa adanya, tidak ada yang dikerjakan
                //   - transfer: dipindah ke pihak 3 (asuransi/vendor), kontraknya yang track bukan RTP
                //   - terminate: hentikan pemrosesan — one-off decision, tidak ongoing
                // Kalau masuk semua ke RTP, dashboard jadi misleading (overdue count, completion %)
                $penanganan = $ev['penanganan'] ?? null;
                if ($penanganan !== 'mitigate') continue;

                $actionText = trim((string)($ev['notes'] ?? '')) !== ''
                    ? $ev['notes']
                    : 'Mitigasi untuk: ' . ($ev['risk_event'] ?? 'risk event');
                $generated[] = self::buildRtpItem(
                    actionText: (string)$actionText,
                    riskEvent: (string)($ev['risk_event'] ?? 'Risk event'),
                    category: (string)$categoryName,
                    likelihood: isset($ev['probabilitas']) ? (int)$ev['probabilitas'] : null,
                    impact: isset($ev['dampak']) ? (int)$ev['dampak'] : null,
                    now: $now,
                    actor: $actor,
                    treatmentType: 'reduce',  // mitigate → reduce (always, since we filter)
                    ownerUserId: $defaultOwnerId,
                );
            }
        }

        return $generated;
    }

    private static function buildRtpItem(
        string $actionText,
        string $riskEvent,
        ?string $category,
        $likelihood,
        $impact,
        string $now,
        $actor,
        string $treatmentType = 'reduce',
        ?string $ownerUserId = null
    ): array {
        $score = ($likelihood && $impact) ? ((int)$likelihood * (int)$impact) : null;
        return [
            'id'                 => (string) Str::uuid(),
            'risk_event'         => mb_substr($riskEvent, 0, 500),
            'category'           => $category,
            'treatment_type'     => $treatmentType,
            'action'             => mb_substr($actionText, 0, 2000),
            'rationale'          => 'Auto-generated dari DPIA wizard data. Owner default = first PIC (Process Owner). Reassign per action sesuai expertise (IT Sec / Legal / HR / dll).',
            'owner_user_id'      => $ownerUserId,
            'priority'           => $score !== null
                ? ($score >= 15 ? 'critical' : ($score >= 10 ? 'high' : 'medium'))
                : 'medium',
            'due_date'           => null,
            'status'             => 'planned',
            'inherent_likelihood'=> $likelihood,
            'inherent_impact'    => $impact,
            'residual_likelihood'=> null,
            'residual_impact'    => null,
            'evidence_files'     => [],
            'notes'              => '',
            'started_at'         => null,
            'completed_at'       => null,
            'verified_at'        => null,
            'verified_by'        => null,
            'created_at'         => $now,
            'updated_at'         => $now,
            'created_by'         => $actor,
        ];
    }

    private static function mapPenangananToTreatmentType(?string $penanganan): string
    {
        return match ($penanganan) {
            'accept'    => 'accept',
            'transfer'  => 'transfer',
            'terminate' => 'avoid',
            default     => 'reduce',  // mitigate atau null → reduce
        };
    }

    /**
     * Resolve default RTP owner berdasar PIC di wizard.informasi_dpia.pic_list.
     * Try first PIC, match ke User table by email (tenant-scoped).
     *
     * Semantic: RTP owner = R (Responsible) di RACI — eksekusi action.
     * PIC = Process Owner (operational day-to-day) → kandidat paling masuk akal.
     * DPO = A (Accountable) → oversight level, NOT default RTP owner.
     */
    public static function resolveDefaultRtpOwner(Dpia $dpia): ?string
    {
        $wizard = $dpia->wizard_data ?? [];
        $info = $wizard['informasi_dpia'] ?? [];
        $picList = $info['pic_list'] ?? [];

        // Try first PIC email
        foreach ($picList as $pic) {
            $email = trim((string)($pic['email'] ?? ''));
            if ($email === '') continue;
            $user = User::where('org_id', $dpia->org_id)
                ->where('email', $email)
                ->first();
            if ($user) return (string) $user->id;
        }

        // Fallback: legacy pic_email (single PIC)
        $legacyEmail = trim((string)($info['pic_email'] ?? ''));
        if ($legacyEmail !== '') {
            $user = User::where('org_id', $dpia->org_id)->where('email', $legacyEmail)->first();
            if ($user) return (string) $user->id;
        }

        // Final fallback: null (user assigns manually di RTP page)
        return null;
    }
}
