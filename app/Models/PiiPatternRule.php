<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pola pengenal data pribadi milik satu organisasi.
 */
class PiiPatternRule extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const CATEGORIES = ['umum', 'spesifik'];

    public const CLASSIFICATIONS = ['pii', 'sensitive', 'internal'];

    protected $fillable = [
        'org_id', 'key', 'label', 'pattern', 'pdp_category', 'classification',
        'encryption_required', 'weight', 'reason', 'sample_value',
        'is_active', 'sequence', 'created_by',
    ];

    protected $casts = [
        'encryption_required' => 'boolean',
        'is_active' => 'boolean',
        'weight' => 'float',
        'sequence' => 'integer',
    ];

    /**
     * Apakah ekspresi regulernya sah dan aman dijalankan.
     *
     * Pola dari pengguna dijalankan terhadap isi basis data, jadi pola yang
     * rusak akan memunculkan peringatan PHP pada setiap baris yang dipindai —
     * bukan sekali, melainkan ribuan kali.
     *
     * @return array{valid: bool, error: ?string}
     */
    public static function validatePattern(string $pattern): array
    {
        // preg_match mengembalikan false dan memicu peringatan untuk pola rusak.
        set_error_handler(fn () => true);
        $result = preg_match($pattern, 'uji');
        restore_error_handler();

        if ($result === false) {
            return ['valid' => false, 'error' => 'Ekspresi reguler tidak sah. Pastikan pembatasnya lengkap, mis. /^\d{10}$/'];
        }

        return ['valid' => true, 'error' => null];
    }
}
