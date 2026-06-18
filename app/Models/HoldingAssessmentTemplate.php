<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Holding Compliance Assessment — Template.
 *
 * Di-author oleh org HOLDING: kumpulan pertanyaan custom (dikelompokkan per
 * kategori) untuk satu regulasi. Template di-dispatch ke anak perusahaan /
 * sub-holding menjadi HoldingAssessmentInstance yang diisi via public link.
 */
class HoldingAssessmentTemplate extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $table = 'holding_assessment_templates';

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'regulation_code',
        'regulation_name',
        'type',
        'status',
        'created_by',
    ];

    public function questions()
    {
        return $this->hasMany(HoldingAssessmentQuestion::class, 'template_id')
            ->orderBy('sort_order');
    }

    public function activeQuestions()
    {
        return $this->questions()->where('is_active', true);
    }

    public function instances()
    {
        return $this->hasMany(HoldingAssessmentInstance::class, 'template_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
