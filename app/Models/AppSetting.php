<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    /**
     * Pinned to landlord — global platform config, must be reachable
     * from any request regardless of which tenant DB is active.
     */
    protected $connection = 'landlord';

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null): ?string
    {
        $setting = self::find($key);
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
