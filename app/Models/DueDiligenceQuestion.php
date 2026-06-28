<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu pertanyaan Technical Due Diligence + jawaban rekomendasi (editable).
 * Platform-level (root-only) — lihat DueDiligenceController.
 */
class DueDiligenceQuestion extends Model
{
    use HasUuids, LandlordPinned, SoftDeletes;

    protected $fillable = [
        'q_no', 'area', 'sub_topic', 'qtype', 'question',
        'recommended_answer', 'evidence', 'status', 'internal_note', 'sort_order',
    ];

    protected $casts = [
        'q_no' => 'integer',
        'sort_order' => 'integer',
    ];
}
