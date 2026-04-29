<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RoleMenuWhitelist extends Model
{
    use HasUuids;

    /** Pinned to landlord — same as MenuItem. */
    protected $connection = 'landlord';

    protected $table = 'role_menu_whitelist';

    protected $fillable = ['menu_id', 'role', 'is_allowed'];

    protected $casts = ['is_allowed' => 'boolean'];

    public function menu()
    {
        return $this->belongsTo(MenuItem::class, 'menu_id');
    }
}
