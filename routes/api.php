<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\{
    ExportingController,
    ReportsController,
    EmployeeReportController,
    DueController,
    KeyController,
    KeyRuleController,
    ValueController,
    ManualCsvImportController,
    EmployeeDebriefController,
    EmployeeDebriefTypeController,
    TagController,
    GoalMetricController,
    GoalController,
    GoToCallController,
    InventoryController,
    CleaningReviewController,
    PricingKpiController,
    LcArchiveExportController,
    HnrPlusController,
    DoughSaucePlanController,
    DoughSauceRecipeController,
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

Route::get('/reports/dashboard/{store}/{date}', [ReportsController::class, 'dashboard'])->middleware('auth.token.store');
Route::post('/reports/multi-dashboard', [ReportsController::class, 'multiStoreDashboard'])->middleware('auth.token.store');
Route::get('/reports/dspr/{store}/{date}', [ReportsController::class, 'dsprLite'])->middleware('auth.token.store');
Route::get('/reports/customer-count-and-sales/{store}/{date}', [ReportsController::class, 'customerCountAndSales'])->middleware('auth.token.store');
Route::get('/reports/portal-weekly/{store}/{date}', [ReportsController::class, 'portalWeekly'])->middleware('auth.token.store');
Route::get('/reports/channel-sales/{store}/{date}', [ReportsController::class, 'channelSales'])->middleware('auth.token.store');
Route::get('/reports/phone-and-adjusted-sales/{store}/{date}', [ReportsController::class, 'phoneAndAdjustedSales'])->middleware('auth.token.store');
Route::get('/reports/cash-control/{store}/{date}', [ReportsController::class, 'cashControl'])->middleware('auth.token.store');
Route::get('/reports/lto/{store}/{date}', [ReportsController::class, 'ltoReport'])->middleware('auth.token.store');
Route::get('/reports/promo/{store}/{date}', [ReportsController::class, 'promoReport'])->middleware('auth.token.store');
Route::get('/reports/non-negotiable-reports/{store}/{date}', [ReportsController::class, 'nonNegotiableReports'])->middleware('auth.token.store');
Route::get('/reports/go-to/{store}/{date}', [ReportsController::class, 'goToReport'])->middleware('auth.token.store');
Route::get('/reports/cleaning-review/{store}/{date}', [ReportsController::class, 'cleaningReviewReport'])->middleware('auth.token.store');
Route::get('/reports/portioning/{store}/{date}', [ReportsController::class, 'portioningReport'])->middleware('auth.token.store');
Route::get('/reports/customer-service/{store}/{date}', [ReportsController::class, 'customerServiceReport'])->middleware('auth.token.store');

// Employee report: store-wide roster + debrief activity (counts per debrief
// type) for the business week containing {date}, plus trailing-week trend.
// Override the trend window with ?trend_weeks= (default 6, max 12).
Route::get('/reports/employees/{store}/{date}', [EmployeeReportController::class, 'show'])->middleware('auth.token.store');

Route::get('/reports/pricing-kpi', [PricingKpiController::class, 'export'])->middleware('auth.secret.key');
Route::get('/reports/lc-archive-zip/{date}', [LcArchiveExportController::class, 'download'])->middleware('auth.token.store');

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

    Route::get('employee/{employee_id}', [EmployeeDebriefController::class, 'byEmployee']);

    Route::get('types', [EmployeeDebriefTypeController::class, 'index']);

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


// ════════════════════════════════════════════════════════════════════════════════════════════
// GO TO CALL ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════

Route::post('/go-to-calls/upload-csv', [GoToCallController::class, 'uploadCsv'])->middleware('auth.token.store');
Route::get('/reports/transfer-in-out/{store}/{date}', [ReportsController::class, 'transferInOutReport'])->middleware('auth.token.store');
Route::get('/reports/orders-vs-sales/{store}/{date}', [ReportsController::class, 'ordersVsSalesReport'])->middleware('auth.token.store');
Route::get('/reports/hnr-plus/{store}/{date}', [ReportsController::class, 'hnrPlusReport'])->middleware('auth.token.store');


// ════════════════════════════════════════════════════════════════════════════════════════════
// INVENTORY ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════

Route::post('/transfer-in-out/upload-csv', [InventoryController::class, 'uploadTransferInOutCsv'])->middleware('auth.token.store');
Route::post('/inventory-orders/upload-csv', [InventoryController::class, 'uploadInventoryOrderCsv'])->middleware('auth.token.store');


// ════════════════════════════════════════════════════════════════════════════════════════════
// HNR+ ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════

Route::post('/hnr-plus/upload-csv', [HnrPlusController::class, 'uploadCsv'])->middleware('auth.token.store');


// ════════════════════════════════════════════════════════════════════════════════════════════
// CLEANING REVIEW ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════

Route::post('/cleaning-review/upload-csv', [CleaningReviewController::class, 'uploadCsv'])->middleware('auth.token.store');
Route::post('/customer-service/upload-csv', [CleaningReviewController::class, 'uploadCustomerServiceCsv'])->middleware('auth.token.store');


// ════════════════════════════════════════════════════════════════════════════════════════════
// DOUGH & SAUCE ROUTES
// ════════════════════════════════════════════════════════════════════════════════════════════
// Consumed by the Dough & Sauce module in AuditApp, whose browser calls these
// directly. Read-only against daily_item_summary plus three small reference
// tables of our own (ds_ingredients / ds_menu_items / ds_recipes); nothing in the
// aggregation pipeline is written or altered.
//
// The plan endpoint is rate limited: 44 store screens can be open at once, and
// every protected request here also makes a synchronous token check against the
// auth server.

Route::prefix('dough-sauce')->middleware('auth.token.store')->group(function () {

    // Ingredient units the four previous same-weekdays needed, averaged and
    // divided — plus the items that sold with no recipe, which the workbook this
    // replaces counted as zero without telling anyone.
    Route::get('daily-plan', [DoughSaucePlanController::class, 'dailyPlan'])
        ->middleware('throttle:dough-sauce')
        ->name('dough-sauce.daily-plan');

    // Recipe maintenance. The screen is in AuditApp — when the specialist sees an
    // item with no recipe there, these are what the fix button calls.
    Route::get('ingredients', [DoughSauceRecipeController::class, 'ingredients'])->name('dough-sauce.ingredients');
    Route::get('recipes', [DoughSauceRecipeController::class, 'index'])->name('dough-sauce.recipes.index');
    Route::post('recipes', [DoughSauceRecipeController::class, 'store'])->name('dough-sauce.recipes.store');
    // Dates the change, never overwrites — so a past week still computes the way
    // it was planned.
    Route::put('recipes/{recipe}', [DoughSauceRecipeController::class, 'update'])->name('dough-sauce.recipes.update');
    Route::delete('recipes/{recipe}', [DoughSauceRecipeController::class, 'destroy'])->name('dough-sauce.recipes.destroy');
});
