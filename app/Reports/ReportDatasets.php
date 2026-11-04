<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\AltaCogsDataset;
use App\Reports\Datasets\AltaIngredientOrdersDataset;
use App\Reports\Datasets\AltaIngredientUsageDataset;
use App\Reports\Datasets\AltaWasteDataset;
use App\Reports\Datasets\CashDataset;
use App\Reports\Datasets\CleaningReviewsDataset;
use App\Reports\Datasets\CustomerServiceDataset;
use App\Reports\Datasets\DailyItemsDataset;
use App\Reports\Datasets\DailySalesDataset;
use App\Reports\Datasets\EmployeeDebriefsDataset;
use App\Reports\Datasets\FinancialsDataset;
use App\Reports\Datasets\GoToCallsDataset;
use App\Reports\Datasets\GoalsDataset;
use App\Reports\Datasets\HnrPlusDataset;
use App\Reports\Datasets\HourlyItemsDataset;
use App\Reports\Datasets\HourlySalesDataset;
use App\Reports\Datasets\InventoryOrdersDataset;
use App\Reports\Datasets\KeyValuesDataset;
use App\Reports\Datasets\NonNegotiablesDataset;
use App\Reports\Datasets\OrderLinesDataset;
use App\Reports\Datasets\OrdersDataset;
use App\Reports\Datasets\SummaryItemsDataset;
use App\Reports\Datasets\SummarySalesDataset;
use App\Reports\Datasets\SummaryTransactionsDataset;
use App\Reports\Datasets\TransactionsDataset;
use App\Reports\Datasets\TransfersDataset;
use App\Reports\Datasets\WasteDataset;

/**
 * The datasets this service offers the report builder. routes/api.php gives
 * each one a literal query route; each needs its own pizzasys auth rule.
 */
final class ReportDatasets
{

    /** @var list<class-string<ReportDataset>> */
    public const DATASETS = [
        DailySalesDataset::class,
        EmployeeDebriefsDataset::class,
        KeyValuesDataset::class,
        DailyItemsDataset::class,
        HourlySalesDataset::class,
        HourlyItemsDataset::class,
        OrdersDataset::class,
        OrderLinesDataset::class,
        SummaryItemsDataset::class,
        SummarySalesDataset::class,
        SummaryTransactionsDataset::class,
        WasteDataset::class,
        CashDataset::class,
        FinancialsDataset::class,
        TransactionsDataset::class,
        AltaCogsDataset::class,
        AltaIngredientOrdersDataset::class,
        AltaIngredientUsageDataset::class,
        AltaWasteDataset::class,
        GoalsDataset::class,
        NonNegotiablesDataset::class,
        GoToCallsDataset::class,
        TransfersDataset::class,
        InventoryOrdersDataset::class,
        CleaningReviewsDataset::class,
        CustomerServiceDataset::class,
        HnrPlusDataset::class,
    ];

    public static function make(string $key): ReportDataset
    {
        foreach (self::DATASETS as $dataset) {
            if ($dataset::KEY === $key) {
                return new $dataset();
            }
        }

        abort(404, 'Unknown dataset.');
    }
}
