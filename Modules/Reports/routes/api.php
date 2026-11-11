<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportController;
use Modules\Reports\Services\ReportService;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware(['raha.auth', 'account:STAFF'])->group(function () {
    Route::get('reports/options', [ReportController::class, 'options']);
    Route::get('reports/kpis', [ReportController::class, 'kpis']);
    Route::get('reports/analytics', [ReportController::class, 'analytics']);
    Route::get('reports/{kind}/export', [ReportController::class, 'export'])->whereIn('kind', [...ReportService::KINDS, 'analytics']);
    Route::get('reports/{kind}', [ReportController::class, 'show'])->whereIn('kind', ReportService::KINDS);

    Route::get('monitoring/care/approvals', [ReportController::class, 'careApprovals']);
    Route::get('monitoring/care/execution', [ReportController::class, 'careExecution']);
    Route::get('monitoring/care/execution/{caseId}', [ReportController::class, 'careExecutionShow']);
    Route::get('monitoring/executive', [ReportController::class, 'executive']);
});
