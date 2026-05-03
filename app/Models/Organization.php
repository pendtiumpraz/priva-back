<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuids, LandlordPinned, SoftDeletes;

    protected $fillable = [
        'parent_id', 'org_level',
        'idle_timeout_enabled', 'idle_timeout_minutes', 'name', 'slug', 'industry', 'logo_url', 'privacy_policy_url',
        'website', 'address', 'phone', 'email', 'settings',
        // Storage & Integrations
        'storage_driver', 'storage_config', 'integration_config',
        // Onboarding
        'business_model', 'company_size', 'data_subjects_type', 'core_systems',
        'has_dpo', 'onboarding_completed',
        // AI Credits
        'ai_credits_monthly', 'ai_credits_remaining', 'ai_credits_purchased', 'ai_credits_reset_at',
        // Lifecycle (offboarding)
        'lifecycle_status', 'offboarded_at', 'offboarded_by', 'offboard_reason', 'offboard_notes',
        'hard_delete_at', 'transferred_from',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'data_subjects_type' => 'array',
            'core_systems' => 'array',
            'has_dpo' => 'boolean',
            'onboarding_completed' => 'boolean',
            'ai_credits_monthly' => 'float',
            'ai_credits_remaining' => 'float',
            'ai_credits_purchased' => 'float',
            'ai_credits_reset_at' => 'datetime',
            'offboarded_at' => 'datetime',
            'hard_delete_at' => 'datetime',
            // PII Encryption — AES-256-CBC
            'phone' => EncryptedString::class,
            'address' => EncryptedString::class,
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class, 'org_id');
    }

    // ============ Holding Hierarchy ============

    public function parent()
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    /**
     * Recursively get all descendants (children, grandchildren, etc.)
     */
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all descendant IDs (flat array) for querying.
     */
    public function getDescendantIds(): array
    {
        $ids = [];
        $children = Organization::where('parent_id', $this->id)->get();
        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }

    /**
     * Check if this org is a holding or sub_holding.
     */
    public function isHolding(): bool
    {
        return in_array($this->org_level, ['holding', 'sub_holding']);
    }

    public function creditLogs()
    {
        return $this->hasMany(AiCreditLog::class, 'org_id');
    }

    public function dbPool()
    {
        return $this->belongsTo(DatabasePool::class, 'db_pool_id');
    }

    public function storagePool()
    {
        return $this->belongsTo(StoragePool::class, 'storage_pool_id');
    }

    public function changeRequests()
    {
        return $this->hasMany(TenantChangeRequest::class, 'org_id');
    }

    public function pendingChangeRequests()
    {
        return $this->hasMany(TenantChangeRequest::class, 'org_id')
            ->where('status', TenantChangeRequest::STATUS_PENDING);
    }

    protected static function booted()
    {
        static::created(function ($organization) {
            $presets = [
                'admin' => [
                    'name' => 'Admin',
                    'desc' => 'Administrator dengan full akses konfigurasi',
                    'permissions' => ['*'],
                ],
                'dpo' => [
                    'name' => 'DPO',
                    'desc' => 'Data Protection Officer untuk review dan approval',
                    'permissions' => ['dashboard', 'ropa', 'dpia', 'dsr', 'breach', 'simulation', 'consent', 'contract-review', 'data-discovery', 'gap-assessment', 'settings'],
                ],
                'maker' => [
                    'name' => 'Maker',
                    'desc' => 'User operasional yang input data RoPA/DPIA',
                    'permissions' => ['dashboard', 'ropa', 'dpia', 'dsr', 'breach', 'consent', 'contract-review', 'data-discovery', 'gap-assessment'],
                ],
                'viewer' => [
                    'name' => 'Viewer',
                    'desc' => 'Akses hanya baca (read-only)',
                    'permissions' => ['dashboard', 'ropa', 'dpia'],
                ],
            ];

            foreach ($presets as $code => $data) {
                TenantRole::create([
                    'org_id' => $organization->id,
                    'name' => $data['name'],
                    'is_system' => true,
                    'description' => $data['desc'],
                    'permissions' => $data['permissions'],
                ]);
            }
        });
    }
}
