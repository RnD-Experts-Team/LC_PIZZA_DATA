<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferInOut extends Model
{
    protected $fillable = [
        'major_category',
        'date',
        'to_store_number',
        'from_store_number',
        'base_unit_per_report_unit',
        'ing_id',
        'ing_des',
        'quantity',
        'unit',
        'total_cost',
        'is_posted',
    ];

    protected $casts = [
        'date' => 'date',
        'base_unit_per_report_unit' => 'decimal:4',
        'quantity' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'is_posted' => 'boolean',
    ];
}
