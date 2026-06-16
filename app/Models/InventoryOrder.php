<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryOrder extends Model
{
    protected $fillable = [
        'delivery_date',
        'invoice_no',
        'store_number',
        'vendor_number',
        'vendor_name',
        'asn_total',
        'invoice_total',
        'is_manual_asn',
        'is_asn_received',
        'is_ordered',
        'order_status',
        'is_posted',
    ];

    protected $casts = [
        'delivery_date'  => 'date',
        'asn_total'      => 'decimal:4',
        'invoice_total'  => 'decimal:4',
        'is_manual_asn'  => 'boolean',
        'is_asn_received'=> 'boolean',
        'is_ordered'     => 'boolean',
        'is_posted'      => 'boolean',
    ];
}
