<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Cleaning reviews of a place in the store, each passed or failed (cleaning_reviews). */
class CleaningReviewsDataset extends ReportDataset
{
    public const KEY = 'data.cleaning_reviews';

    public function label(): string
    {
        return 'Cleaning reviews';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('cleaning_reviews.store_number'),
            Cols::date('cleaning_reviews.date', 'Review date'),
            Cols::text('review_place', 'Place reviewed', 'cleaning_reviews.review_place'),
            Cols::pick('score', 'Score', ['Pass', 'Fail', 'Auto Fail'], 'cleaning_reviews.score'),
            Cols::whole('reviews', 'Reviews', '1', ['sum']),
            Cols::whole('passes', 'Passed', "CASE WHEN cleaning_reviews.score = 'Pass' THEN 1 ELSE 0 END", ['sum']),
            Cols::whole('fails', 'Failed (including auto fails)', "CASE WHEN cleaning_reviews.score IN ('Fail', 'Auto Fail') THEN 1 ELSE 0 END", ['sum']),
            Cols::whole('auto_fails', 'Auto fails', "CASE WHEN cleaning_reviews.score = 'Auto Fail' THEN 1 ELSE 0 END", ['sum']),
            Cols::ratio('pass_rate', 'Passed (% of reviews)', 'number', 'percent(passes, reviews)'),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('cleaning_reviews');
    }

    public function storeColumn(): string
    {
        return 'cleaning_reviews.store_number';
    }

    public function dateColumn(): string
    {
        return 'cleaning_reviews.date';
    }

    /** store_number already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
