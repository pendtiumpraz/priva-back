<?php

namespace App\Lms\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use BelongsToOrg;

    protected $table = 'lms_certificates';

    protected $fillable = [
        'user_id', 'org_id', 'course_id', 'certificate_number',
        'issued_at', 'signed_payload', 'revoked_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
