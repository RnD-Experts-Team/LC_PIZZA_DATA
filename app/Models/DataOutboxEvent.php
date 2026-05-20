<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class DataOutboxEvent extends Model
{
    use HasUlids;

    protected $table = 'data_outbox_events';

    protected $fillable = [
        'subject',
        'type',
        'payload',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
    ];
}