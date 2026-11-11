<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware(['raha.auth', 'account:STAFF'])->group(function () {
    Route::get('audit-logs', [AuditController::class, 'index']);
});
