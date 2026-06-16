<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoToCallParticipant extends Model
{
    protected $fillable = [
        'go_to_call_id',
        'participant',
    ];

    public function goToCall(): BelongsTo
    {
        return $this->belongsTo(GoToCall::class);
    }
}
