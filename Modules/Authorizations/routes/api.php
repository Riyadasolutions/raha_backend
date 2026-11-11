<?php

use Illuminate\Support\Facades\Route;
use Modules\Authorizations\Http\Controllers\AuthorizationController;
use Modules\Authorizations\Http\Controllers\ProviderAssignmentController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware('raha.auth')->group(function () {
    Route::middleware('account:STAFF')->group(function () {
        Route::get('authorizations', [AuthorizationController::class, 'index']);
        Route::get('authorizations/{id}', [AuthorizationController::class, 'show']);
        Route::post('authorizations/{id}/replace', [AuthorizationController::class, 'replace']);
        Route::post('authorizations/{id}/cancel', [AuthorizationController::class, 'cancel']);
        Route::get('finance/cases/{caseId}/authorization/blockers', [AuthorizationController::class, 'blockers']);
        Route::post('finance/cases/{caseId}/authorization/preview', [AuthorizationController::class, 'preview']);
        Route::post('finance/cases/{caseId}/authorization', [AuthorizationController::class, 'issue']);
        Route::post('finance/cases/{caseId}/assign', [AuthorizationController::class, 'assign']);
    });

    Route::middleware('account:STAFF,PROVIDER')->get('authorizations/{id}/document', [AuthorizationController::class, 'document']);

    Route::middleware('account:PROVIDER')->group(function () {
        Route::get('provider/assignments', [ProviderAssignmentController::class, 'index']);
        Route::post('provider/assignments/{id}/acknowledge', [ProviderAssignmentController::class, 'acknowledge']);
    });
});
