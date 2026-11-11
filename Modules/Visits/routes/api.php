<?php

use Illuminate\Support\Facades\Route;
use Modules\Visits\Http\Controllers\VisitController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware('raha.auth')->group(function () {
    Route::middleware('account:STAFF,PROVIDER')->group(function () {
        Route::get('cases/{id}/visit-plan', [VisitController::class, 'plan']);
        Route::put('cases/{id}/visit-plan/approved-count', [VisitController::class, 'setApprovedCount']);
        Route::post('cases/{id}/visit-plan/adjust', [VisitController::class, 'adjust']);
        Route::get('cases/{id}/visits', [VisitController::class, 'index']);
        Route::post('cases/{id}/visits', [VisitController::class, 'schedule']);
        Route::post('visits/{visitId}/complete', [VisitController::class, 'complete']);
        Route::post('visits/{visitId}/miss', [VisitController::class, 'miss']);
        Route::post('visits/{visitId}/cancel', [VisitController::class, 'cancel']);
        Route::post('visits/{visitId}/correct', [VisitController::class, 'correct']);
    });

    Route::middleware('account:PROVIDER')->get('provider/visits', [VisitController::class, 'providerVisits']);
    Route::middleware('account:STAFF')->get('monitoring/visits', [VisitController::class, 'monitoring']);
});
