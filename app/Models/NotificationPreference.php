<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'kind', 'module', 'channel', 'enabled', 'digest',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** Kind × module × channel match for a user. Defaults to enabled when no row exists. */
    public static function isEnabled(string $userId, string $kind, string $module, string $channel = 'in_app'): bool
    {
        $row = self::where('user_id', $userId)
            ->where('kind', $kind)
            ->whereIn('module', [$module, '*'])
            ->where('channel', $channel)
            ->orderByRaw("CASE WHEN module = ? THEN 0 ELSE 1 END", [$module])
            ->first();

        return $row ? (bool) $row->enabled : true;
    }
}
