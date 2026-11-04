<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/**
 * Every payment taken against an order (detail_transactions, hot and archive).
 * The card number's last digits are not selected, so no request can reach them.
 */
class TransactionsDataset extends WarehouseDataset
{
    public const KEY = 'data.transactions';

    public function label(): string
    {
        return 'Payment transactions';
    }

    public function heavy(): bool
    {
        return true;
    }

    protected function table(): string
    {
        return 'detail_transactions';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'order_id', 'date_time_placed', 'date_time_fulfilled',
            'transaction_date_time', 'payment_method', 'sub_payment_method', 'refund', 'terminal_payment_made',
            'saf_transaction', 'employee', 'override_approval_employee', 'order_placed_method', 'order_fulfilled_method',
            'po_number', 'po_entity_name', 'user_id', 'tendered_amount',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('order_id', 'Order number'),
            Cols::text('payment_method', 'Payment method'),
            Cols::text('sub_payment_method', 'Payment sub-method'),
            Cols::text('refund', 'Refund'),
            Cols::text('terminal_payment_made', 'Paid at a terminal'),
            Cols::text('saf_transaction', 'Store-and-forward (SAF) payment'),
            Cols::text('order_placed_method', 'Channel (how the order was placed)'),
            Cols::text('order_fulfilled_method', 'Service type (how it was fulfilled)'),
            Cols::text('pos_employee', 'Employee (POS name)', 'employee'),
            Cols::text('approver', 'Override approved by (POS name)', 'override_approval_employee'),
            Cols::text('pos_user_id', 'POS user ID', 'user_id'),
            Cols::text('po_number', 'Purchase order number'),
            Cols::text('po_entity_name', 'Purchase order entity'),
            Cols::stamp('placed_at', 'Order placed at', 'date_time_placed'),
            Cols::stamp('fulfilled_at', 'Order fulfilled at', 'date_time_fulfilled'),
            Cols::stamp('paid_at', 'Paid at', 'transaction_date_time'),
            Cols::distinct('orders', 'Orders', 'order_id'),
            Cols::rows('Payments', 'id'),
            Cols::money('tendered_amount', 'Amount tendered'),
        ];
    }
}
