<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\TransferInOutCsvProcessor;
use App\Services\InventoryOrderCsvProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    public function uploadTransferInOutCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10485760', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            $uploadId = uniqid('transfer_in_out_', true);
            $storagePath = storage_path("app/transfer_in_out_uploads/{$uploadId}");

            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $filename = $file->getClientOriginalName();
            $filePath = $storagePath . '/' . $filename;

            if (!$file->move($storagePath, $filename)) {
                throw new \Exception('Failed to move uploaded file');
            }

            if (!file_exists($filePath)) {
                throw new \Exception('File was not stored correctly');
            }

            $processor = new TransferInOutCsvProcessor();
            $result = $processor->process($filePath);

            @unlink($filePath);
            @rmdir($storagePath);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV processing failed',
                    'errors' => $result['errors'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'imported_rows' => $result['imported_rows'],
                'total_rows' => $result['total_rows'],
                'failed_rows' => $result['failed_rows'],
            ]);
        } catch (\Exception $e) {
            Log::error('TransferInOut CSV upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadInventoryOrderCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10485760', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            $uploadId = uniqid('inventory_order_', true);
            $storagePath = storage_path("app/inventory_order_uploads/{$uploadId}");

            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $filename = $file->getClientOriginalName();
            $filePath = $storagePath . '/' . $filename;

            if (!$file->move($storagePath, $filename)) {
                throw new \Exception('Failed to move uploaded file');
            }

            if (!file_exists($filePath)) {
                throw new \Exception('File was not stored correctly');
            }

            $processor = new InventoryOrderCsvProcessor();
            $result = $processor->process($filePath);

            @unlink($filePath);
            @rmdir($storagePath);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV processing failed',
                    'errors' => $result['errors'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'imported_rows' => $result['imported_rows'],
                'total_rows' => $result['total_rows'],
                'failed_rows' => $result['failed_rows'],
            ]);
        } catch (\Exception $e) {
            Log::error('InventoryOrder CSV upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
