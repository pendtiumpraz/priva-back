<?php

namespace App\Models\Landing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton — selalu ada exactly 1 row. Gunakan ::current() untuk fetch.
 * Bukan multi-tenant (sengaja): landing global Privasimu.
 */
class LandingSetting extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public static function current(): self
    {
        return static::query()->first() ?? static::create([
            'hero_headline' => 'Compliance UU PDP yang akhirnya tidak rumit',
        ]);
    }
}
