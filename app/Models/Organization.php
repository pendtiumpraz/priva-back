<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'idle_timeout_enabled', 'idle_timeout_minutes', 'name', 'slug', 'industry', 'logo_url', 'privacy_policy_url',
        'website', 'address', 'phone', 'email', 'settings',
        // Onboarding
        'business_model', 'company_size', 'data_subjects_type', 'core_systems',
        'has_dpo', 'onboarding_completed',
        // AI Credits
        'ai_credits_monthly', 'ai_credits_remaining', 'ai_credits_purchased', 'ai_credits_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'data_subjects_type' => 'array',
            'core_systems' => 'array',
            'has_dpo' => 'boolean',
            'onboarding_completed' => 'boolean',
            'ai_credits_reset_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class , 'org_id');
    }

    public function creditLogs()
    {
        return $this->hasMany(AiCreditLog::class, 'org_id');
    }

    protected static function booted()
    {
        static::created(function ($organization) {
            $presets = [
                'admin' => [
                    'name' => 'Admin', 
                    'desc' => 'Administrator dengan full akses konfigurasi',
                    'permissions' => ['*']
                ],
                'dpo'   => [
                    'name' => 'DPO', 
                    'desc' => 'Data Protection Officer untuk review dan approval',
                    'permissions' => ['dashboard', 'ropa', 'dpia', 'dsr', 'breach', 'simulation', 'consent', 'contract-review', 'data-discovery', 'gap-assessment', 'settings']
                ],
                'maker' => [
                    'name' => 'Maker', 
                    'desc' => 'User operasional yang input data ROPA/DPIA',
                    'permissions' => ['dashboard', 'ropa', 'dpia', 'dsr', 'breach', 'consent', 'contract-review', 'data-discovery', 'gap-assessment']
                ],
                'viewer'=> [
                    'name' => 'Viewer', 
                    'desc' => 'Akses hanya baca (read-only)',
                    'permissions' => ['dashboard', 'ropa', 'dpia']
                ],
            ];

            foreach ($presets as $code => $data) {
                \App\Models\TenantRole::create([
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
