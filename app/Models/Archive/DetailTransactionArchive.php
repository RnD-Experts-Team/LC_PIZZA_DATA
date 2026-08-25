<?php

namespace App\Models\Archive;

class DetailTransactionArchive extends ArchiveModel
{
    protected $table = 'detail_transactions_archive';

    // Same fillable as DetailTransactionHot
    protected $fillable = [
        'franchise_store', 'business_date', 'date_time_placed', 'date_time_fulfilled',
        'transaction_date_time', 'tendered_amount', 'payment_method', 'order_id',
        'sub_payment_method', 'refund', 'employee', 'override_approval_employee',
        'order_placed_method', 'order_fulfilled_method', 'po_number', 'po_entity_name',
        'user_id', 'terminal_payment_made', 'card_last4', 'saf_transaction',
    ];

    protected $casts = [
        'business_date' => 'date',
        'date_time_placed' => 'datetime',
        'date_time_fulfilled' => 'datetime',
        'transaction_date_time' => 'datetime',
        'tendered_amount' => 'decimal:2',
    ];

    public function scopeForStore($query, $store)
    {
        return $query->where('franchise_store', $store);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('business_date', [$startDate, $endDate]);
    }
}
