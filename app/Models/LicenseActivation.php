<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LicenseActivation extends Model
{
    use HasUuids;

    protected $fillable = [
        'license_id', 'ip_address', 'domain', 'server_hostname', 'action', 'details',
    ];

    public function license()
    {
        return $this->belongsTo(License::class);
    }
}
