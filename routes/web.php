<?php

use App\Http\Controllers\ManualCsvImportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\ExportingController;
/*
|--------------------------------------------------------------------------
| Manual CSV Import Routes
|--------------------------------------------------------------------------
|
| - Index is public (UI page)
| - All actions are protected by auth.secret.key middleware
|
| Rate limits (AppServiceProvider): the public pages are per IP; the secret
| actions are per secret + IP and counted as exports. The two progress routes
| are polled every second by the import page, so they are counted as polls.
|
*/

Route::prefix('manual-import')
    ->name('manual.import.')
    ->group(function () {

        // UI page (NO secret key middleware)
        Route::get('/', [ManualCsvImportController::class, 'index'])
            ->middleware('throttle:api-public')
            ->name('index');

        // Protected routes
        Route::middleware('auth.secret.key')->group(function () {

            Route::post('/inspect-zip', [ManualCsvImportController::class, 'inspectZip'])
                ->middleware(['throttle:service-secret', 'throttle:api-export'])
                ->name('inspect.zip');

            Route::post('/upload', [ManualCsvImportController::class, 'upload'])
                ->middleware(['throttle:service-secret', 'throttle:api-export'])
                ->name('upload');

            Route::get('/progress/{uploadId}', [ManualCsvImportController::class, 'progress'])
                ->middleware('throttle:api-poll')
                ->name('progress');

            Route::post('/reaggregate', [ManualCsvImportController::class, 'reaggregate'])
                ->middleware(['throttle:service-secret', 'throttle:api-export'])
                ->name('reaggregate');

            Route::get('/aggregation-progress/{aggregationId}', [ManualCsvImportController::class, 'aggregationProgress'])
                ->middleware('throttle:api-poll')
                ->name('aggregation.progress');
        });
    });

Route::get('/manual-export', function () {
    return view('manual-export');
})->middleware('throttle:api-public')->name('manual.export.index');
