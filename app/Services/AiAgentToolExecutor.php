<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\BreachSimulation;
use App\Models\ChatConversation;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentRecord;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\GapAssessment;
use App\Models\InformationSystem;
use App\Models\License;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\User;

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

            // Organization
            'get_organization' => $this->getOrganization($args),
            'update_organization' => $this->updateOrganization($args),

            // Summary
            'get_compliance_summary' => $this->getComplianceSummary($args),

            // SuperAdmin tools
            'list_users' => $this->listUsers($args),
            'list_licenses' => $this->listLicenses($args),
            'list_chat_history' => $this->listChatHistory($args),

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
    private static function sanitizeForAi($data)
    {
        if (is_array($data)) {
            // Associative array: sanitize each key; sequential array: sanitize each element
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            $out = [];
            foreach ($data as $k => $v) {
                if ($isAssoc && is_string($k)) {
                    $out[$k] = self::sanitizeField((string) $k, $v);
                } else {
                    $out[$k] = self::sanitizeForAi($v);
                }
            }

            return $out;
        }

        return $data;
    }

    private static function sanitizeField(string $key, $value)
    {
        if (is_array($value)) {
            return self::sanitizeForAi($value);
        }
        if (! is_string($value)) {
            return $value;
        }

        $k = strtolower($key);

        // Full redact: credentials, tokens, IDs where knowing the value is pure PII
        if (preg_match('/(password|secret|token|api_key|credit_card|cvv|rekening|bank_account)/', $k)) {
            return '[REDACTED]';
        }

        // National ID numbers (NIK/KTP): keep only first 4 + last 2
        if (preg_match('/(^|_)(nik|ktp|national_id|identity_number)($|_)/', $k)) {
            return self::maskDigits($value, 4, 2);
        }

        // Email → keep domain only
        if (preg_match('/(^|_)(email|mail|e_mail)($|_)/', $k) && str_contains($value, '@')) {
            [, $domain] = array_pad(explode('@', $value, 2), 2, '');

            return '***@'.$domain;
        }

        // Phone-like → mask middle digits
        if (preg_match('/(phone|telepon|telp|handphone|hp|mobile|whatsapp|wa_number)/', $k)) {
            return self::maskDigits($value, 2, 2);
        }

        // Name-ish or address → partial mask
        if (preg_match('/(^name$|_name$|^nama$|_nama$|requester_name|full.?name|first.?name|last.?name|address|alamat)/', $k)) {
            return self::maskString($value, 2);
        }

        // Free-text fields (description, notes, response, dll) — neutralisasi
        // pola prompt-injection sebelum di-include ke konteks AI. Ini lapis
        // pertahanan terhadap konten DB yang user-controlled. Lihat
        // neutralizePromptInjection() untuk pola yang di-strip.
        $value = self::neutralizePromptInjection($value);

        // Long narrative text — truncate so LLM sees context but not full body
        if (strlen($value) > 200) {
            return substr($value, 0, 200).'… [truncated for privacy]';
        }

        return $value;
    }

    /**
     * Anti-prompt-injection sanitizer untuk field bebas (description, notes,
     * response, narrative) yang user-controlled di DB. Strip pola yang sering
     * dipakai jailbreak / obfuscated injection sehingga AI tidak akan obey
     * instruksi yang muncul di dalam DATA.
     *
     * Lapisan yang di-strip:
     *  1. Encoded blobs: morse, base64, hex panjang, ROT13 all-caps blok besar
     *  2. Zero-width / invisible Unicode (steganografi)
     *  3. Role-tokens: SYSTEM:/ASSISTANT:/USER:/TOOL: di awal kalimat
     *  4. Markdown system fences: ```system, ```role
     *  5. Custom marker: `===END===`, `=== AKHIR DOKUMEN ===` (mencegah marker spoofing)
     *  6. Control chars (kecuali \n, \t) — strip semua
     *  7. Newline berlebih (>2 berurutan) → di-collapse
     *
     * NB: ini destructive — kalau ada user sah simpan base64 hash (mis. file
     * checksum) di field bebas, juga ke-strip. Trade-off keamanan vs UX yang
     * kami ambil: tampilkan placeholder ⟦encoded⟧ + log warning.
     */
    private static function neutralizePromptInjection(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $original = $text;
        $stripCount = 0;

        // (1) Strip control chars kecuali tab/newline/CR
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // (2) Zero-width chars / BOM / right-to-left override / invisible separators
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $text) ?? $text;

        // (3) Morse code — sequence panjang dot/dash/slash/spasi
        $text = preg_replace_callback('/(?:[.\-]{1,8}[\s\/]){6,}[.\-]{1,8}/u', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:morse⟧';
        }, $text) ?? $text;

        // (4) Base64 blob — 60+ char Base64-alphabet
        $text = preg_replace_callback('/[A-Za-z0-9+\/]{60,}={0,2}/', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:base64⟧';
        }, $text) ?? $text;

        // (5) Hex blob — 40+ char hex murni
        $text = preg_replace_callback('/\b[0-9a-fA-F]{40,}\b/', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:hex⟧';
        }, $text) ?? $text;

        // (6) ROT13-like — all-caps tanpa spasi panjang (heuristik)
        $text = preg_replace_callback('/\b[A-Z]{30,}\b/', function ($m) use (&$stripCount) {
            $stripCount++;
            return '⟦encoded:caps-blob⟧';
        }, $text) ?? $text;

        // (7) Role-token impersonation pada awal baris atau setelah newline
        $text = preg_replace('/(^|\n)\s*(SYSTEM|ASSISTANT|USER|TOOL|FUNCTION)\s*:\s*/i', '$1[role-strip] ', $text) ?? $text;

        // (8) Markdown fenced with system/role tag
        $text = preg_replace('/```\s*(?:system|role|instruction|prompt)\b/i', '[fence-strip]', $text) ?? $text;

        // (9) Custom delimiters yang dipakai di file upload context (mencegah
        // attacker palsu `=== AKHIR DOKUMEN ===` lalu inject instruction)
        $text = preg_replace('/(===\s*(?:END|AKHIR|BEGIN|MULAI)\b[^\n]*===)/iu', '⟦marker-strip⟧', $text) ?? $text;

        // (10) Common jailbreak phrases (lowercase-match supaya simple)
        $jailbreakPhrases = [
            '/ignore (?:all )?(?:previous|prior|above) (?:instructions?|prompts?|rules?)/i',
            '/disregard (?:all )?(?:previous|prior|above)/i',
            '/abaikan (?:semua )?(?:instruksi|perintah|aturan) (?:sebelumnya|di atas)/i',
            '/lupakan (?:semua )?(?:instruksi|perintah|aturan) (?:sebelumnya|di atas)/i',
            '/forget (?:all )?(?:previous|prior|earlier)/i',
            '/you are (?:now )?(?:a )?(?:new|different|jailbroken)/i',
            '/act as (?:a )?(?:DAN|developer mode|unrestricted)/i',
            '/(?:bypass|override) (?:safety|guardrail|filter|approval)/i',
        ];
        foreach ($jailbreakPhrases as $p) {
            $text = preg_replace($p, '[jailbreak-strip]', $text) ?? $text;
        }

        // (11) Collapse 3+ consecutive newlines (sering dipakai memisahkan
        // "data" dari "instruction" di prompt injection)
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        if ($stripCount > 0 || $text !== $original) {
            \Log::warning('AI sanitizer neutralized suspicious content in tool result', [
                'strip_count' => $stripCount,
                'orig_len' => strlen($original),
                'after_len' => strlen($text),
            ]);
        }

        return $text;
    }

    private static function maskDigits(string $value, int $keepStart, int $keepEnd): string
    {
        $digits = preg_replace('/\D/', '', $value);
        $len = strlen($digits);
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat('*', $len);
        }

        return substr($digits, 0, $keepStart)
            .str_repeat('*', $len - $keepStart - $keepEnd)
            .substr($digits, -$keepEnd);
    }

    private static function maskString(string $value, int $keepStart): string
    {
        $len = strlen($value);
        if ($len <= $keepStart + 1) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, $keepStart).str_repeat('*', $len - $keepStart);
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

            // Organization
            ['type' => 'function', 'function' => ['name' => 'get_organization', 'description' => 'Ambil detail organisasi (nama, alamat, industri, dll). Tidak bisa mengakses credentials.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'update_organization', 'description' => 'Update info organisasi. Field yang diizinkan: name, address, phone, industry, size, website, description. TIDAK BISA mengubah credentials/email/password.', 'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'address' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'industry' => ['type' => 'string'], 'size' => ['type' => 'string'], 'website' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => []]]],

            // Summary
            ['type' => 'function', 'function' => ['name' => 'get_compliance_summary', 'description' => 'Ringkasan compliance seluruh modul: jumlah RoPA, DPIA, Breach, DSR, GAP score, dll.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
        ];
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
            ['type' => 'function', 'function' => ['name' => 'get_organization', 'description' => 'Ambil detail organisasi (nama, alamat, industri, dll).', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_compliance_summary', 'description' => 'Ringkasan compliance seluruh modul: jumlah RoPA, DPIA, Breach, DSR, GAP score, dll.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
        ];
    }
}
