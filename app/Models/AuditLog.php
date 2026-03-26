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
    ];

    protected $casts = [
        'changes' => 'array',
    ];

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
