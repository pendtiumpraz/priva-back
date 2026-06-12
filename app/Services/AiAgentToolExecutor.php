<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\BreachSimulation;
use App\Models\ChatConversation;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentRecord;
use App\Models\CookieLog;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\GapAssessment;
use App\Models\InformationSystem;
use App\Models\LeakDetection;
use App\Models\License;
use App\Models\LiaAssessment;
use App\Models\MaturityAssessment;
use App\Models\Organization;
use App\Models\PostureFinding;
use App\Models\PostureSnapshot;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPreAssessment;

/**
 * Executes AI Agent tool calls with strict tenant isolation.
 * Every query is filtered by org_id. No credential access allowed.
 */
class AiAgentToolExecutor
{
    /**
     * Tools that mutate tenant data. Callers (AiAgentController) should not
     * execute these from an LLM tool_call round without an explicit user
     * approval step — the agent can propose them, user confirms, controller
     * invokes execute() with $approved = true.
     */
    public const MUTATION_TOOLS = [
        'create_ropa', 'update_ropa',
        'create_dpia', 'update_dpia',
        'create_breach',
        'update_dsr',
        'update_organization',
    ];

    private string $orgId;

    /** UUID user yang me-trigger AI Agent (initiator chat). */
    private ?string $initiatorUserId = null;

    /** Nama user untuk audit trail (mis. "Budi Santoso"). */
    private ?string $initiatorUserName = null;

    /** UUID ChatConversation yang sedang aktif. */
    private ?string $conversationId = null;

    public function __construct(string $orgId)
    {
        $this->orgId = $orgId;
    }

    /**
     * Inject context user + conversation supaya AuditLog yang ditulis oleh
     * executor bisa di-trace ke siapa yang me-trigger AI Agent. Sebelumnya
     * audit log hanya menulis user_name='PRIVASIMU AI Agent' yang opak.
     * Sekarang user_id terisi user asli, user_name mention "(via AI Agent)",
     * dan meta tool/conversation masuk ke field `changes` untuk forensik.
     */
    public function withContext(?string $userId, ?string $userName, ?string $conversationId): self
    {
        $this->initiatorUserId = $userId;
        $this->initiatorUserName = $userName;
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * Build payload dasar AuditLog dengan initiator context. Caller add
     * module/record_id/action/section/changes mereka sendiri lalu spread
     * ke AuditLog::create(). Memastikan setiap AI-driven mutation tercatat
     * dengan siapa initiator + conversation id-nya.
     */
    private function auditPayload(string $module, string $recordId, string $action, string $section, array $extraChanges = []): array
    {
        $name = $this->initiatorUserName
            ? "{$this->initiatorUserName} (via AI Agent)"
            : '✨ PRIVASIMU AI Agent';

        $meta = ['_ai_meta' => array_filter([
            'conversation_id' => $this->conversationId,
            'initiator_user_id' => $this->initiatorUserId,
            'triggered_at' => now()->toIso8601String(),
        ])];

        return [
            'module' => $module,
            'record_id' => $recordId,
            'action' => $action,
            'user_id' => $this->initiatorUserId,
            'user_name' => $name,
            'user_role' => 'ai-agent',
            'section' => $section,
            'changes' => array_merge($meta, $extraChanges),
        ];
    }

    /**
     * Execute a tool by name with given arguments.
     * Returns [result, step_description].
     *
     * When $approved is false and the tool is in MUTATION_TOOLS, the call is
     * NOT executed — we return a `pending_approval` envelope so the frontend
     * can show an approve/cancel prompt. The controller then re-invokes
     * execute() with $approved=true when the user clicks Approve.
     */
    public function execute(string $tool, array $args, bool $approved = false): array
    {
        if (in_array($tool, self::MUTATION_TOOLS, true) && ! $approved) {
            // Notify admin role that an AI action is awaiting approval.
            try {
                NotificationService::dispatch(
                    kind: 'alert',
                    severity: 'high',
                    module: 'ai',
                    type: 'ai.approval_required',
                    recipient: 'role:admin',
                    orgId: $this->orgId,
                    title: "🤖 AI butuh persetujuan: {$tool}",
                    body: 'Aksi AI yang memodifikasi data menunggu approval. Periksa detail di chat.',
                    actionUrl: '/ai-agent',
                    metadata: ['tool' => $tool]
                );
            } catch (\Throwable $e) {
                \Log::warning('AI notif failed: '.$e->getMessage());
            }

            return [
                [
                    'pending_approval' => true,
                    'tool' => $tool,
                    'proposed_args' => self::sanitizeForAi($args),
                    'message' => 'Aksi ini akan memodifikasi data. User harus approve di UI sebelum dijalankan.',
                ],
                "⏸ Approval dibutuhkan untuk: {$tool}",
            ];
        }

        // Strip dangerous destructive flags the LLM might try to pass.
        // Hard delete is never allowed from the agent — only soft delete via
        // tenant UI by an authenticated human user.
        foreach (['deleted_at', 'force_delete', 'hard_delete', '_delete'] as $bannedKey) {
            unset($args[$bannedKey]);
        }

        [$result, $step] = match ($tool) {
            // RoPA
            'list_ropa' => $this->listRopa($args),
            'get_ropa_detail' => $this->getRopaDetail($args),
            'create_ropa' => $this->createRopa($args),
            'update_ropa' => $this->updateRopa($args),

            // DPIA
            'list_dpia' => $this->listDpia($args),
            'get_dpia_detail' => $this->getDpiaDetail($args),
            'create_dpia' => $this->createDpia($args),
            'update_dpia' => $this->updateDpia($args),

            // GAP
            'list_gap' => $this->listGap($args),
            'get_gap_detail' => $this->getGapDetail($args),

            // Discovery
            'list_discovery' => $this->listDiscovery($args),
            'get_discovery_detail' => $this->getDiscoveryDetail($args),

            // Consent
            'list_consent' => $this->listConsent($args),
            'get_consent_stats' => $this->getConsentStats($args),

            // DSR
            'list_dsr' => $this->listDsr($args),
            'get_dsr_detail' => $this->getDsrDetail($args),
            'update_dsr' => $this->updateDsr($args),

            // Breach
            'list_breach' => $this->listBreach($args),
            'get_breach_detail' => $this->getBreachDetail($args),
            'create_breach' => $this->createBreach($args),

            // Drill
            'list_drill' => $this->listDrill($args),
            'get_drill_detail' => $this->getDrillDetail($args),

            // LIA (Legitimate Interest Assessment)
            'list_lia' => $this->listLia($args),
            'get_lia_detail' => $this->getLiaDetail($args),

            // TIA (Transfer Impact Assessment)
            'list_tia' => $this->listTia($args),
            'get_tia_detail' => $this->getTiaDetail($args),

            // Maturity Level Assessment
            'list_maturity' => $this->listMaturity($args),
            'get_maturity_detail' => $this->getMaturityDetail($args),

            // TPRM / Pihak Ketiga (Vendor)
            'list_third_party' => $this->listThirdParty($args),
            'get_third_party_detail' => $this->getThirdPartyDetail($args),
            'get_third_party_pre_assessment' => $this->getThirdPartyPreAssessment($args),

            // Cross-Border Data Transfer
            'list_cross_border' => $this->listCrossBorder($args),
            'get_cross_border_detail' => $this->getCrossBorderDetail($args),

            // Security Posture
            'get_security_posture' => $this->getSecurityPosture($args),
            'list_posture_findings' => $this->listPostureFindings($args),

            // Cookie consent
            'get_cookie_stats' => $this->getCookieStats($args),

            // Leak Detection
            'list_leak_detection' => $this->listLeakDetection($args),

            // Organization
            'get_organization' => $this->getOrganization($args),
            'update_organization' => $this->updateOrganization($args),

            // Summary
            'get_compliance_summary' => $this->getComplianceSummary($args),

            // SuperAdmin tools
            'list_users' => $this->listUsers($args),
            'list_licenses' => $this->listLicenses($args),
            'list_chat_history' => $this->listChatHistory($args),
            'list_organizations' => $this->listOrganizations($args),
            'get_platform_stats' => $this->getPlatformStats($args),

            // RAG / Semantic Search (read-only)
            'search_similar_ropa' => $this->searchSimilarRopa($args),
            'search_similar_dpia' => $this->searchSimilarDpia($args),
            'search_similar_breach' => $this->searchSimilarBreach($args),
            'search_knowledge_base' => $this->searchKb($args),
            'find_related_records' => $this->findRelatedRecords($args),

            default => [['error' => "Tool '{$tool}' tidak dikenali."], "❌ Tool tidak dikenali: {$tool}"],
        };

        // Centralized PII redaction — every tool payload going back to the
        // LLM passes through here. The step description (human string) is not
        // sanitized since it only contains counts/titles that we generate
        // ourselves, not raw record fields.
        if (is_array($result)) {
            $result = self::sanitizeForAi($result);
        }

        return [$result, $step];
    }

    // =============================================
    // PII REDACTION — applied before any record reaches the LLM
    // =============================================
    /**
     * Mask PII-heavy fields recursively so the LLM sees structure but never
     * actual personal data values. Safe keys (status, counts, enums, ids)
     * pass through. Free-text over 200 chars is truncated with a marker.
     */
    /**
     * Delegate ke shared AiContentSanitizer supaya 3 AI surface
     * (Agent, Chat Widget, Avatar) inherit defense yang sama.
     * Lihat app/Services/AiContentSanitizer.php untuk detail layer.
     */
    private static function sanitizeForAi($data)
    {
        return \App\Services\AiContentSanitizer::sanitizeForAi($data);
    }

    /**
     * Delegate ke shared sanitizer. Lihat AiContentSanitizer untuk
     * implementation detail + 11 layer defense lengkap.
     */
    private static function neutralizePromptInjection(string $text): string
    {
        return \App\Services\AiContentSanitizer::neutralize($text);
    }

    // =============================================
    // RoPA
    // =============================================
    private function listRopa(array $args): array
    {
        $records = Ropa::where('org_id', $this->orgId)
            ->select('id', 'registration_number', 'processing_activity', 'status', 'risk_level', 'progress', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar RoPA... ({$records->count()} record ditemukan)"];
    }

    private function getRopaDetail(array $args): array
    {
        $r = Ropa::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'RoPA tidak ditemukan'], '❌ RoPA dengan ID tersebut tidak ditemukan'];
        }

        return [$r->toArray(), "📋 Membaca detail RoPA: {$r->processing_activity}"];
    }

    private function syncRopaWizardData(Ropa $r, array $data): void
    {
        $wizardData = $r->wizard_data ?? [];
        $changed = false;
        if (defined('\App\Models\Ropa::WIZARD_SECTIONS')) {
            foreach (Ropa::WIZARD_SECTIONS as $section) {
                $key = $section['key'];
                if (! isset($wizardData[$key])) {
                    $wizardData[$key] = [];
                }
                foreach ($section['fields'] ?? [] as $field) {
                    if (isset($data[$field])) {
                        $wizardData[$key][$field] = $data[$field];
                        $changed = true;
                    }
                }
            }
        }
        if ($changed) {
            $r->wizard_data = $wizardData;
            if (method_exists($r, 'calculateProgress')) {
                $r->progress = $r->calculateProgress();
            }
            $r->save();
        }
    }

    private function createRopa(array $args): array
    {
        $forbidden = ['org_id', 'id'];
        $data = array_diff_key($args, array_flip($forbidden));
        $data['org_id'] = $this->orgId;
        $data['registration_number'] = $data['registration_number'] ?? 'ROPA-AI-'.date('Y').'-'.rand(100, 999);

        // Extract wizard_data before creating (it's a JSON column)
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);

        $r = Ropa::create($data);

        // If agent provided wizard_data, write it directly
        if ($wizardData && is_array($wizardData)) {
            $r->wizard_data = $wizardData;
            $r->save();
        } else {
            $this->syncRopaWizardData($r, $data);
        }

        // Sprint E4 — recompute risk from 7-step wizard triggers.
        $this->applyAutoRisk($r);

        try {
            AuditLog::create($this->auditPayload('ropa', $r->id, 'created', 'Automated AI Creation'));
        } catch (\Exception $e) {
        }

        return [$r->fresh()->toArray(), "✏️ Membuat RoPA baru: {$r->processing_activity} (risk: {$r->risk_level})"];
    }

    private function updateRopa(array $args): array
    {
        $r = Ropa::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'RoPA tidak ditemukan'], '❌ RoPA tidak ditemukan untuk diupdate'];
        }
        $forbidden = ['org_id', 'id'];
        $data = array_diff_key($args, array_flip($forbidden));

        // Extract wizard_data before updating
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);

        $r->update($data);

        // If agent provided wizard_data, merge with existing
        if ($wizardData && is_array($wizardData)) {
            $existing = $r->wizard_data ?? [];
            $r->wizard_data = array_replace_recursive($existing, $wizardData);
            $r->save();
        } else {
            $this->syncRopaWizardData($r, $data);
        }

        // Sprint E4 — recompute risk after wizard merge.
        $this->applyAutoRisk($r);

        try {
            AuditLog::create($this->auditPayload('ropa', $r->id, 'updated', 'AI Automated Edit', ['changed_fields' => array_keys($data)]));
        } catch (\Exception $e) {
        }

        return [$r->fresh()->toArray(), "✅ RoPA berhasil diupdate: {$r->processing_activity} (risk: {$r->risk_level})"];
    }

    /**
     * Run the 7-step risk calculator and persist result on the RoPA row.
     * Respects risk_level_locked (user-set manual override).
     */
    private function applyAutoRisk(Ropa $r): void
    {
        if ($r->risk_level_locked) {
            return;
        }
        $wiz = $r->wizard_data ?? [];
        $result = app(RopaRiskCalculator::class)->calculate(is_array($wiz) ? $wiz : []);
        $wiz['risk_triggers'] = [
            'level' => $result['level'],
            'triggers' => $result['triggers'],
            'reasons' => $result['reasons'],
            'computed_at' => now()->toIso8601String(),
        ];
        $r->risk_level = $result['level'];
        $r->wizard_data = $wiz;
        $r->save();
    }

    // =============================================
    // DPIA
    // =============================================
    private function listDpia(array $args): array
    {
        $records = Dpia::where('org_id', $this->orgId)
            ->select('id', 'registration_number', 'risk_level', 'status', 'progress', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar DPIA... ({$records->count()} record ditemukan)"];
    }

    private function getDpiaDetail(array $args): array
    {
        $r = Dpia::where('org_id', $this->orgId)->with('ropa:id,processing_activity')->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'DPIA tidak ditemukan'], '❌ DPIA tidak ditemukan'];
        }

        return [$r->toArray(), "⚠️ Membaca detail DPIA: {$r->registration_number}"];
    }

    private function syncDpiaWizardData(Dpia $r, array $data): void
    {
        $wizardData = $r->wizard_data ?? [];
        $changed = false;

        if (isset($data['description'])) {
            if (! isset($wizardData['informasi_dpia'])) {
                $wizardData['informasi_dpia'] = [];
            }
            $wizardData['informasi_dpia']['description'] = $data['description'];
            $changed = true;
        }
        if (isset($data['ropa_id']) && $data['ropa_id']) {
            if (! isset($wizardData['koneksi_ropa'])) {
                $wizardData['koneksi_ropa'] = [];
            }
            $wizardData['koneksi_ropa']['connected_ropas'] = [$data['ropa_id']];
            $changed = true;
        }

        if ($changed) {
            $r->wizard_data = $wizardData;
            $r->save();
        }
    }

    private function createDpia(array $args): array
    {
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $data['org_id'] = $this->orgId;
        $data['registration_number'] = $data['registration_number'] ?? 'DPIA-AI-'.date('Y').'-'.rand(100, 999);

        // Extract wizard_data before creating
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);

        $r = Dpia::create($data);

        // If agent provided wizard_data (with potensi_risiko), write directly
        if ($wizardData && is_array($wizardData)) {
            $r->wizard_data = $wizardData;
            $r->save();
        } else {
            $this->syncDpiaWizardData($r, $data);
        }

        try {
            AuditLog::create($this->auditPayload('dpia', $r->id, 'created', 'Automated AI Creation'));
        } catch (\Exception $e) {
        }

        return [$r->fresh()->toArray(), "✏️ Membuat DPIA baru: {$r->registration_number}"];
    }

    private function updateDpia(array $args): array
    {
        $r = Dpia::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'DPIA tidak ditemukan'], '❌ DPIA tidak ditemukan'];
        }
        $data = array_diff_key($args, array_flip(['org_id', 'id']));

        // Extract wizard_data before updating
        $wizardData = $data['wizard_data'] ?? null;
        unset($data['wizard_data']);

        $r->update($data);

        // If agent provided wizard_data, merge with existing
        if ($wizardData && is_array($wizardData)) {
            $existing = $r->wizard_data ?? [];
            $r->wizard_data = array_replace_recursive($existing, $wizardData);
            $r->save();
        } else {
            $this->syncDpiaWizardData($r, $data);
        }

        try {
            AuditLog::create($this->auditPayload('dpia', $r->id, 'updated', 'AI Automated Edit', ['changed_fields' => array_keys($data)]));
        } catch (\Exception $e) {
        }

        return [$r->fresh()->toArray(), "✅ DPIA diupdate: {$r->registration_number}"];
    }

    // =============================================
    // GAP Assessment
    // =============================================
    private function listGap(array $args): array
    {
        $records = GapAssessment::where('org_id', $this->orgId)
            ->select('id', 'version', 'overall_score', 'compliance_level', 'progress', 'created_at')
            ->orderBy('created_at', 'desc')->limit(10)->get();

        return [$records->toArray(), "🔍 Mengambil daftar GAP Assessment... ({$records->count()} ditemukan)"];
    }

    private function getGapDetail(array $args): array
    {
        $r = GapAssessment::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'GAP Assessment tidak ditemukan'], '❌ GAP Assessment tidak ditemukan'];
        }

        return [$r->toArray(), "📊 Membaca GAP Assessment v{$r->version} (skor: {$r->overall_score}%)"];
    }

    // =============================================
    // Data Discovery
    // =============================================
    private function listDiscovery(array $args): array
    {
        $records = InformationSystem::where('org_id', $this->orgId)
            ->select('id', 'name', 'source_type', 'scanning_status', 'pdp_alert_count', 'pii_alert_count', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar Information Systems... ({$records->count()} ditemukan)"];
    }

    private function getDiscoveryDetail(array $args): array
    {
        $r = InformationSystem::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'Sistem tidak ditemukan'], '❌ Sistem informasi tidak ditemukan'];
        }

        return [$r->toArray(), "📊 Membaca detail sistem: {$r->name}"];
    }

    // =============================================
    // Consent
    // =============================================
    private function listConsent(array $args): array
    {
        $records = ConsentCollectionPoint::where('org_id', $this->orgId)
            ->select('id', 'name', 'channel', 'is_active', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar Consent Collection Points... ({$records->count()} ditemukan)"];
    }

    private function getConsentStats(array $args): array
    {
        $points = ConsentCollectionPoint::where('org_id', $this->orgId)->pluck('id');
        $total = ConsentRecord::whereIn('collection_point_id', $points)->count();
        $granted = ConsentRecord::whereIn('collection_point_id', $points)->where('is_granted', true)->count();
        $revoked = ConsentRecord::whereIn('collection_point_id', $points)->where('is_granted', false)->count();

        return [
            ['total_records' => $total, 'granted' => $granted, 'revoked' => $revoked, 'collection_points' => $points->count()],
            "📊 Menghitung statistik consent... ({$total} total records)",
        ];
    }

    // =============================================
    // DSR
    // =============================================
    private function listDsr(array $args): array
    {
        $records = DsrRequest::where('org_id', $this->orgId)
            ->select('id', 'request_id', 'request_type', 'requester_name', 'status', 'deadline_at', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar DSR Requests... ({$records->count()} ditemukan)"];
    }

    private function getDsrDetail(array $args): array
    {
        $r = DsrRequest::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'DSR tidak ditemukan'], '❌ DSR Request tidak ditemukan'];
        }

        return [$r->toArray(), "📩 Membaca DSR: {$r->request_id} ({$r->request_type})"];
    }

    private function updateDsr(array $args): array
    {
        $r = DsrRequest::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'DSR tidak ditemukan'], '❌ DSR tidak ditemukan'];
        }
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $r->update($data);

        try {
            AuditLog::create($this->auditPayload('dsr', $r->id, 'updated', 'AI Automated Edit', ['changed_fields' => array_keys($data)]));
        } catch (\Exception $e) {
        }

        return [$r->fresh()->toArray(), "✅ DSR diupdate: {$r->request_id}"];
    }

    // =============================================
    // Breach
    // =============================================
    private function listBreach(array $args): array
    {
        $records = BreachIncident::where('org_id', $this->orgId)
            ->select('id', 'incident_code', 'title', 'severity', 'status', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar Breach Incidents... ({$records->count()} ditemukan)"];
    }

    private function getBreachDetail(array $args): array
    {
        $r = BreachIncident::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'Breach tidak ditemukan'], '❌ Breach Incident tidak ditemukan'];
        }

        return [$r->toArray(), "🚨 Membaca detail breach: {$r->title}"];
    }

    private function createBreach(array $args): array
    {
        $data = array_diff_key($args, array_flip(['org_id', 'id']));
        $data['org_id'] = $this->orgId;
        $data['incident_code'] = $data['incident_code'] ?? 'BRC-AI-'.date('Y').'-'.rand(100, 999);
        $r = BreachIncident::create($data);

        try {
            AuditLog::create($this->auditPayload('breach', $r->id, 'created', 'Automated AI Creation'));
        } catch (\Exception $e) {
        }

        return [$r->toArray(), "✏️ Breach incident baru dicatat: {$r->title}"];
    }

    // =============================================
    // Fire Drill
    // =============================================
    private function listDrill(array $args): array
    {
        $records = BreachSimulation::where('org_id', $this->orgId)
            ->select('id', 'scenario_type', 'scenario_title', 'overall_score', 'status', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar Fire Drill... ({$records->count()} ditemukan)"];
    }

    private function getDrillDetail(array $args): array
    {
        $r = BreachSimulation::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'Drill tidak ditemukan'], '❌ Fire Drill tidak ditemukan'];
        }

        return [$r->toArray(), "🔥 Membaca detail drill: {$r->scenario_title}"];
    }

    // =============================================
    // LIA — Legitimate Interest Assessment
    // =============================================
    private function listLia(array $args): array
    {
        $records = LiaAssessment::where('org_id', $this->orgId)
            ->select('id', 'lia_code', 'title', 'status', 'overall_score', 'assessment_result', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar LIA (Legitimate Interest Assessment)... ({$records->count()} ditemukan)"];
    }

    private function getLiaDetail(array $args): array
    {
        $r = LiaAssessment::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'LIA tidak ditemukan'], '❌ LIA tidak ditemukan'];
        }

        return [$r->toArray(), "⚖️ Membaca detail LIA: {$r->lia_code}"];
    }

    // =============================================
    // TIA — Transfer Impact Assessment
    // =============================================
    private function listTia(array $args): array
    {
        $records = TiaAssessment::where('org_id', $this->orgId)
            ->select('id', 'tia_code', 'title', 'status', 'overall_risk_score', 'overall_risk_level', 'conclusion_verdict', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar TIA (Transfer Impact Assessment)... ({$records->count()} ditemukan)"];
    }

    private function getTiaDetail(array $args): array
    {
        $r = TiaAssessment::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'TIA tidak ditemukan'], '❌ TIA tidak ditemukan'];
        }

        return [$r->toArray(), "🌐 Membaca detail TIA: {$r->tia_code} (risk: {$r->overall_risk_level})"];
    }

    // =============================================
    // Maturity Level Assessment
    // =============================================
    private function listMaturity(array $args): array
    {
        $records = MaturityAssessment::where('org_id', $this->orgId)
            ->select('id', 'title', 'version', 'status', 'overall_score', 'overall_level', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar Maturity Assessment... ({$records->count()} ditemukan)"];
    }

    private function getMaturityDetail(array $args): array
    {
        $r = MaturityAssessment::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'Maturity Assessment tidak ditemukan'], '❌ Maturity Assessment tidak ditemukan'];
        }

        return [$r->toArray(), "📈 Membaca Maturity Assessment: {$r->title} (level {$r->overall_level})"];
    }

    // =============================================
    // TPRM — Pihak Ketiga (Vendor internal slug)
    // =============================================
    private function listThirdParty(array $args): array
    {
        $records = Vendor::where('org_id', $this->orgId)
            ->select('id', 'name', 'type', 'country', 'category', 'risk_score', 'risk_level', 'dpa_status', 'pdp_scope_status', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar pihak ketiga... ({$records->count()} ditemukan)"];
    }

    private function getThirdPartyDetail(array $args): array
    {
        $r = Vendor::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'Pihak ketiga tidak ditemukan'], '❌ Pihak ketiga tidak ditemukan'];
        }
        $data = $r->toArray();
        // contact_email is encrypted PII — drop before it reaches the LLM.
        unset($data['contact_email'], $data['contact_name']);

        return [$data, "🤝 Membaca detail pihak ketiga: {$r->name} (risk: {$r->risk_level})"];
    }

    private function getThirdPartyPreAssessment(array $args): array
    {
        $vendorId = $args['vendor_id'] ?? $args['id'] ?? '';
        $vendor = Vendor::where('org_id', $this->orgId)->find($vendorId);
        if (! $vendor) {
            return [['error' => 'Pihak ketiga tidak ditemukan'], '❌ Pihak ketiga tidak ditemukan'];
        }
        $pre = VendorPreAssessment::where('org_id', $this->orgId)
            ->where('vendor_id', $vendor->id)
            ->select('id', 'vendor_id', 'status', 'suggested_scope', 'final_scope', 'overridden', 'justification', 'filled_by', 'decided_at', 'approved_at', 'rejection_reason', 'created_at')
            ->orderBy('created_at', 'desc')->first();
        if (! $pre) {
            return [['vendor_id' => $vendor->id, 'pre_assessment' => null, 'pdp_scope_status' => $vendor->pdp_scope_status], "ℹ️ Belum ada pra-asesmen untuk {$vendor->name}"];
        }

        return [$pre->toArray(), "🧭 Membaca pra-asesmen pihak ketiga: {$vendor->name} (scope: ".($pre->final_scope ?: $pre->suggested_scope).")"];
    }

    // =============================================
    // Cross-Border Data Transfer
    // =============================================
    private function listCrossBorder(array $args): array
    {
        $records = CrossBorderTransfer::where('org_id', $this->orgId)
            ->select('id', 'destination_country', 'destination_entity', 'transfer_purpose', 'legal_basis', 'status', 'risk_score', 'risk_level', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil daftar Cross-Border Transfer... ({$records->count()} ditemukan)"];
    }

    private function getCrossBorderDetail(array $args): array
    {
        $r = CrossBorderTransfer::where('org_id', $this->orgId)->find($args['id'] ?? '');
        if (! $r) {
            return [['error' => 'Cross-Border Transfer tidak ditemukan'], '❌ Transfer lintas negara tidak ditemukan'];
        }

        return [$r->toArray(), "🌍 Membaca detail transfer ke {$r->destination_country} (risk: {$r->risk_level})"];
    }

    // =============================================
    // Security Posture
    // =============================================
    private function getSecurityPosture(array $args): array
    {
        $snap = PostureSnapshot::where('org_id', $this->orgId)
            ->orderBy('taken_at', 'desc')->orderBy('created_at', 'desc')->first();
        if (! $snap) {
            return [['error' => 'Belum ada snapshot posture'], 'ℹ️ Belum ada snapshot Privacy Posture untuk organisasi ini'];
        }

        return [
            [
                'overall_score' => $snap->overall_score,
                'layer_data_score' => $snap->layer_data_score,
                'layer_process_score' => $snap->layer_process_score,
                'layer_response_score' => $snap->layer_response_score,
                'pillar_breakdown' => $snap->pillar_breakdown,
                'taken_at' => $snap->taken_at,
                'source' => $snap->source,
            ],
            "🛡️ Membaca Privacy Posture terakhir (skor {$snap->overall_score}/100)",
        ];
    }

    private function listPostureFindings(array $args): array
    {
        $q = PostureFinding::where('org_id', $this->orgId);
        if (! empty($args['status'])) {
            $q->where('status', $args['status']);
        }
        $records = $q->select('id', 'title', 'severity', 'status', 'source_pillar', 'sla_due_at', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil temuan Privacy Posture... ({$records->count()} ditemukan)"];
    }

    // =============================================
    // Cookie consent (anonymous, aggregate only)
    // =============================================
    private function getCookieStats(array $args): array
    {
        $base = CookieLog::where('org_id', $this->orgId);
        $total = (clone $base)->count();
        $analytics = (clone $base)->where('choices->analytics', true)->count();
        $marketing = (clone $base)->where('choices->marketing', true)->count();
        $preferences = (clone $base)->where('choices->preferences', true)->count();

        return [
            [
                'total_logs' => $total,
                'accepted_analytics' => $analytics,
                'accepted_marketing' => $marketing,
                'accepted_preferences' => $preferences,
            ],
            "🍪 Menghitung statistik consent cookie... ({$total} log)",
        ];
    }

    // =============================================
    // Leak Detection (scan results)
    // =============================================
    private function listLeakDetection(array $args): array
    {
        $records = LeakDetection::where('org_id', $this->orgId)
            ->select('id', 'system_id', 'table_name', 'match_mode', 'found_count', 'leak_confirmed', 'created_at')
            ->orderBy('created_at', 'desc')->limit(20)->get();

        return [$records->toArray(), "🔍 Mengambil hasil Leak Detection... ({$records->count()} ditemukan)"];
    }

    // =============================================
    // Organization
    // =============================================
    private function getOrganization(array $args): array
    {
        $org = Organization::find($this->orgId);
        if (! $org) {
            return [['error' => 'Organisasi tidak ditemukan'], '❌ Organisasi tidak ditemukan'];
        }
        $data = $org->toArray();
        unset($data['api_key'], $data['license_key']);

        return [$data, "🏢 Membaca detail organisasi: {$org->name}"];
    }

    private function updateOrganization(array $args): array
    {
        $org = Organization::find($this->orgId);
        if (! $org) {
            return [['error' => 'Organisasi tidak ditemukan'], '❌ Organisasi tidak ditemukan'];
        }
        $safe = ['name', 'address', 'phone', 'industry', 'size', 'website', 'description'];
        $data = array_intersect_key($args, array_flip($safe));
        $org->update($data);

        try {
            AuditLog::create($this->auditPayload('organization', $org->id, 'updated', 'AI Automated Edit', ['changed_fields' => array_keys($data)]));
        } catch (\Exception $e) {
        }

        return [$org->fresh()->makeHidden(['api_key', 'license_key'])->toArray(), "✅ Organisasi diupdate: {$org->name}"];
    }

    // =============================================
    // Compliance Summary
    // =============================================
    private function getComplianceSummary(array $args): array
    {
        $ropaCount = Ropa::where('org_id', $this->orgId)->count();
        $dpiaCount = Dpia::where('org_id', $this->orgId)->count();
        $breachCount = BreachIncident::where('org_id', $this->orgId)->count();
        $dsrCount = DsrRequest::where('org_id', $this->orgId)->count();
        $dsrPending = DsrRequest::where('org_id', $this->orgId)->where('status', 'pending')->count();
        $gap = GapAssessment::where('org_id', $this->orgId)->orderBy('created_at', 'desc')->first();
        $gapScore = $gap ? $gap->overall_score : null;
        $drillCount = BreachSimulation::where('org_id', $this->orgId)->count();

        return [[
            'ropa_count' => $ropaCount,
            'dpia_count' => $dpiaCount,
            'breach_count' => $breachCount,
            'dsr_total' => $dsrCount,
            'dsr_pending' => $dsrPending,
            'latest_gap_score' => $gapScore,
            'drill_count' => $drillCount,
        ], '📈 Mengumpulkan ringkasan compliance dari semua modul...'];
    }

    // =============================================
    // SuperAdmin-only tools (read-only)
    // =============================================
    private function listUsers(array $args): array
    {
        $users = User::select('id', 'name', 'role', 'org_id', 'created_at')
            ->orderBy('created_at', 'desc')->limit(50)->get();

        return [$users->toArray(), "👥 Mengambil daftar user... ({$users->count()} user ditemukan)"];
    }

    private function listLicenses(array $args): array
    {
        $licenses = License::select('id', 'license_key', 'org_id', 'package_type', 'status', 'expires_at', 'created_at')
            ->orderBy('created_at', 'desc')->limit(30)->get();

        return [$licenses->toArray(), "🔑 Mengambil daftar license... ({$licenses->count()} license ditemukan)"];
    }

    private function listChatHistory(array $args): array
    {
        $chats = ChatConversation::withCount('messages')
            ->orderBy('last_message_at', 'desc')->limit(30)->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'user_name' => $c->user_name, 'user_email' => $c->user_email,
                'org_id' => $c->org_id, 'status' => $c->status, 'messages_count' => $c->messages_count,
                'last_message_at' => $c->last_message_at,
            ]);

        return [$chats->toArray(), "💬 Mengambil riwayat chat... ({$chats->count()} percakapan ditemukan)"];
    }

    /**
     * Platform-wide org roster (read-only). No credentials/settings exposed —
     * only org identity, lifecycle, and AI-credit posture.
     */
    private function listOrganizations(array $args): array
    {
        $orgs = Organization::select(
            'id', 'name', 'slug', 'industry', 'company_size', 'org_level',
            'lifecycle_status', 'onboarding_completed',
            'ai_credits_remaining', 'ai_credits_monthly', 'created_at'
        )->orderBy('created_at', 'desc')->limit(50)->get();

        return [$orgs->toArray(), "🏢 Mengambil daftar organisasi platform... ({$orgs->count()} organisasi)"];
    }

    /**
     * Platform-level aggregate stats for SuperAdmin monitoring.
     */
    private function getPlatformStats(array $args): array
    {
        $stats = [
            'total_organizations' => Organization::count(),
            'active_organizations' => Organization::where('lifecycle_status', 'active')->count(),
            'total_users' => User::count(),
            'total_licenses' => License::count(),
            'active_licenses' => License::where('status', 'active')->count(),
            'expired_licenses' => License::where('status', 'expired')->count(),
            'license_by_package' => License::selectRaw('package_type, COUNT(*) as c')
                ->groupBy('package_type')->pluck('c', 'package_type')->toArray(),
            'total_conversations' => ChatConversation::count(),
        ];

        return [$stats, '📊 Mengumpulkan statistik platform (organisasi, user, license)...'];
    }

    // =============================================
    // RAG / Semantic Search (read-only, no mutations)
    // =============================================
    /**
     * P1 security: sanitize RAG result chunks sebelum return ke AI.
     * Vector store bisa berisi DB content yang user-controlled (RoPA
     * description, KB content) — kalau attacker inject `SYSTEM: jailbreak`
     * di field tersebut, similarity search return raw → AI execute.
     *
     * Apply neutralize ke content_excerpt + summary + content field di
     * setiap result row.
     */
    private static function sanitizeRagResults(array $results): array
    {
        return array_map(function ($row) {
            if (! is_array($row)) return $row;
            foreach (['content_excerpt', 'content', 'summary', 'description', 'notes'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $row[$field] = \App\Services\AiContentSanitizer::neutralize($row[$field]);
                }
            }
            // metadata array can also contain injected fields
            if (isset($row['metadata']) && is_array($row['metadata'])) {
                $row['metadata'] = \App\Services\AiContentSanitizer::sanitizeForAi($row['metadata']);
            }
            return $row;
        }, $results);
    }

    private function searchSimilarRopa(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $topK = min(10, max(1, (int) ($args['top_k'] ?? 5)));
        if ($query === '') {
            return [['error' => 'query kosong'], '❌ Parameter query wajib diisi'];
        }
        if (! config('ai_embedding.enabled')) {
            return [['error' => 'RAG nonaktif di config sistem'], '⚠️ Semantic search nonaktif (config ai_embedding.enabled=false)'];
        }
        try {
            $results = app(\App\Services\VectorSearchService::class)
                ->search($this->orgId, $query, $topK, ['ropa']);
            $results = self::sanitizeRagResults($results);

            return [['results' => $results], "🔎 Mencari RoPA mirip secara semantik... (".count($results)." hasil)"];
        } catch (\Throwable $e) {
            return [['error' => 'Vector search gagal: '.$e->getMessage()], '❌ Vector search gagal'];
        }
    }

    private function searchSimilarDpia(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $topK = min(10, max(1, (int) ($args['top_k'] ?? 5)));
        if ($query === '') {
            return [['error' => 'query kosong'], '❌ Parameter query wajib diisi'];
        }
        if (! config('ai_embedding.enabled')) {
            return [['error' => 'RAG nonaktif di config sistem'], '⚠️ Semantic search nonaktif (config ai_embedding.enabled=false)'];
        }
        try {
            $results = app(\App\Services\VectorSearchService::class)
                ->search($this->orgId, $query, $topK, ['dpia']);
            $results = self::sanitizeRagResults($results);

            return [['results' => $results], "🔎 Mencari DPIA mirip secara semantik... (".count($results)." hasil)"];
        } catch (\Throwable $e) {
            return [['error' => 'Vector search gagal: '.$e->getMessage()], '❌ Vector search gagal'];
        }
    }

    private function searchSimilarBreach(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $topK = min(10, max(1, (int) ($args['top_k'] ?? 5)));
        if ($query === '') {
            return [['error' => 'query kosong'], '❌ Parameter query wajib diisi'];
        }
        if (! config('ai_embedding.enabled')) {
            return [['error' => 'RAG nonaktif di config sistem'], '⚠️ Semantic search nonaktif (config ai_embedding.enabled=false)'];
        }
        try {
            $results = app(\App\Services\VectorSearchService::class)
                ->search($this->orgId, $query, $topK, ['breach']);
            $results = self::sanitizeRagResults($results);

            return [['results' => $results], "🔎 Mencari Breach mirip secara semantik... (".count($results)." hasil)"];
        } catch (\Throwable $e) {
            return [['error' => 'Vector search gagal: '.$e->getMessage()], '❌ Vector search gagal'];
        }
    }

    private function searchKb(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $topK = min(10, max(1, (int) ($args['top_k'] ?? 5)));
        if ($query === '') {
            return [['error' => 'query kosong'], '❌ Parameter query wajib diisi'];
        }
        if (! config('ai_embedding.enabled')) {
            return [['error' => 'RAG nonaktif di config sistem'], '⚠️ Semantic search nonaktif (config ai_embedding.enabled=false)'];
        }
        try {
            $results = app(\App\Services\VectorSearchService::class)
                ->search($this->orgId, $query, $topK, ['kb', 'kb_shared', 'pasal_uu_pdp']);
            $results = self::sanitizeRagResults($results);

            return [['results' => $results], "📚 Mencari knowledge base & Pasal UU PDP... (".count($results)." hasil)"];
        } catch (\Throwable $e) {
            return [['error' => 'Vector search gagal: '.$e->getMessage()], '❌ Knowledge base search gagal'];
        }
    }

    private function findRelatedRecords(array $args): array
    {
        $sourceType = trim((string) ($args['source_type'] ?? ''));
        $sourceId = trim((string) ($args['source_id'] ?? ''));
        $topK = min(10, max(1, (int) ($args['top_k'] ?? 5)));
        if ($sourceType === '' || $sourceId === '') {
            return [['error' => 'source_type dan source_id wajib'], '❌ source_type dan source_id wajib diisi'];
        }
        if (! config('ai_embedding.enabled')) {
            return [['error' => 'RAG nonaktif'], '⚠️ Semantic search nonaktif (config ai_embedding.enabled=false)'];
        }
        try {
            $results = app(\App\Services\VectorSearchService::class)
                ->findRelated($this->orgId, $sourceType, $sourceId, $topK);
            $results = self::sanitizeRagResults($results);

            return [['results' => $results], "🔗 Mencari record terkait dengan {$sourceType}... (".count($results)." hasil)"];
        } catch (\Throwable $e) {
            return [['error' => 'Find related gagal: '.$e->getMessage()], '❌ Find related gagal'];
        }
    }

    // =============================================
    // Tool Definitions for regular users (compliance tools)
    // =============================================
    public static function getToolDefinitions(): array
    {
        return [
            // RoPA
            ['type' => 'function', 'function' => ['name' => 'list_ropa', 'description' => 'List semua RoPA (Records of Processing Activities) milik organisasi. Tidak butuh parameter.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_ropa_detail', 'description' => 'Ambil detail lengkap dari satu RoPA berdasarkan ID.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'UUID dari RoPA']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_ropa', 'description' => 'Buat RoPA baru. PENTING: risk_level akan DI-COMPUTE OTOMATIS backend dari trigger wizard (AI penuh, otomatis penuh, pemrofilan, teknologi baru, subjek >1000, data spesifik, transfer luar, pernah insiden). Jadi jangan set risk_level manual kecuali diminta. Sertakan wizard_data untuk mengisi form 7-step lengkap.', 'parameters' => ['type' => 'object', 'properties' => [
                'processing_activity' => ['type' => 'string', 'description' => 'Nama aktivitas pemrosesan (wajib)'],
                'entity' => ['type' => 'string'], 'division' => ['type' => 'string'], 'work_unit' => ['type' => 'string'],
                'description' => ['type' => 'string'], 'purpose' => ['type' => 'string'], 'legal_basis' => ['type' => 'string'],
                'risk_level' => ['type' => 'string', 'description' => 'OPSIONAL — backend akan override. HARUS: low | medium | high'],
                'risk_level_locked' => ['type' => 'boolean', 'description' => 'Set true HANYA jika user minta override manual; kalau false backend auto-compute dari wizard'],
                'status' => ['type' => 'string', 'description' => 'HARUS: draft | active | archived'],
                'wizard_data' => ['type' => 'object', 'description' => "Data wizard 7 section. FIELDS:\n"
                    ."detail_pemrosesan: {nama_pemrosesan, entitas, divisi, unit_kerja, deskripsi}\n"
                    ."dpo_team: {kategori_pemrosesan: 'Pengendali Data Pribadi'|'Pemroses Data Pribadi', dpo_list: [{name,email,phone,jabatan}], pic_list: [{name,email,jabatan,divisi}]}\n"
                    ."informasi_pemrosesan: {tujuan, penjelasan, jenis_pemrosesan: array, dasar_pemrosesan: 1 value, sistem_terkait: array of system IDs atau nama,\n"
                    ."  bantuan_ai: 'Ya (Keputusan Sepenuhnya menggunakan AI)'|'Ya (Keputusan Akhir dari Manusia)'|'Sebagian dari Pemrosesan'|'Tidak menggunakan bantuan AI',\n"
                    ."  otomatis: 'Ya, Keputusan Penuh'|'Ya, Keputusan Akhir dari Manusia'|'Sebagian dari Pemrosesan'|'Tidak',\n"
                    ."  pemrofilan: array dari ['Marketing','Advertisement','Penawaran Produk','Peningkatan Pengalaman Pengguna','Personalisasi Konten','Lainnya','Not Applicable'],\n"
                    ."  teknologi_baru: 'Ya'|'Tidak'}\n"
                    ."pengumpulan_data: {sumber_data, jumlah_subjek: '≤ 1.000 subjek'|'> 1.000 subjek', kategori_subjek: array, jenis_data_spesifik: array dari ['Data Kesehatan','Data Biometrik','Data Genetika','Data Catatan Kejahatan','Data Anak','Data Keuangan Pribadi','Data Ras/Etnis','Data Pandangan Politik','Data Agama/Kepercayaan','Data Orientasi Seksual'], jenis_data_umum: array, jenis_data_pii: array}\n"
                    ."penggunaan_penyimpanan: {pihak_pemroses, kategori_pihak: array, cara_pemrosesan, lokasi_penyimpanan, pihak_ketiga: 'Ya'|'Tidak'}\n"
                    ."pengiriman_data: {ada_penerima: 'Ya'|'Tidak', penerima_data, transfer_luar: 'Ya'|'Tidak', negara_tujuan, safeguards}\n"
                    ."retensi_keamanan: {kontrol_keamanan: array, retensi_list: [{policy_id?, name, duration_value, duration_unit: day|month|year|indefinite, trigger_event, disposal_method: delete|anonymize|archive}], prosedur_pemusnahan, pernah_insiden: 'Ya'|'Tidak'}"],
            ], 'required' => ['processing_activity']]]],
            ['type' => 'function', 'function' => ['name' => 'update_ropa', 'description' => 'Update field di RoPA. Backend akan recompute risk_level dari wizard_data setelah save (kecuali risk_level_locked=true). Harus sertakan id.', 'parameters' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string'],
                'processing_activity' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high (akan di-override backend kecuali risk_level_locked=true)'],
                'risk_level_locked' => ['type' => 'boolean'],
                'status' => ['type' => 'string', 'description' => 'HARUS: draft | active | archived'],
                'purpose' => ['type' => 'string'],
                'legal_basis' => ['type' => 'string'],
                'wizard_data' => ['type' => 'object', 'description' => 'Sama format dengan create_ropa.wizard_data'],
            ], 'required' => ['id']]]],

            // DPIA
            ['type' => 'function', 'function' => ['name' => 'list_dpia', 'description' => 'List semua DPIA milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_dpia_detail', 'description' => 'Ambil detail lengkap DPIA berdasarkan ID.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_dpia', 'description' => 'Buat DPIA baru. risk_level HARUS low/medium/high. status HARUS draft/in_progress/approved. Bisa sertakan wizard_data dengan potensi_risiko = object 21 kategori, setiap kategori: {answer: "sudah"|"sebagian"|"belum"|"tidak_berlaku", description: "..."}. Kategori: Dasar Hukum Pemrosesan, Pemrosesan Data Pribadi yang Sah, Kesesuaian Tujuan Pemrosesan, Minimisasi Data, Keakuratan Data, Pembatasan Penyimpanan, Integritas dan Kerahasiaan, Akuntabilitas, Hak Subjek Data - Akses, Hak Subjek Data - Koreksi, Hak Subjek Data - Hapus, Hak Subjek Data - Portabilitas, Persetujuan dan Consent, Transfer Data Lintas Batas, Enkripsi dan Pseudonymization, Kontrol Akses, Monitoring dan Logging, Retensi Data, Manajemen Insiden, Pelatihan dan Kesadaran, Penilaian Dampak Berkala.', 'parameters' => ['type' => 'object', 'properties' => ['description' => ['type' => 'string'], 'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high'], 'status' => ['type' => 'string', 'description' => 'HARUS: draft | in_progress | approved'], 'ropa_id' => ['type' => 'string'], 'wizard_data' => ['type' => 'object']], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'update_dpia', 'description' => 'Update DPIA. Bisa sertakan wizard_data.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'description' => ['type' => 'string'], 'risk_level' => ['type' => 'string', 'description' => 'HARUS: low | medium | high'], 'status' => ['type' => 'string'], 'wizard_data' => ['type' => 'object']], 'required' => ['id']]]],

            // GAP
            ['type' => 'function', 'function' => ['name' => 'list_gap', 'description' => 'List semua GAP Assessment milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_gap_detail', 'description' => 'Ambil detail GAP Assessment (termasuk skor, jawaban, rekomendasi).', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Discovery
            ['type' => 'function', 'function' => ['name' => 'list_discovery', 'description' => 'List semua Information Systems (Data Discovery).', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_discovery_detail', 'description' => 'Detail Information System beserta scan results.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Consent
            ['type' => 'function', 'function' => ['name' => 'list_consent', 'description' => 'List semua Consent Collection Points.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_consent_stats', 'description' => 'Statistik consent: total records, granted, revoked.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],

            // DSR
            ['type' => 'function', 'function' => ['name' => 'list_dsr', 'description' => 'List semua DSR (Data Subject Request).', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_dsr_detail', 'description' => 'Detail DSR Request.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'update_dsr', 'description' => 'Update status DSR. status HARUS: new | new_reply | replied | rejected | closed. request_type HARUS: access | rectification | erasure | portability | restriction | objection.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'status' => ['type' => 'string', 'description' => 'HARUS: new | new_reply | replied | rejected | closed'], 'response' => ['type' => 'string']], 'required' => ['id']]]],

            // Breach
            ['type' => 'function', 'function' => ['name' => 'list_breach', 'description' => 'List semua Breach Incidents.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_breach_detail', 'description' => 'Detail Breach Incident.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'create_breach', 'description' => 'Catat breach incident baru. severity HARUS: low | medium | high | critical. status HARUS: detected | assessing | containment | notification | closed. source HARUS: manual | automated | external_report | monitoring.', 'parameters' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'severity' => ['type' => 'string', 'description' => 'HARUS: low | medium | high | critical'], 'source' => ['type' => 'string', 'description' => 'HARUS: manual | automated | external_report | monitoring'], 'status' => ['type' => 'string', 'description' => 'HARUS: detected | assessing | containment | notification | closed'], 'affected_subjects_count' => ['type' => 'integer'], 'notification_required' => ['type' => 'boolean']], 'required' => ['title']]]],

            // Drill
            ['type' => 'function', 'function' => ['name' => 'list_drill', 'description' => 'List semua Fire Drill / Breach Simulation.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_drill_detail', 'description' => 'Detail Fire Drill beserta skor.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // LIA
            ['type' => 'function', 'function' => ['name' => 'list_lia', 'description' => 'List semua LIA (Legitimate Interest Assessment / Penilaian Kepentingan Sah) milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_lia_detail', 'description' => 'Detail LIA beserta hasil uji (purpose/necessity/balancing test) dan verdict.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // TIA
            ['type' => 'function', 'function' => ['name' => 'list_tia', 'description' => 'List semua TIA (Transfer Impact Assessment) milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_tia_detail', 'description' => 'Detail TIA beserta skor risiko transfer dan verdict (approved/conditional/rejected).', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Maturity
            ['type' => 'function', 'function' => ['name' => 'list_maturity', 'description' => 'List semua Maturity Level Assessment milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_maturity_detail', 'description' => 'Detail Maturity Assessment beserta skor per-domain dan level keseluruhan (1-4).', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // TPRM / Pihak Ketiga
            ['type' => 'function', 'function' => ['name' => 'list_third_party', 'description' => 'List semua pihak ketiga (third party / vendor) milik organisasi beserta skor risiko, status DPA, dan status lingkup PDP (pra-asesmen).', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_third_party_detail', 'description' => 'Detail satu pihak ketiga. TIDAK menampilkan kontak/email (data terenkripsi).', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],
            ['type' => 'function', 'function' => ['name' => 'get_third_party_pre_assessment', 'description' => 'Ambil pra-asesmen (penyaringan lingkup PDP / triage) terbaru untuk satu pihak ketiga: scope yang disarankan vs final, override, dan status approval DPO.', 'parameters' => ['type' => 'object', 'properties' => ['vendor_id' => ['type' => 'string', 'description' => 'UUID pihak ketiga']], 'required' => ['vendor_id']]]],

            // Cross-Border
            ['type' => 'function', 'function' => ['name' => 'list_cross_border', 'description' => 'List semua Cross-Border Data Transfer (transfer data lintas negara) milik organisasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_cross_border_detail', 'description' => 'Detail satu transfer lintas negara beserta dasar hukum, skor risiko, dan safeguards.', 'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]]],

            // Security Posture
            ['type' => 'function', 'function' => ['name' => 'get_security_posture', 'description' => 'Ambil snapshot Privacy/Security Posture terakhir: skor keseluruhan (0-100), skor per-layer (data/process/response), dan breakdown per-pilar.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'list_posture_findings', 'description' => 'List temuan (findings) Privacy Posture yang actionable beserta severity, status, dan SLA. Bisa filter status (open/in_progress/resolved).', 'parameters' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => 'OPSIONAL filter status']], 'required' => []]]],

            // Cookie
            ['type' => 'function', 'function' => ['name' => 'get_cookie_stats', 'description' => 'Statistik consent cookie (anonim): total log, jumlah accept analytics/marketing/preferences.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],

            // Leak Detection
            ['type' => 'function', 'function' => ['name' => 'list_leak_detection', 'description' => 'List hasil scan Leak Detection: tabel yang discan, jumlah match, dan apakah kebocoran terkonfirmasi.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],

            // Organization
            ['type' => 'function', 'function' => ['name' => 'get_organization', 'description' => 'Ambil detail organisasi (nama, alamat, industri, dll). Tidak bisa mengakses credentials.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'update_organization', 'description' => 'Update info organisasi. Field yang diizinkan: name, address, phone, industry, size, website, description. TIDAK BISA mengubah credentials/email/password.', 'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'address' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'industry' => ['type' => 'string'], 'size' => ['type' => 'string'], 'website' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => []]]],

            // Summary
            ['type' => 'function', 'function' => ['name' => 'get_compliance_summary', 'description' => 'Ringkasan compliance seluruh modul: jumlah RoPA, DPIA, Breach, DSR, GAP score, dll.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],

            // RAG / Semantic Search (read-only) — PRIORITAS dipakai untuk pertanyaan
            // "mirip apa", "ada yang serupa", "kasus sejenis" — lebih relevan
            // daripada exact filter list_*. Hasil disertai source_id untuk verifikasi.
            ['type' => 'function', 'function' => [
                'name' => 'search_similar_ropa',
                'description' => 'Cari RoPA milik organisasi yang mirip secara semantik dengan query. Gunakan untuk pertanyaan "RoPA mirip apa", "ada yang serupa dengan X", "cari pemrosesan data karyawan". Lebih relevan daripada list_ropa untuk pencarian konseptual.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Deskripsi atau keyword pencarian semantik (bukan exact match)'],
                        'top_k' => ['type' => 'integer', 'description' => 'Maksimal hasil (default 5, max 10)'],
                    ],
                    'required' => ['query'],
                ],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_similar_dpia',
                'description' => 'Cari DPIA milik organisasi yang mirip secara semantik. Gunakan untuk "DPIA serupa", "kasus risiko sejenis", atau ketika user ingin reference DPIA lama untuk konteks DPIA baru.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Deskripsi atau keyword pencarian semantik'],
                        'top_k' => ['type' => 'integer', 'description' => 'Maksimal hasil (default 5, max 10)'],
                    ],
                    'required' => ['query'],
                ],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_similar_breach',
                'description' => 'Cari Breach Incident milik organisasi yang mirip secara semantik. Gunakan untuk "insiden serupa", "breach sejenis dengan X", lessons learned dari kasus lampau.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Deskripsi atau keyword pencarian semantik'],
                        'top_k' => ['type' => 'integer', 'description' => 'Maksimal hasil (default 5, max 10)'],
                    ],
                    'required' => ['query'],
                ],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'search_knowledge_base',
                'description' => 'Cari di knowledge base internal organisasi, KB shared, dan Pasal UU PDP. Gunakan untuk pertanyaan regulasi ("Pasal berapa", "apa kata UU PDP tentang"), best practices, atau referensi compliance. PRIORITAS gunakan ini sebelum jawab dari memori untuk pertanyaan regulasi.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Pertanyaan atau topik yang dicari (mis. "hak subjek data akses", "Pasal transfer data luar negeri")'],
                        'top_k' => ['type' => 'integer', 'description' => 'Maksimal hasil (default 5, max 10)'],
                    ],
                    'required' => ['query'],
                ],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'find_related_records',
                'description' => 'Cari record lain (RoPA/DPIA/Breach/dll) yang terkait secara semantik dengan satu record sumber. Gunakan setelah get_*_detail untuk eksplorasi konteks: "tampilkan yang terkait dengan RoPA X", "DPIA lain yang relevan".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'source_type' => ['type' => 'string', 'description' => 'HARUS: ropa | dpia | breach | vendor | kb | pasal_uu_pdp | contract | policy'],
                        'source_id' => ['type' => 'string', 'description' => 'UUID record sumber'],
                        'top_k' => ['type' => 'integer', 'description' => 'Maksimal hasil (default 5, max 10)'],
                    ],
                    'required' => ['source_type', 'source_id'],
                ],
            ]],
        ];
    }

    /**
     * Page → tool name mapping untuk page-context filtering.
     * FE pass `current_module` di request body → backend filter tools.
     * Save 60-80% tokens saat user di specific module page.
     */
    private const PAGE_TOOL_MAP = [
        'ropa' => ['list_ropa', 'get_ropa_detail', 'create_ropa', 'update_ropa', 'search_similar_ropa', 'find_related_records'],
        'dpia' => ['list_dpia', 'get_dpia_detail', 'create_dpia', 'update_dpia', 'search_similar_dpia', 'find_related_records'],
        'breach' => ['list_breach', 'get_breach_detail', 'create_breach', 'search_similar_breach', 'find_related_records'],
        'dsr' => ['list_dsr', 'get_dsr_detail', 'update_dsr', 'find_related_records'],
        'gap' => ['list_gap', 'get_gap_detail'],
        'consent' => ['list_consent', 'get_consent_stats', 'get_cookie_stats'],
        'data-discovery' => ['list_discovery', 'get_discovery_detail', 'list_leak_detection'],
        'simulation' => ['list_drill', 'get_drill_detail'],
        'lia' => ['list_lia', 'get_lia_detail'],
        'tia' => ['list_tia', 'get_tia_detail'],
        'maturity' => ['list_maturity', 'get_maturity_detail'],
        'vendor-risk' => ['list_third_party', 'get_third_party_detail', 'get_third_party_pre_assessment'],
        'cross-border' => ['list_cross_border', 'get_cross_border_detail'],
        'security' => ['get_security_posture', 'list_posture_findings'],
    ];

    /** Universal tools yang selalu tersedia (cross-module utility). */
    private const UNIVERSAL_TOOLS = [
        'get_compliance_summary',
        'search_knowledge_base',
    ];

    /** Read-only tool names (filter saat intent = READ_ONLY atau widget mode). */
    public const READ_ONLY_TOOLS = [
        'list_ropa', 'get_ropa_detail',
        'list_dpia', 'get_dpia_detail',
        'list_breach', 'get_breach_detail',
        'list_dsr', 'get_dsr_detail',
        'list_gap', 'get_gap_detail',
        'list_consent', 'get_consent_stats',
        'list_discovery', 'get_discovery_detail',
        'list_drill', 'get_drill_detail',
        'list_lia', 'get_lia_detail',
        'list_tia', 'get_tia_detail',
        'list_maturity', 'get_maturity_detail',
        'list_third_party', 'get_third_party_detail', 'get_third_party_pre_assessment',
        'list_cross_border', 'get_cross_border_detail',
        'get_security_posture', 'list_posture_findings',
        'get_cookie_stats', 'list_leak_detection',
        'get_organization',
        'search_similar_ropa', 'search_similar_dpia', 'search_similar_breach',
        'search_knowledge_base', 'find_related_records',
        'get_compliance_summary',
    ];

    /**
     * Filter tools by module page context. Kalau $module null atau tidak
     * di-recognize, return all tools (default behavior).
     *
     * @param  string|null  $module  Mis. 'ropa', 'dpia', 'breach'
     */
    public static function getToolDefinitionsForPage(?string $module = null): array
    {
        $allTools = self::getToolDefinitions();

        if (! $module || ! isset(self::PAGE_TOOL_MAP[$module])) {
            return $allTools;
        }

        $allowedNames = array_merge(self::PAGE_TOOL_MAP[$module], self::UNIVERSAL_TOOLS);
        return array_values(array_filter($allTools, function ($tool) use ($allowedNames) {
            return in_array($tool['function']['name'] ?? '', $allowedNames, true);
        }));
    }

    /**
     * Filter tools jadi cuma read-only. Dipakai saat intent classifier
     * detect READ_ONLY action, atau di Chat Widget enterprise mode.
     */
    public static function getReadOnlyToolDefinitions(): array
    {
        $allTools = self::getToolDefinitions();
        return array_values(array_filter($allTools, function ($tool) {
            return in_array($tool['function']['name'] ?? '', self::READ_ONLY_TOOLS, true);
        }));
    }

    /**
     * Kombinasi: filter by page + read-only saja.
     */
    public static function getReadOnlyToolDefinitionsForPage(?string $module = null): array
    {
        $pageTools = self::getToolDefinitionsForPage($module);
        return array_values(array_filter($pageTools, function ($tool) {
            return in_array($tool['function']['name'] ?? '', self::READ_ONLY_TOOLS, true);
        }));
    }

    // =============================================
    // Tool Definitions for SuperAdmin (admin/read-only tools only)
    // =============================================
    public static function getSuperAdminToolDefinitions(): array
    {
        return [
            ['type' => 'function', 'function' => ['name' => 'list_users', 'description' => 'List semua user di platform (read-only). Menampilkan nama, role, dan organisasi. TIDAK menampilkan email/password.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'list_licenses', 'description' => 'List semua license yang terdaftar. Menampilkan key, package_type, status, dan expired.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'list_chat_history', 'description' => 'List riwayat chat dari semua user. Menampilkan nama user, jumlah pesan, dan waktu terakhir.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'list_organizations', 'description' => 'List semua organisasi (tenant) di platform: nama, industri, ukuran, status lifecycle, dan sisa AI credit. Read-only, tidak menampilkan credentials.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_platform_stats', 'description' => 'Statistik agregat seluruh platform: total/aktif organisasi, total user, license aktif/expired, dan breakdown license per paket.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_organization', 'description' => 'Ambil detail organisasi (nama, alamat, industri, dll).', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_compliance_summary', 'description' => 'Ringkasan compliance seluruh modul: jumlah RoPA, DPIA, Breach, DSR, GAP score, dll.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
        ];
    }
}
