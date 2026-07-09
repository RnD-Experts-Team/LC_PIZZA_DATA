<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerService extends Model
{
    protected $fillable = [
        'store_number',
        'lobby_in',
        'lobby_out',
        'drive_thru_in',
        'drive_thru_out',
        'guest_service',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
