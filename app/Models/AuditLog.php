<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'module', 'record_id', 'action', 'user_id', 'user_name',
        'user_role', 'section', 'field', 'changes', 'ip_address',
        'content_hash', 'prev_hash',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    protected static function booted(): void
    {
        // Hash-chain stamping saat creating — supaya prev_hash + content_hash
        // di-set sebelum INSERT ke DB. Service guard sendiri kalau feature
        // disabled (default).
        static::creating(function (self $log) {
            if (! $log->id) {
                // UUID dari HasUuids trait — biasanya di-generate di base
                // Model::creating, tapi untuk safety pre-fill kalau belum.
                $log->id = (string) \Illuminate\Support\Str::uuid();
            }
            if (! $log->created_at) $log->created_at = now();
            if (! $log->updated_at) $log->updated_at = now();

            app(\App\Services\AuditLogChainService::class)->stampOnCreate($log);
        });
    }

    /**
     * Create an audit log entry.
     */
    public static function log(string $module, string $recordId, string $action, ?array $changes = null, ?string $section = null): self
    {
        $user = auth()->user();
        return self::create([
            'module' => $module,
            'record_id' => $recordId,
            'action' => $action,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System',
            'user_role' => $user?->role ?? 'system',
            'section' => $section,
            'changes' => $changes,
            'ip_address' => request()->ip(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
