<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcessingCategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'code', 'label', 'description',
        'ropa_counter', 'dpia_counter', 'counter_year',
        'created_by',
    ];

    protected $casts = [
        'ropa_counter' => 'integer',
        'dpia_counter' => 'integer',
        'counter_year' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Reserve the next counter for a module in the current year and persist it.
     * Returns the newly incremented integer. Resets counter if the year rolled.
     */
    public function nextCounter(string $module): int
    {
        $year = (int) date('Y');
        $column = $module === 'dpia' ? 'dpia_counter' : 'ropa_counter';

        if ($this->counter_year !== $year) {
            // Year changed — reset both counters for this category
            $this->counter_year = $year;
            $this->ropa_counter = 0;
            $this->dpia_counter = 0;
        }

        $this->{$column} = ((int) $this->{$column}) + 1;
        $this->save();

        return (int) $this->{$column};
    }
}
