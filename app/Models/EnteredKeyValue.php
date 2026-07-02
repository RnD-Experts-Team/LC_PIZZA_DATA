<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class EnteredKeyValue extends Model
{
    protected $fillable = [
        'key_id',
        'store_id',
        'user_id',
        'entry_date',
        'value_text',
        'value_number',
        'value_boolean',
        'value_json',
        'note',
        'is_mistaken',
        'superseded_at',
        'corrected_from_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'value_boolean' => 'boolean',
        'value_json' => 'array',
        'value_number' => 'decimal:4',
        'is_mistaken' => 'boolean',
        'superseded_at' => 'datetime',
    ];

    protected $with = ['attachments'];

    public function key(): BelongsTo
    {
        return $this->belongsTo(EnteredKey::class, 'key_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EnteredKeyValueAttachment::class, 'entered_key_value_id');
    }

    /** The row this one superseded (the previous, now-mistaken, value). */
    public function correctedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_from_id');
    }

    /** The row(s) that superseded this one (0 or 1). */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrected_from_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_mistaken', false);
    }

    public function scopeMistaken(Builder $query): Builder
    {
        return $query->where('is_mistaken', true);
    }

    /**
     * The chain of previously-mistaken versions, newest-first.
     */
    public function mistakenVersions(): Collection
    {
        $history = collect();

        $cursor = $this->correctedFrom;

        while ($cursor) {
            $history->push($cursor);
            $cursor = $cursor->correctedFrom;
        }

        return $history;
    }
}
