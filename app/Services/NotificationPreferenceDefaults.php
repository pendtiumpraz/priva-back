<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Default preference presets per role — called on user creation and
 * from the `reset` endpoint. Defines the opinionated starting state
 * so new users get useful notifications without having to configure.
 *
 * Semantics: rows here OVERRIDE the implicit "all enabled" default.
 * We only seed rows that DISABLE a combination, so a missing row
 * means "still enabled". This keeps the table small.
 */
class NotificationPreferenceDefaults
{
    /**
     * Per-role list of (kind, module, channel, enabled, digest) to seed.
     * Only the DISABLED combinations need explicit rows; everything else
     * stays at the default-enabled baseline.
     */
    private const PRESETS = [
        'root' => [
            // Root: only system + license critical. Everything tenant-level off.
            ['kind' => 'info',    'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'approval',       'channel' => 'in_app', 'enabled' => false],
        ],
        'superadmin' => [
            // Superadmin: platform operations. Tenant modules off except license.
            ['kind' => 'info',    'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'approval',       'channel' => 'in_app', 'enabled' => false],
        ],
        'admin' => [
            // Admin: everything tenant-level. Platform modules off.
            ['kind' => 'info',    'module' => 'license',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'license',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'system',         'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'system',         'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'tenant',         'channel' => 'in_app', 'enabled' => false],
        ],
        'dpo' => [
            // DPO: full compliance coverage. Disable platform noise.
            ['kind' => 'info',    'module' => 'license',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'license',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'system',         'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'tenant',         'channel' => 'in_app', 'enabled' => false],
        ],
        'maker' => [
            // Maker: quiet by default — only personally assigned / mentions.
            ['kind' => 'warning', 'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'breach',         'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'consent',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'license',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'system',         'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'tenant',         'channel' => 'in_app', 'enabled' => false],
        ],
        'viewer' => [
            // Viewer: only mentions.
            ['kind' => 'info',    'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'alert',   'module' => 'ropa',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dpia',           'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'warning', 'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'alert',   'module' => 'dsr',            'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'approval',       'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'license',        'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'system',         'channel' => 'in_app', 'enabled' => false],
            ['kind' => 'info',    'module' => 'tenant',         'channel' => 'in_app', 'enabled' => false],
        ],
    ];

    public static function seedForUser(User $user): int
    {
        $preset = self::PRESETS[$user->role] ?? [];
        $count = 0;
        foreach ($preset as $p) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'kind' => $p['kind'],
                    'module' => $p['module'],
                    'channel' => $p['channel'],
                ],
                [
                    'enabled' => $p['enabled'],
                    'digest' => $p['digest'] ?? 'instant',
                ]
            );
            $count++;
        }
        return $count;
    }
}
