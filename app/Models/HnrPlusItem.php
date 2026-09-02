<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HnrPlusItem extends Model
{
    protected $fillable = [
        'store_number',
        'week_start',
        'week_end',
        'item_id',
        'item_name',
        'made',
        'sold',
        'voided',
        'wasted',
        'variance',
        'no_inventory_available',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'made' => 'integer',
        'sold' => 'integer',
        'voided' => 'integer',
        'wasted' => 'integer',
        'variance' => 'integer',
        'no_inventory_available' => 'integer',
    ];
}
