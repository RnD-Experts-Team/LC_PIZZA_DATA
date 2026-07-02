<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\HandlesCsvFileUpload;
use App\Services\TransferInOutCsvProcessor;
use App\Services\InventoryOrderCsvProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use HandlesCsvFileUpload;

    public function uploadTransferInOutCsv(Request $request): JsonResponse
    {
        return $this->processCsvUpload(
            $request,
            'transfer_in_out_uploads',
            'transfer_in_out_',
            fn (string $filePath) => (new TransferInOutCsvProcessor())->process($filePath)
        );
    }

    public function uploadInventoryOrderCsv(Request $request): JsonResponse
    {
        return $this->processCsvUpload(
            $request,
            'inventory_order_uploads',
            'inventory_order_',
            fn (string $filePath) => (new InventoryOrderCsvProcessor())->process($filePath)
        );
    }
}
