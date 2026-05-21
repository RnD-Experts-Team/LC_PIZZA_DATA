<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\{
    ExportingController,
    ReportsController,
    DueController,
    KeyController,
    KeyRuleController,
    ValueController,
    ManualCsvImportController,
    EmployeeDebriefController,
    TagController,
    GoalMetricController,
    GoalController,
};


/**
 * API Routes for Pizza Data System
 * 
 * All routes return JSON
 * Authenticated via API middleware
 */


// ════════════════════════════════════════════════════════════════════════════════════════════
// EXPORT ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════

Route::prefix('export')->group(function () {
    Route::get('csv', [ExportingController::class, 'exportCSV'])->name('export.csv')->middleware('auth.token.store');
    Route::get('json', [ExportingController::class, 'exportJson'])->name('export.json')->middleware('auth.token.store');
    Route::get('csv/excel-reports', [ExportingController::class, 'exportCSV'])->name('export.csv.excel-reports')->middleware('auth.secret.key');
});

Route::get('/reports/dspr/{store}/{date}', [ReportsController::class, 'dsprLite'])->middleware('auth.token.store');



Route::prefix('engine')->middleware('auth.token.store')->group(function () {

    // Keys CRUD
    Route::apiResource('keys', KeyController::class);
    Route::patch('keys/{key}/restore', [KeyController::class, 'restore']);
    Route::delete('keys/{key}/force-delete', [KeyController::class, 'forceDelete']);

    // Optional rules convenience
    Route::get('keys/{key}/rules', [KeyRuleController::class, 'index']);
    Route::put('keys/{key}/rules', [KeyRuleController::class, 'replace']);

    // Values
    // Route::get('values', [ValueController::class, 'index']);
    Route::get('stores/{store_id}/values', [ValueController::class, 'storeIndex']);

    Route::post('stores/{store_id}/dates/{date}/values', [ValueController::class, 'upsertOne']);
    Route::post('stores/{store_id}/dates/{date}/values/bulk', [ValueController::class, 'upsertBulk']);

    // Due
    Route::get('stores/{store_id}/dates/{date}/due', [DueController::class, 'dueOnDate']);
    Route::get('stores/{store_id}/due-range', [DueController::class, 'dueRange']);

    Route::get('stores/{store_id}/dates/{date}/values', [ValueController::class, 'grid']);
});


Route::prefix('manual-import')
    ->middleware('auth.token.store')
    ->group(function () {

        // UI page (NO secret key middleware, accessible for API requests)
        Route::get('/', [ManualCsvImportController::class, 'index']);
        Route::post('/inspect-zip', [ManualCsvImportController::class, 'inspectZip']);

        Route::post('/upload', [ManualCsvImportController::class, 'upload']);

        Route::get('/progress/{uploadId}', [ManualCsvImportController::class, 'progress']);

        Route::post('/reaggregate', [ManualCsvImportController::class, 'reaggregate']);

        Route::get('/aggregation-progress/{aggregationId}', [ManualCsvImportController::class, 'aggregationProgress']);
    });


Route::prefix('stores/{store_id}/employee-debriefs')->middleware('auth.token.store')->group(function () {

    Route::get('/', [EmployeeDebriefController::class, 'index']);

    Route::get('range', [EmployeeDebriefController::class, 'range']);

    Route::post('/', [EmployeeDebriefController::class, 'store']);

    Route::post('bulk', [EmployeeDebriefController::class, 'storeMultiple']);

    Route::get('{debrief}', [EmployeeDebriefController::class, 'show']);

    Route::delete('{debrief}', [EmployeeDebriefController::class, 'destroy']);

});

Route::prefix('tags')->middleware('auth.token.store')->group(function () {
    Route::get('/', [TagController::class, 'index']);
    Route::post('/', [TagController::class, 'store']);
    Route::delete('/bulk', [TagController::class, 'bulkDelete']);
    Route::delete('/{tag}', [TagController::class, 'destroy']);
});


// ════════════════════════════════════════════════════════════════════════════════════════════
// GOALS ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════

Route::prefix('goal-metrics')->middleware('auth.token.store')->group(function () {
    Route::get('/', [GoalMetricController::class, 'index']);
    Route::post('/', [GoalMetricController::class, 'store']);
    Route::delete('/{goalMetric}', [GoalMetricController::class, 'destroy']);
});

Route::prefix('stores/{store_id}/goals')->middleware('auth.token.store')->group(function () {
    Route::get('/', [GoalController::class, 'index']);
    Route::post('/', [GoalController::class, 'store']);
    Route::put('/{goal}', [GoalController::class, 'update']);
    Route::delete('/{goal}', [GoalController::class, 'destroy']);
});