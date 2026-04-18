<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\RoleMenuWhitelist;
use Illuminate\Database\Seeder;

/**
 * Seeds menu_items + role_menu_whitelist from the current hardcoded layout.tsx
 * config. Idempotent: updates existing rows by menu_key, inserts new ones.
 */
class MenuRegistrySeeder extends Seeder
{
    private const ALL = ['root', 'superadmin', 'admin', 'dpo', 'maker', 'viewer'];
    private const COMPLIANCE = ['admin', 'dpo', 'maker', 'viewer'];
    private const EDITOR = ['admin', 'dpo', 'maker'];
    private const PLATFORM_ROOT = ['root']; // strict root-only (platform infra)
    private const PLATFORM_SUPERADMIN = ['root', 'superadmin']; // root inherits
    private const ADMIN_SUPERADMIN = ['root', 'superadmin', 'admin'];

    // Package gates: null → all tiers; array → only listed packages
    private const PKG_AI_ONLY = ['ai', 'ai_agent', 'perpetual'];
    private const PKG_AI_AGENT_ONLY = ['ai_agent', 'perpetual'];
    private const PKG_PRO_UP = ['pro', 'ai', 'ai_agent', 'perpetual'];

    public function run(): void
    {
        $menus = [
            // Menu Utama
            ['menu_key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'LayoutDashboard', 'section' => 'Menu Utama', 'sort' => 10, 'hideable' => false, 'roles' => self::ALL],
            ['menu_key' => 'holding-dashboard', 'label' => 'Holding Dashboard', 'href' => '/holding-dashboard', 'icon' => 'Building2', 'section' => 'Menu Utama', 'sort' => 20, 'roles' => ['root', 'superadmin', 'admin']],
            ['menu_key' => 'gap-assessment', 'label' => 'Gap Assessment', 'href' => '/gap-assessment', 'icon' => 'ClipboardCheck', 'section' => 'Menu Utama', 'sort' => 30, 'roles' => array_merge(['root'], self::COMPLIANCE)],

            // PDP Modules
            ['menu_key' => 'ropa', 'label' => 'ROPA', 'href' => '/ropa', 'icon' => 'FileText', 'section' => 'PDP Modules', 'sort' => 110, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'dpia', 'label' => 'DPIA', 'href' => '/dpia', 'icon' => 'ShieldCheck', 'section' => 'PDP Modules', 'sort' => 120, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'lia', 'label' => 'LIA', 'href' => '/lia', 'icon' => 'Scale', 'section' => 'PDP Modules', 'sort' => 130, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'tia', 'label' => 'TIA', 'href' => '/tia', 'icon' => 'Globe', 'section' => 'PDP Modules', 'sort' => 140, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'maturity', 'label' => 'Maturity Assessment', 'href' => '/maturity', 'icon' => 'BarChart3', 'section' => 'PDP Modules', 'sort' => 150, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'policy-review', 'label' => 'Policy Review', 'href' => '/policy-review', 'icon' => 'BookCheck', 'section' => 'PDP Modules', 'sort' => 160, 'roles' => array_merge(['root'], self::COMPLIANCE)],

            // Data Management
            ['menu_key' => 'data-discovery', 'label' => 'Data Discovery', 'href' => '/data-discovery', 'icon' => 'Scan', 'section' => 'Data Management', 'sort' => 210, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'contract-review', 'label' => 'Contract Review', 'href' => '/contract-review', 'icon' => 'FileSearch', 'section' => 'Data Management', 'sort' => 220, 'roles' => array_merge(['root'], self::EDITOR)],
            ['menu_key' => 'vendor-risk', 'label' => 'Third Party Management', 'href' => '/vendor-risk', 'icon' => 'Building2', 'section' => 'Data Management', 'sort' => 230, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'cross-border', 'label' => 'Cross Border Data Transfer', 'href' => '/cross-border', 'icon' => 'Globe', 'section' => 'Data Management', 'sort' => 240, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'document-import', 'label' => 'Document Import', 'href' => '/document-import', 'icon' => 'FolderUp', 'section' => 'Data Management', 'sort' => 250, 'roles' => array_merge(['root'], self::EDITOR)],

            // Subject Rights
            ['menu_key' => 'dsr', 'label' => 'DSR Request', 'href' => '/dsr', 'icon' => 'UserCheck', 'section' => 'Subject Rights', 'sort' => 310, 'roles' => array_merge(['root'], self::EDITOR)],
            ['menu_key' => 'consent', 'label' => 'Consent Mgmt', 'href' => '/consent', 'icon' => 'FolderLock', 'section' => 'Subject Rights', 'sort' => 320, 'roles' => array_merge(['root'], self::EDITOR)],

            // AI Enterprise (license-gated)
            ['menu_key' => 'ai-agent', 'label' => 'AI Agent', 'href' => '/ai-agent', 'icon' => 'Bot', 'section' => 'AI Enterprise', 'sort' => 410, 'roles' => self::ALL, 'packages' => self::PKG_AI_AGENT_ONLY],
            ['menu_key' => 'ai-credits', 'label' => 'AI Credits', 'href' => '/ai-credits', 'icon' => 'Zap', 'section' => 'AI Enterprise', 'sort' => 420, 'roles' => ['root', 'superadmin', 'admin'], 'packages' => self::PKG_AI_ONLY],

            // Data Security
            ['menu_key' => 'breach', 'label' => 'Data Breach Mgmt', 'href' => '/breach', 'icon' => 'AlertTriangle', 'section' => 'Data Security', 'sort' => 510, 'roles' => array_merge(['root'], self::COMPLIANCE)],
            ['menu_key' => 'simulation', 'label' => 'Fire Drill', 'href' => '/simulation', 'icon' => 'Flame', 'section' => 'Data Security', 'sort' => 520, 'roles' => self::ALL],
            ['menu_key' => 'security', 'label' => 'Data Security Posture Mgmt', 'href' => '/security', 'icon' => 'ShieldCheck', 'section' => 'Data Security', 'sort' => 530, 'roles' => array_merge(['root'], self::COMPLIANCE)],

            // Developer Center
            ['menu_key' => 'feature-requests', 'label' => 'Feature Request', 'href' => '/feature-requests', 'icon' => 'Lightbulb', 'section' => 'Developer Center', 'sort' => 610, 'roles' => self::ALL],
            ['menu_key' => 'feature-documentation', 'label' => 'Feature Docs', 'href' => '/feature-documentation', 'icon' => 'BookOpen', 'section' => 'Developer Center', 'sort' => 620, 'roles' => self::ALL],
            ['menu_key' => 'docs', 'label' => 'Architecture', 'href' => '/docs', 'icon' => 'BookOpen', 'section' => 'Developer Center', 'sort' => 630, 'roles' => self::ALL],

            // Platform (ROOT-only) — 4 dangerous features
            ['menu_key' => 'system-update', 'label' => 'System Update (OTA)', 'href' => '/system-update', 'icon' => 'RefreshCw', 'section' => 'Platform (Root)', 'sort' => 710, 'roles' => self::PLATFORM_ROOT],
            ['menu_key' => 'maintenance', 'label' => 'Web Terminal', 'href' => '/maintenance', 'icon' => 'TerminalSquare', 'section' => 'Platform (Root)', 'sort' => 720, 'roles' => self::PLATFORM_ROOT],
            ['menu_key' => 'system-logs', 'label' => 'System Logs (AI)', 'href' => '/system-logs', 'icon' => 'Server', 'section' => 'Platform (Root)', 'sort' => 730, 'roles' => self::PLATFORM_ROOT],
            ['menu_key' => 'api-hub', 'label' => 'API Hub', 'href' => '/api-hub', 'icon' => 'Globe', 'section' => 'Platform (Root)', 'sort' => 740, 'roles' => self::PLATFORM_ROOT],
            ['menu_key' => 'menu-control', 'label' => 'Menu Control Matrix', 'href' => '/menu-control', 'icon' => 'Layers', 'section' => 'Platform (Root)', 'sort' => 750, 'roles' => self::PLATFORM_ROOT],

            // Superadmin (not root)
            ['menu_key' => 'tenant-offboard', 'label' => 'Tenant Offboarding', 'href' => '/tenant-offboard', 'icon' => 'Archive', 'section' => 'Superadmin', 'sort' => 800, 'roles' => self::PLATFORM_SUPERADMIN],
            ['menu_key' => 'license', 'label' => 'License & Billing', 'href' => '/license', 'icon' => 'Key', 'section' => 'Superadmin', 'sort' => 810, 'roles' => self::ADMIN_SUPERADMIN],
            ['menu_key' => 'chat-history', 'label' => 'Master AI Audit', 'href' => '/chat-history', 'icon' => 'MessageSquare', 'section' => 'Superadmin', 'sort' => 820, 'roles' => self::PLATFORM_SUPERADMIN],
            ['menu_key' => 'feature-status', 'label' => 'Feature Status', 'href' => '/feature-status', 'icon' => 'Layers', 'section' => 'Superadmin', 'sort' => 830, 'roles' => self::PLATFORM_SUPERADMIN],

            // Organisasi
            ['menu_key' => 'users', 'label' => 'User Management', 'href' => '/users', 'icon' => 'Users', 'section' => 'Organisasi', 'sort' => 910, 'roles' => self::ADMIN_SUPERADMIN],
            ['menu_key' => 'custom-fields', 'label' => 'Custom Fields', 'href' => '/custom-fields', 'icon' => 'Layers', 'section' => 'Organisasi', 'sort' => 920, 'roles' => self::ADMIN_SUPERADMIN],
            ['menu_key' => 'knowledge-base', 'label' => 'Knowledge Base', 'href' => '/knowledge-base', 'icon' => 'BookOpen', 'section' => 'Organisasi', 'sort' => 930, 'roles' => self::ADMIN_SUPERADMIN],
            ['menu_key' => 'settings', 'label' => 'Pengaturan Tenant', 'href' => '/settings', 'icon' => 'Settings', 'section' => 'Organisasi', 'sort' => 940, 'hideable' => false, 'roles' => self::ALL],
            ['menu_key' => 'menu-preferences', 'label' => 'Menu Preferences', 'href' => '/menu-preferences', 'icon' => 'Layers', 'section' => 'Organisasi', 'sort' => 950, 'roles' => ['root', 'admin']],
        ];

        foreach ($menus as $m) {
            $menu = MenuItem::updateOrCreate(
                ['menu_key' => $m['menu_key']],
                [
                    'label' => $m['label'],
                    'href' => $m['href'],
                    'icon' => $m['icon'] ?? null,
                    'section' => $m['section'] ?? null,
                    'sort_order' => $m['sort'] ?? 0,
                    'hideable' => $m['hideable'] ?? true,
                    'required_packages' => $m['packages'] ?? null,
                ]
            );

            // Seed whitelist per role. Only (re-)insert defaults for rows that don't
            // already exist, so root's manual changes are never overwritten on re-seed.
            foreach ($m['roles'] ?? [] as $role) {
                RoleMenuWhitelist::firstOrCreate(
                    ['menu_id' => $menu->id, 'role' => $role],
                    ['is_allowed' => true]
                );
            }
        }

        // Settings sub-tabs (children of 'settings' menu_item)
        $settings = MenuItem::where('menu_key', 'settings')->first();
        if ($settings) {
            $this->seedSettingsTabs($settings->id);
        }
    }

    private function seedSettingsTabs(string $parentId): void
    {
        $tabs = [
            ['key' => 'settings.profile', 'label' => 'My Profile', 'icon' => 'User', 'section' => 'Settings: Account & Profile', 'sort' => 1001, 'roles' => self::ALL],
            ['key' => 'settings.security', 'label' => 'Security', 'icon' => 'Key', 'section' => 'Settings: Account & Profile', 'sort' => 1002, 'roles' => self::ALL],
            ['key' => 'settings.notifications', 'label' => 'Notifications', 'icon' => 'Bell', 'section' => 'Settings: Account & Profile', 'sort' => 1003, 'roles' => self::ALL],

            ['key' => 'settings.organization', 'label' => 'Organisation', 'icon' => 'Building2', 'section' => 'Settings: Organization', 'sort' => 1010, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.departments', 'label' => 'Departments', 'icon' => 'GitBranch', 'section' => 'Settings: Organization', 'sort' => 1011, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.positions', 'label' => 'Positions', 'icon' => 'Briefcase', 'section' => 'Settings: Organization', 'sort' => 1012, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.master_data', 'label' => 'Master Application', 'icon' => 'Database', 'section' => 'Settings: Organization', 'sort' => 1013, 'roles' => self::ADMIN_SUPERADMIN],

            ['key' => 'settings.compliance', 'label' => 'Compliance', 'icon' => 'Shield', 'section' => 'Settings: Compliance', 'sort' => 1020, 'roles' => ['root', 'superadmin', 'admin', 'dpo']],
            ['key' => 'settings.automation', 'label' => 'System Automation', 'icon' => 'Sparkles', 'section' => 'Settings: Compliance', 'sort' => 1021, 'roles' => self::ADMIN_SUPERADMIN],

            ['key' => 'settings.sso', 'label' => 'Single Sign-On (SSO)', 'icon' => 'Shield', 'section' => 'Settings: Integration', 'sort' => 1030, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.integrations', 'label' => 'Breach Integrations', 'icon' => 'Zap', 'section' => 'Settings: Integration', 'sort' => 1031, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.roles', 'label' => 'Role Management', 'icon' => 'Users', 'section' => 'Settings: Integration', 'sort' => 1032, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.cloud_storage', 'label' => 'Cloud Storage', 'icon' => 'Cloud', 'section' => 'Settings: Integration', 'sort' => 1033, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.crm_integration', 'label' => 'CRM Integration', 'icon' => 'Link2', 'section' => 'Settings: Integration', 'sort' => 1034, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.developer', 'label' => 'Developer API Keys', 'icon' => 'Key', 'section' => 'Settings: Integration', 'sort' => 1035, 'roles' => self::ADMIN_SUPERADMIN],

            ['key' => 'settings.ai_providers', 'label' => 'AI Providers', 'icon' => 'Cpu', 'section' => 'Settings: AI', 'sort' => 1040, 'roles' => self::PLATFORM_SUPERADMIN],
            ['key' => 'settings.ai_assistant', 'label' => 'AI Assistant', 'icon' => 'Bot', 'section' => 'Settings: AI', 'sort' => 1041, 'roles' => self::ADMIN_SUPERADMIN],
            ['key' => 'settings.credits', 'label' => 'AI Credits', 'icon' => 'Zap', 'section' => 'Settings: AI', 'sort' => 1042, 'roles' => self::ADMIN_SUPERADMIN],
        ];

        foreach ($tabs as $t) {
            $item = MenuItem::updateOrCreate(
                ['menu_key' => $t['key']],
                [
                    'parent_menu_id' => $parentId,
                    'label' => $t['label'],
                    'href' => '/settings#' . str_replace('settings.', '', $t['key']),
                    'icon' => $t['icon'] ?? null,
                    'section' => $t['section'] ?? null,
                    'sort_order' => $t['sort'] ?? 0,
                    'hideable' => true,
                ]
            );
            foreach ($t['roles'] ?? [] as $role) {
                RoleMenuWhitelist::firstOrCreate(
                    ['menu_id' => $item->id, 'role' => $role],
                    ['is_allowed' => true]
                );
            }
        }
    }
}
