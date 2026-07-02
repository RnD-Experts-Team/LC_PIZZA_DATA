<?php

namespace App\Http\Controllers\API\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait HandlesCsvFileUpload
{
    /**
     * Validate + stage an uploaded CSV file, hand the staged path to $processor,
     * and guarantee the staging directory is removed no matter what happens
     * (success, caught exception, or any other throwable).
     *
     * @param callable(string $filePath): array $processor Must return the
     *        standard ['success','total_rows','imported_rows','failed_rows','errors'] shape.
     */
    protected function processCsvUpload(Request $request, string $folder, string $uploadIdPrefix, callable $processor): JsonResponse
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

        $storagePath = storage_path("app/{$folder}/" . uniqid($uploadIdPrefix, true));

        try {
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $file = $request->file('file');
            $filename = $file->getClientOriginalName();
            $filePath = $storagePath . '/' . $filename;

            if (!$file->move($storagePath, $filename)) {
                throw new \RuntimeException('Failed to move uploaded file');
            }

            if (!file_exists($filePath)) {
                throw new \RuntimeException('File was not stored correctly');
            }

            $result = $processor($filePath);

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
        } catch (\Throwable $e) {
            Log::error('CSV upload failed', [
                'folder' => $folder,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        } finally {
            $this->deleteUploadDirectory($storagePath);
        }
    }

    /**
     * Recursively delete an upload staging directory. Safe to call even if
     * mkdir/move never happened (is_dir guard) or if called twice.
     */
    protected function deleteUploadDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }

        @rmdir($path);
    }
}
