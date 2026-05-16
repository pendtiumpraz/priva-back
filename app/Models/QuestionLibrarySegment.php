<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPRM Phase 1 — Segment/domain di dalam library.
 *
 * Mis. library "PDP UU 27/2022" punya segment: Tata Kelola, Operasi,
 * SDM, Teknologi, Data Handling. Tiap segment punya weight_pct yang
 * mempengaruhi kontribusi terhadap final score.
 *
 * weight_pct sum dalam satu library idealnya = 100. Validasi soft
 * di builder UI; backend tetap accept sum lain (mis. saat builder
 * masih draft) supaya UX tidak terlalu strict.
 */
class QuestionLibrarySegment extends Model
{
    use HasUuids, LandlordPinned, SoftDeletes;

    protected $fillable = [
        'library_id',
        'name',
        'code',
        'description',
        'order_index',
        'weight_pct',
        'questions_count',
    ];

    protected $casts = [
        'order_index' => 'integer',
        'weight_pct' => 'integer',
        'questions_count' => 'integer',
    ];

    public function library()
    {
        return $this->belongsTo(QuestionLibrary::class, 'library_id');
    }

    public function questions()
    {
        return $this->hasMany(VendorQuestionnaire::class, 'library_segment_id')
            ->orderBy('sort_order');
    }

    public function refreshCounter(): void
    {
        $this->questions_count = $this->questions()->count();
        $this->save();
    }
}
