<?php

use Illuminate\Support\Facades\Route;
use Modules\Cases\Http\Controllers\BeneficiaryRequestController;
use Modules\Cases\Http\Controllers\CaseController;
use Modules\Cases\Http\Controllers\PortalController;
use Modules\Cases\Http\Controllers\TaskController;
use Modules\Cases\Http\Controllers\UploadController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware('raha.auth')->group(function () {
    Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:uploads');

    Route::middleware('account:STAFF,PROVIDER')->group(function () {
        Route::get('cases', [CaseController::class, 'index']);
        Route::get('cases/stage-statistics', [CaseController::class, 'stageStatistics']);
        Route::get('cases/{id}', [CaseController::class, 'show']);
        Route::get('cases/{id}/actions', [CaseController::class, 'actions']);
        Route::post('cases/{id}/actions/{key}', [CaseController::class, 'perform']);
        Route::get('cases/{id}/timeline', [CaseController::class, 'timeline']);
        Route::post('cases/{id}/comments', [CaseController::class, 'comment']);
        Route::post('cases/{id}/documents', [CaseController::class, 'uploadDocument']);
        Route::delete('cases/{id}/documents/{docId}', [CaseController::class, 'deleteDocument']);
        Route::patch('cases/{id}/documents/{docId}/review', [CaseController::class, 'reviewDocument']);
        Route::get('cases/{id}/documents/{docId}/download', [CaseController::class, 'download']);
        Route::post('cases/{id}/invoice', [CaseController::class, 'invoice']);
        Route::get('requests/status-lookup', [CaseController::class, 'statusLookup']);
    });

    Route::middleware('account:STAFF')->group(function () {
        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('cases/{id}/task/claim', [TaskController::class, 'claim']);
        Route::post('cases/{id}/task/release', [TaskController::class, 'release']);
        Route::post('cases/{id}/task/reassign', [TaskController::class, 'reassign']);
        Route::get('cases/{id}/task/eligible-users', [TaskController::class, 'eligibleUsers']);
        Route::post('beneficiaries/{id}/requests', [BeneficiaryRequestController::class, 'store']);
    });

    Route::middleware('account:BENEFICIARY')->prefix('portal')->group(function () {
        Route::get('requests', [PortalController::class, 'index']);
        Route::get('requests/pending-survey', [PortalController::class, 'pendingSurvey']);
        Route::post('requests', [PortalController::class, 'store']);
        Route::get('requests/{id}', [PortalController::class, 'show']);
        Route::post('requests/{id}/resubmit', [PortalController::class, 'resubmit']);
        Route::post('requests/{id}/survey', [PortalController::class, 'survey']);
        Route::post('requests/{id}/confirm-service', [PortalController::class, 'confirmService']);
        Route::post('requests/{id}/evaluation', [PortalController::class, 'evaluate']);
        Route::get('requests/{id}/documents/{docId}/download', [PortalController::class, 'download']);
        Route::get('appointments', [PortalController::class, 'appointments']);
        Route::get('fast-track/eligibility', [PortalController::class, 'fastTrack']);
    });
});
