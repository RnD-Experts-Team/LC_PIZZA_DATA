<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeyStoreRuleTime extends Model
{
    protected $fillable = [
        'key_store_rule_id',
        'due_time',
        'last_notified_at',
        'last_notified_for_date',
    ];

    protected $casts = [
        'last_notified_at' => 'datetime',
        'last_notified_for_date' => 'date',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(KeyStoreRule::class, 'key_store_rule_id');
    }
}