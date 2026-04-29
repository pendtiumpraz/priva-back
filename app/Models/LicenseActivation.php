<?php
namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LicenseActivation extends Model
{
    use HasUuids;

    /** Pinned to landlord — same DB as License. */
    protected $connection = 'landlord';

    protected $fillable = [
        'license_id', 'ip_address', 'domain', 'server_hostname', 'action', 'details',
    ];

    protected $casts = [
        // PII Encryption — AES-256-CBC
        'ip_address' => EncryptedString::class,
    ];

    public function license()
    {
        return $this->belongsTo(License::class);
    }
}
