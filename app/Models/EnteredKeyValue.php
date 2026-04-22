<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'entry_date' => 'date',
        'value_boolean' => 'boolean',
        'value_json' => 'array',
        'value_number' => 'decimal:4',
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
}
