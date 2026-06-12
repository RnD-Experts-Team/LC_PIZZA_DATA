<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NonNegotiableReport extends Model
{
    protected $fillable = [
        'store_number',
        'action',
        'date',
        'time',
        'date_two',
        'time_two',
        'notes',
    ];
}
