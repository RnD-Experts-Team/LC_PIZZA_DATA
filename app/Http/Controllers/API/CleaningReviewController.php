<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\HandlesCsvFileUpload;
use App\Services\CleaningReviewCsvProcessor;
use App\Services\CustomerServiceCsvProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CleaningReviewController extends Controller
{
    use HandlesCsvFileUpload;

    public function uploadCsv(Request $request): JsonResponse
    {
        return $this->processCsvUpload(
            $request,
            'cleaning_review_uploads',
            'cleaning_review_',
            fn (string $filePath) => (new CleaningReviewCsvProcessor())->process($filePath)
        );
    }

    public function uploadCustomerServiceCsv(Request $request): JsonResponse
    {
        return $this->processCsvUpload(
            $request,
            'customer_service_uploads',
            'customer_service_',
            fn (string $filePath) => (new CustomerServiceCsvProcessor())->process($filePath)
        );
    }
}
