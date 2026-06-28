<?php

namespace App\Models;

use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu dokumen Due Diligence yang diminta, dengan tabel detail (editable).
 * `columns` = header tabel, `rows` = baris isian (array of array string).
 * Platform-level (root-only).
 */
class DueDiligenceDocument extends Model
{
    use HasUuids, LandlordPinned, SoftDeletes;

    protected $fillable = [
        'doc_no', 'category', 'name', 'request_text', 'priority', 'format',
        'doc_status', 'received_date', 'guidance', 'recommendation',
        'columns', 'rows', 'sort_order',
    ];

    protected $casts = [
        'doc_no' => 'integer',
        'sort_order' => 'integer',
        'received_date' => 'date',
        'columns' => 'array',
        'rows' => 'array',
    ];
}
