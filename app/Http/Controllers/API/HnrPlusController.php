<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\HandlesCsvFileUpload;
use App\Services\HnrPlusItemCsvProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HnrPlusController extends Controller
{
    use HandlesCsvFileUpload;

    public function uploadCsv(Request $request): JsonResponse
    {
        return $this->processCsvUpload(
            $request,
            'hnr_plus_uploads',
            'hnr_plus_',
            fn (string $filePath) => (new HnrPlusItemCsvProcessor())->process($filePath)
        );
    }
}
