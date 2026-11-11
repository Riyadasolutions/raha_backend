<?php

use Illuminate\Support\Facades\Route;
use Modules\Beneficiaries\Http\Controllers\BeneficiaryController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.
// POST beneficiaries/{id}/requests (request on behalf) is owned by the Cases module.

Route::middleware(['raha.auth', 'account:STAFF'])->group(function () {
    Route::get('beneficiaries', [BeneficiaryController::class, 'index']);
    Route::post('beneficiaries', [BeneficiaryController::class, 'store']);
    Route::get('beneficiaries/{id}', [BeneficiaryController::class, 'show']);
    Route::put('beneficiaries/{id}', [BeneficiaryController::class, 'update']);
    Route::get('beneficiaries/{id}/requests', [BeneficiaryController::class, 'requests']);
    Route::get('beneficiaries/{id}/documents', [BeneficiaryController::class, 'documents']);
    Route::get('beneficiaries/{id}/financials', [BeneficiaryController::class, 'financials']);
    Route::get('beneficiaries/{id}/notes', [BeneficiaryController::class, 'notes']);
    Route::post('beneficiaries/{id}/notes', [BeneficiaryController::class, 'addNote']);
    Route::get('beneficiaries/{id}/activity', [BeneficiaryController::class, 'activity']);
});
