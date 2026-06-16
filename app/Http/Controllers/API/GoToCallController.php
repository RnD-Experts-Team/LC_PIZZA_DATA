<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\GoToCall;
use App\Services\GoToCallCsvProcessor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GoToCallController extends Controller
{
    public function uploadCsv(Request $request)
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
            $uploadId = uniqid('go_to_call_', true);
            $storagePath = storage_path("app/go_to_calls_uploads/{$uploadId}");

            // Ensure directory exists
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $filename = $file->getClientOriginalName();
            $filePath = $storagePath . '/' . $filename;

            // Move the file to storage
            if (!$file->move($storagePath, $filename)) {
                throw new \Exception('Failed to move uploaded file');
            }

            if (!file_exists($filePath)) {
                throw new \Exception('File was not stored correctly');
            }

            $processor = new GoToCallCsvProcessor();
            $result = $processor->process($filePath);

            // Clean up uploaded file
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
            Log::error('GoToCall CSV upload failed', [
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
