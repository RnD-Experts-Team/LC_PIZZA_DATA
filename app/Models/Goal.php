<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends Model
{
    protected $fillable = [
        'goal_metric_id',
        'store_id',
        'week_start_date',
        'week_end_date',
        'goal',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'week_end_date'   => 'date',
        'goal'            => 'decimal:4',
    ];

    protected $with = ['metric'];

    public function metric(): BelongsTo
    {
        return $this->belongsTo(GoalMetric::class, 'goal_metric_id');
    }
}
