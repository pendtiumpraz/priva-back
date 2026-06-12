<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;

/**
 * Platform-scoped tool executor for root / superadmin AI control.
 *
 * UNLIKE AiAgentToolExecutor this is NOT org-scoped — it operates across the
 * whole platform. It is only ever constructed for users whose role is
 * root or superadmin (see AiAgentController). Every method re-checks the role
 * server-side so a superadmin cannot reach a root-only tool by guessing a name.
 *
 * Deliberately SCOPED to reversible controls. Irreversible / externally-verified
 * operations are intentionally NOT exposed and remain manual dashboard-only:
 *   - license issuance / activation (needs License-Manager signed payload)
 *   - permanent license revoke
 *   - tenant archive (schedules hard-delete) + ownership transfer (mints admin)
 *
 * All mutations are approval-gated: execute() returns a pending_approval
 * envelope (identical shape to AiAgentToolExecutor) unless $approved=true, so
 * the existing AiAgentController approval loop + approveAction works unchanged.
 */
class PlatformToolExecutor
{
    /** Tools available to root AND superadmin. */
    private const SHARED_TOOLS = [
        'suspend_license', 'reactivate_license',
        'freeze_tenant', 'unfreeze_tenant',
        'adjust_ai_credits',
    ];

    /** Tools available to root ONLY. */
    private const ROOT_ONLY_TOOLS = [
        'list_menu_items', 'get_platform_config',
        'toggle_tenant_entitlement', 'set_platform_config',
    ];

    /** Every platform mutation requires explicit user approval before running. */
    public const PLATFORM_MUTATION_TOOLS = [
        'suspend_license', 'reactivate_license',
        'freeze_tenant', 'unfreeze_tenant',
        'adjust_ai_credits',
        'toggle_tenant_entitlement', 'set_platform_config',
    ];

    /**
     * Allow-list of platform config keys the AI may set, with a coarse type so
     * we can validate before writing. Mirrors PlatformConfigController's schema
     * (subset — only the safe toggles + bounded numerics).
     */
    private const CONFIG_SCHEMA = [
        'features.ai_agent_enabled' => 'bool',
        'features.notifications_enabled' => 'bool',
        'features.consent_webhooks_enabled' => 'bool',
        'ai.default_temperature' => ['num', 0.0, 2.0],
        'ai.default_max_tokens' => ['num', 256, 8000],
        'ai.credits_low_threshold_percent' => ['num', 1, 50],
        'security.idle_timeout_default_minutes' => ['num', 5, 1440],
    ];

    private string $role;
    private ?string $userId;
    private ?string $userName;
    private ?string $conversationId = null;

    public function __construct(string $role, ?string $userId = null, ?string $userName = null)
    {
        $this->role = $role;
        $this->userId = $userId;
        $this->userName = $userName;
    }

    public function withContext(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /** Does this tool belong to the platform executor? Used by the controller to dispatch. */
    public static function handles(string $tool): bool
    {
        return in_array($tool, array_merge(self::SHARED_TOOLS, self::ROOT_ONLY_TOOLS), true);
    }

    /** Tool definitions visible to the given role (root sees more than superadmin). */
    public static function getDefinitions(string $role): array
    {
        $defs = self::allDefinitions();
        $allowed = $role === 'root'
            ? array_merge(self::SHARED_TOOLS, self::ROOT_ONLY_TOOLS)
            : self::SHARED_TOOLS;

        return array_values(array_filter($defs, fn ($d) => in_array($d['function']['name'], $allowed, true)));
    }

    public function execute(string $tool, array $args, bool $approved = false): array
    {
        // Role gate first — never even hint at root-only tools to a superadmin.
        if (in_array($tool, self::ROOT_ONLY_TOOLS, true) && $this->role !== 'root') {
            return [['error' => 'forbidden'], "⛔ Tool '{$tool}' hanya untuk role root."];
        }
        if (! self::handles($tool)) {
            return [['error' => "Tool '{$tool}' tidak dikenali."], "❌ Tool platform tidak dikenali: {$tool}"];
        }

        // Approval gate (same envelope as AiAgentToolExecutor).
        if (in_array($tool, self::PLATFORM_MUTATION_TOOLS, true) && ! $approved) {
            try {
                NotificationService::dispatch(
                    kind: 'alert', severity: 'high', module: 'platform',
                    type: 'ai.platform_approval_required', recipient: 'role:root', orgId: null,
                    title: "🤖 AI butuh persetujuan platform: {$tool}",
                    body: 'Aksi AI yang mengubah konfigurasi platform menunggu approval.',
                    actionUrl: '/ai-agent', metadata: ['tool' => $tool]
                );
            } catch (\Throwable $e) {
                \Log::warning('Platform AI notif failed: '.$e->getMessage());
            }

            return [
                [
                    'pending_approval' => true,
                    'tool' => $tool,
                    'proposed_args' => AiContentSanitizer::sanitizeForAi($args),
                    'message' => 'Aksi platform ini akan mengubah data lintas-tenant. User harus approve di UI sebelum dijalankan.',
                ],
                "⏸ Approval platform dibutuhkan untuk: {$tool}",
            ];
        }

        [$result, $step] = match ($tool) {
            'suspend_license' => $this->suspendLicense($args),
            'reactivate_license' => $this->reactivateLicense($args),
            'freeze_tenant' => $this->freezeTenant($args),
            'unfreeze_tenant' => $this->unfreezeTenant($args),
            'adjust_ai_credits' => $this->adjustAiCredits($args),
            'list_menu_items' => $this->listMenuItems($args),
            'get_platform_config' => $this->getPlatformConfig($args),
            'toggle_tenant_entitlement' => $this->toggleTenantEntitlement($args),
            'set_platform_config' => $this->setPlatformConfig($args),
            default => [['error' => "Tool '{$tool}' tidak dikenali."], "❌ Tool tidak dikenali: {$tool}"],
        };

        if (is_array($result)) {
            $result = AiContentSanitizer::sanitizeForAi($result);
        }

        return [$result, $step];
    }

    private function audit(string $module, string $recordId, string $action, array $changes = []): void
    {
        try {
            AuditLog::create([
                'module' => $module,
                'record_id' => $recordId,
                'action' => $action,
                'user_id' => $this->userId,
                'user_name' => ($this->userName ? "{$this->userName} (via AI Agent)" : '✨ PRIVASIMU AI Agent'),
                'user_role' => 'ai-agent-'.$this->role,
                'section' => 'Platform Control (AI)',
                'changes' => array_merge(['_ai_meta' => array_filter([
                    'conversation_id' => $this->conversationId,
                    'initiator_user_id' => $this->userId,
                    'triggered_at' => now()->toIso8601String(),
                ])], $changes),
            ]);
        } catch (\Throwable $e) {
        }
    }

    // ---------------------------------------------------------------------
    // License (reversible status control only)
    // ---------------------------------------------------------------------
    private function suspendLicense(array $args): array
    {
        $lic = License::find($args['license_id'] ?? '');
        if (! $lic) {
            return [['error' => 'License tidak ditemukan'], '❌ License tidak ditemukan'];
        }
        $prev = $lic->status;
        $lic->update(['status' => 'suspended']);
        $this->audit('license', $lic->id, 'suspended', ['from' => $prev, 'reason' => $args['reason'] ?? null]);

        return [['id' => $lic->id, 'status' => 'suspended', 'previous_status' => $prev], "⏸ License disuspend (sebelumnya: {$prev})"];
    }

    private function reactivateLicense(array $args): array
    {
        $lic = License::find($args['license_id'] ?? '');
        if (! $lic) {
            return [['error' => 'License tidak ditemukan'], '❌ License tidak ditemukan'];
        }
        if ($lic->status === 'revoked') {
            return [['error' => 'License sudah direvoke permanen'], '⛔ License sudah direvoke permanen — aktivasi ulang harus manual lewat License Manager.'];
        }
        $prev = $lic->status;
        $lic->update(['status' => 'active']);
        $this->audit('license', $lic->id, 'reactivated', ['from' => $prev]);

        return [['id' => $lic->id, 'status' => 'active', 'previous_status' => $prev], "▶️ License diaktifkan kembali (sebelumnya: {$prev})"];
    }

    // ---------------------------------------------------------------------
    // Tenant lifecycle (reversible freeze/unfreeze)
    // ---------------------------------------------------------------------
    private function freezeTenant(array $args): array
    {
        $org = Organization::find($args['org_id'] ?? '');
        if (! $org) {
            return [['error' => 'Organisasi tidak ditemukan'], '❌ Organisasi tidak ditemukan'];
        }
        $org->update([
            'lifecycle_status' => 'frozen',
            'offboarded_at' => now(),
            'offboarded_by' => $this->userId,
            'offboard_reason' => $args['reason'] ?? 'pause',
            'offboard_notes' => $args['notes'] ?? null,
        ]);
        $suspended = License::where('org_id', $org->id)->where('status', 'active')->update(['status' => 'suspended']);
        $this->audit('organization', $org->id, 'frozen', ['reason' => $args['reason'] ?? 'pause', 'licenses_suspended' => $suspended]);

        return [['id' => $org->id, 'lifecycle_status' => 'frozen', 'licenses_suspended' => $suspended], "🧊 Tenant '{$org->name}' dibekukan ({$suspended} license disuspend)"];
    }

    private function unfreezeTenant(array $args): array
    {
        $org = Organization::find($args['org_id'] ?? '');
        if (! $org) {
            return [['error' => 'Organisasi tidak ditemukan'], '❌ Organisasi tidak ditemukan'];
        }
        if ($org->lifecycle_status !== 'frozen') {
            return [['error' => 'Tenant tidak dalam status frozen'], "ℹ️ Tenant '{$org->name}' tidak sedang dibekukan (status: {$org->lifecycle_status})"];
        }
        $org->update([
            'lifecycle_status' => 'active',
            'offboarded_at' => null,
            'offboarded_by' => null,
            'offboard_reason' => null,
            'offboard_notes' => null,
        ]);
        $restored = License::where('org_id', $org->id)->where('status', 'suspended')->update(['status' => 'active']);
        $this->audit('organization', $org->id, 'unfrozen', ['licenses_restored' => $restored]);

        return [['id' => $org->id, 'lifecycle_status' => 'active', 'licenses_restored' => $restored], "☀️ Tenant '{$org->name}' diaktifkan kembali ({$restored} license dipulihkan)"];
    }

    private function adjustAiCredits(array $args): array
    {
        $org = Organization::find($args['org_id'] ?? '');
        if (! $org) {
            return [['error' => 'Organisasi tidak ditemukan'], '❌ Organisasi tidak ditemukan'];
        }
        $data = [];
        foreach (['ai_credits_monthly', 'ai_credits_remaining'] as $f) {
            if (isset($args[$f]) && is_numeric($args[$f]) && $args[$f] >= 0) {
                $data[$f] = (float) $args[$f];
            }
        }
        if (! $data) {
            return [['error' => 'tidak ada nilai kredit valid'], '❌ Sertakan ai_credits_monthly dan/atau ai_credits_remaining (angka ≥ 0)'];
        }
        $org->update($data);
        $this->audit('organization', $org->id, 'ai_credits_adjusted', $data);

        return [['id' => $org->id, 'ai_credits_monthly' => $org->ai_credits_monthly, 'ai_credits_remaining' => $org->ai_credits_remaining], "💳 AI credit '{$org->name}' diperbarui"];
    }

    // ---------------------------------------------------------------------
    // Menu entitlement + platform config (root only)
    // ---------------------------------------------------------------------
    private function listMenuItems(array $args): array
    {
        $items = MenuItem::select('id', 'menu_key', 'label', 'section', 'hideable', 'required_packages')
            ->orderBy('section')->orderBy('sort_order')->limit(200)->get();

        return [$items->toArray(), "📋 Mengambil daftar menu registry... ({$items->count()} menu)"];
    }

    private function toggleTenantEntitlement(array $args): array
    {
        $org = Organization::find($args['org_id'] ?? '');
        if (! $org) {
            return [['error' => 'Organisasi tidak ditemukan'], '❌ Organisasi tidak ditemukan'];
        }
        $menu = MenuItem::where('menu_key', $args['menu_key'] ?? '')->first();
        if (! $menu) {
            return [['error' => 'menu_key tidak ditemukan'], "❌ menu_key '".($args['menu_key'] ?? '')."' tidak ada. Pakai list_menu_items untuk lihat key valid."];
        }
        $isEntitled = (bool) ($args['is_entitled'] ?? true);
        $ent = TenantModuleEntitlement::updateOrCreate(
            ['org_id' => $org->id, 'menu_id' => $menu->id],
            [
                'is_entitled' => $isEntitled,
                'valid_until' => $args['valid_until'] ?? null,
                'notes' => $args['notes'] ?? 'Diatur via AI Agent',
            ]
        );
        $this->audit('menu_registry', $ent->id, $isEntitled ? 'entitlement_granted' : 'entitlement_revoked', ['org_id' => $org->id, 'menu_key' => $menu->menu_key]);

        $verb = $isEntitled ? 'diaktifkan' : 'dimatikan';

        return [['org_id' => $org->id, 'menu_key' => $menu->menu_key, 'is_entitled' => $isEntitled], "🔧 Fitur '{$menu->label}' {$verb} untuk '{$org->name}'"];
    }

    private function getPlatformConfig(array $args): array
    {
        $out = [];
        foreach (array_keys(self::CONFIG_SCHEMA) as $key) {
            $out[$key] = AppSetting::get($key);
        }

        return [$out, '⚙️ Membaca konfigurasi platform (allow-listed)...'];
    }

    private function setPlatformConfig(array $args): array
    {
        $key = (string) ($args['key'] ?? '');
        if (! isset(self::CONFIG_SCHEMA[$key])) {
            return [['error' => 'key tidak diizinkan'], "⛔ Key '{$key}' tidak ada di allow-list. Pakai get_platform_config untuk lihat key valid."];
        }
        $rule = self::CONFIG_SCHEMA[$key];
        $raw = $args['value'] ?? null;

        if ($rule === 'bool') {
            $val = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        } else {
            // ['num', min, max]
            if (! is_numeric($raw)) {
                return [['error' => 'value harus angka'], "❌ Value untuk '{$key}' harus angka."];
            }
            $n = (float) $raw;
            if ($n < $rule[1] || $n > $rule[2]) {
                return [['error' => 'di luar rentang'], "❌ Value untuk '{$key}' harus antara {$rule[1]} dan {$rule[2]}."];
            }
            $val = (string) $raw;
        }

        $prev = AppSetting::get($key);
        AppSetting::set($key, $val);
        $this->audit('platform_config', $key, 'config_updated', ['key' => $key, 'from' => $prev, 'to' => $val]);

        return [['key' => $key, 'value' => $val, 'previous' => $prev], "⚙️ Konfigurasi platform '{$key}' = {$val}"];
    }

    // ---------------------------------------------------------------------
    // Definitions
    // ---------------------------------------------------------------------
    private static function allDefinitions(): array
    {
        return [
            ['type' => 'function', 'function' => ['name' => 'suspend_license', 'description' => 'Suspend (nonaktifkan sementara) satu license. REVERSIBLE — bisa diaktifkan lagi dengan reactivate_license. Bukan revoke permanen. Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['license_id' => ['type' => 'string', 'description' => 'UUID license'], 'reason' => ['type' => 'string']], 'required' => ['license_id']]]],
            ['type' => 'function', 'function' => ['name' => 'reactivate_license', 'description' => 'Aktifkan kembali license yang disuspend. Tidak bisa untuk license yang sudah direvoke permanen. Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['license_id' => ['type' => 'string']], 'required' => ['license_id']]]],
            ['type' => 'function', 'function' => ['name' => 'freeze_tenant', 'description' => 'Bekukan (freeze) sebuah tenant/organisasi: set lifecycle_status=frozen dan suspend semua license aktifnya. REVERSIBLE via unfreeze_tenant. reason HARUS: end_of_contract|non_payment|pause|other. Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['org_id' => ['type' => 'string'], 'reason' => ['type' => 'string', 'description' => 'end_of_contract|non_payment|pause|other'], 'notes' => ['type' => 'string']], 'required' => ['org_id']]]],
            ['type' => 'function', 'function' => ['name' => 'unfreeze_tenant', 'description' => 'Aktifkan kembali tenant yang dibekukan: lifecycle_status=active dan pulihkan license yang tadinya disuspend. Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['org_id' => ['type' => 'string']], 'required' => ['org_id']]]],
            ['type' => 'function', 'function' => ['name' => 'adjust_ai_credits', 'description' => 'Sesuaikan kuota AI credit sebuah tenant (jatah bulanan dan/atau sisa). Angka ≥ 0. Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['org_id' => ['type' => 'string'], 'ai_credits_monthly' => ['type' => 'number'], 'ai_credits_remaining' => ['type' => 'number']], 'required' => ['org_id']]]],
            // root only
            ['type' => 'function', 'function' => ['name' => 'list_menu_items', 'description' => '[ROOT] List semua menu di menu registry beserta menu_key — dipakai untuk menentukan key sebelum toggle_tenant_entitlement.', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'get_platform_config', 'description' => '[ROOT] Baca konfigurasi platform yang boleh diubah AI (feature toggle + numerik ber-batas).', 'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => []]]],
            ['type' => 'function', 'function' => ['name' => 'toggle_tenant_entitlement', 'description' => '[ROOT] Aktifkan/matikan satu fitur (menu) untuk satu tenant. Pakai menu_key dari list_menu_items. Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['org_id' => ['type' => 'string'], 'menu_key' => ['type' => 'string'], 'is_entitled' => ['type' => 'boolean'], 'valid_until' => ['type' => 'string', 'description' => 'OPSIONAL tanggal kedaluwarsa (YYYY-MM-DD)'], 'notes' => ['type' => 'string']], 'required' => ['org_id', 'menu_key', 'is_entitled']]]],
            ['type' => 'function', 'function' => ['name' => 'set_platform_config', 'description' => '[ROOT] Ubah satu konfigurasi platform (hanya key dalam allow-list). Butuh approval.', 'parameters' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['key', 'value']]]],
        ];
    }
}
