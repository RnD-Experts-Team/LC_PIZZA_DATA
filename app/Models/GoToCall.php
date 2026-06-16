<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoToCall extends Model
{
    protected $fillable = [
        'store_number',
        'datetime',
        'duration',
        'call_result',
        'from',
        'status',
    ];

    protected $casts = [
        'datetime' => 'datetime',
        'duration' => 'integer',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(GoToCallParticipant::class);
    }
}
