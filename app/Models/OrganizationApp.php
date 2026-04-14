<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;

class OrganizationApp extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'staging_db_driver',
        'staging_db_host',
        'staging_db_port',
        'staging_db_database',
        'staging_db_username',
        'staging_db_password',
        'prod_db_driver',
        'prod_db_host',
        'prod_db_port',
        'prod_db_database',
        'prod_db_username',
        'prod_db_password',
        'is_active',
    ];

    protected $hidden = [
        'staging_db_password',
        'prod_db_password',
    ];

    protected $casts = [
        // Credential Encryption — AES-256-CBC
        'staging_db_username' => EncryptedString::class,
        'staging_db_password' => EncryptedString::class,
        'prod_db_username' => EncryptedString::class,
        'prod_db_password' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
