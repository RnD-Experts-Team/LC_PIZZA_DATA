<?php

namespace App\Models\Operational;

class DetailTransactionHot extends OperationalModel
{
    protected $table = 'detail_transactions_hot';

    protected $fillable = [
        'franchise_store',
        'business_date',
        'date_time_placed',
        'date_time_fulfilled',
        'transaction_date_time',
        'tendered_amount',
        'payment_method',
        'order_id',
        'sub_payment_method',
        'refund',
        'employee',
        'override_approval_employee',
        'order_placed_method',
        'order_fulfilled_method',
        'po_number',
        'po_entity_name',
        'user_id',
        'terminal_payment_made',
        'card_last4',
        'saf_transaction',
    ];

    protected $casts = [
        'business_date' => 'date',
        'date_time_placed' => 'datetime',
        'date_time_fulfilled' => 'datetime',
        'transaction_date_time' => 'datetime',
        'tendered_amount' => 'decimal:2',
    ];

    /**
     * Get the order this transaction/tender belongs to
     */
    public function order()
    {
        return $this->belongsTo(DetailOrderHot::class, 'order_id', 'order_id')
            ->where('franchise_store', $this->franchise_store)
            ->where('business_date', $this->business_date);
    }

    /**
     * Scope to filter by store
     */
    public function scopeForStore($query, $store)
    {
        return $query->where('franchise_store', $store);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('business_date', [$startDate, $endDate]);
    }

    /**
     * Check if this tender was a refund
     */
    public function isRefund(): bool
    {
        return strtolower($this->refund ?? '') === 'yes';
    }
}
