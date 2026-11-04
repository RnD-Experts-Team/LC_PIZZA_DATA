<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Customer service measurements per store and day: lobby and drive-thru times and the guest service score (customer_services). */
class CustomerServiceDataset extends ReportDataset
{
    public const KEY = 'data.customer_service';

    public function label(): string
    {
        return 'Customer service';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('customer_services.store_number'),
            Cols::date('customer_services.date', 'Date'),
            Cols::text('lobby_in', 'Lobby in (time)', 'customer_services.lobby_in'),
            Cols::text('lobby_out', 'Lobby out (time)', 'customer_services.lobby_out'),
            Cols::text('drive_thru_in', 'Drive-thru in (time)', 'customer_services.drive_thru_in'),
            Cols::text('drive_thru_out', 'Drive-thru out (time)', 'customer_services.drive_thru_out'),
            Cols::rows('Entries', 'customer_services.id'),
            Cols::amount('guest_service', 'Guest service score', 'customer_services.guest_service', Cols::SPREAD),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('customer_services');
    }

    public function storeColumn(): string
    {
        return 'customer_services.store_number';
    }

    public function dateColumn(): string
    {
        return 'customer_services.date';
    }

    /** store_number already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
