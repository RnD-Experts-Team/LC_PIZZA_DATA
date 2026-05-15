<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeyStoreRule extends Model
{
    protected $fillable = [
        'key_id',
        'store_id',

        'fill_mode',
        'role_names',

        'frequency_type',
        'interval',

        'week_days',
        'month_day',
        'week_of_month',
        'week_day',
        'year_month',

        'due_time',
        'last_notified_at',

        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'role_names' => 'array',
        'week_days' => 'array',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'last_notified_at' => 'datetime',
    ];

    public function key(): BelongsTo
    {
        return $this->belongsTo(EnteredKey::class, 'key_id');
    }
}