<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\LandlordPinned;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes, LandlordPinned;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'org_id',
        'role',
        'phone',
        'avatar_url',
        'position',
        'department_id',
        'position_id',
        'is_active',
        'settings',
        'tenant_role_id',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'settings' => 'array',
            // Login lockout (added 2026-05-10) — datetime cast supaya
            // Carbon comparison di LoginAttemptService gampang.
            'last_failed_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            // PII Encryption — AES-256-CBC
            'name' => EncryptedString::class,
            'phone' => EncryptedString::class,
        ];
    }

    /**
     * Get the organization that the user belongs to.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class , 'org_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function positionRef()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function tenantRole()
    {
        return $this->belongsTo(TenantRole::class, 'tenant_role_id');
    }
}
