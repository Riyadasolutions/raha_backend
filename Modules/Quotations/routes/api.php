<?php

use Illuminate\Support\Facades\Route;
use Modules\Quotations\Http\Controllers\ProviderQuotationController;
use Modules\Quotations\Http\Controllers\QuotationDocumentController;
use Modules\Quotations\Http\Controllers\QuotationRoundController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware('raha.auth')->group(function () {
    Route::middleware('account:STAFF')->group(function () {
        Route::get('quotation-rounds', [QuotationRoundController::class, 'index']);
        Route::post('quotation-rounds', [QuotationRoundController::class, 'store']);
        Route::get('quotation-rounds/{id}', [QuotationRoundController::class, 'show']);
        Route::get('quotation-rounds/{id}/eligible-providers', [QuotationRoundController::class, 'eligibleProviders']);
        Route::post('quotation-rounds/{id}/invitations', [QuotationRoundController::class, 'invite']);
        Route::post('quotation-rounds/{id}/extend-deadline', [QuotationRoundController::class, 'extendDeadline']);
        Route::post('quotation-rounds/{id}/close', [QuotationRoundController::class, 'close']);
        Route::post('quotation-rounds/{id}/cancel', [QuotationRoundController::class, 'cancel']);
        Route::post('quotation-rounds/{id}/quotations/{qid}/evaluations', [QuotationRoundController::class, 'evaluate']);
        Route::post('quotation-rounds/{id}/select', [QuotationRoundController::class, 'select']);
        Route::get('quotation-rounds/{id}/assessments', [QuotationRoundController::class, 'assessments']);
        Route::get('cases/{caseId}/quotation-rounds', [QuotationRoundController::class, 'forCase']);
    });

    Route::middleware('account:PROVIDER')->prefix('provider')->group(function () {
        Route::get('dashboard', [ProviderQuotationController::class, 'dashboard']);
        Route::get('invitations', [ProviderQuotationController::class, 'index']);
        Route::get('invitations/{id}', [ProviderQuotationController::class, 'show']);
        Route::post('invitations/{id}/decline', [ProviderQuotationController::class, 'decline']);
        Route::put('invitations/{id}/quotation', [ProviderQuotationController::class, 'saveDraft']);
        Route::post('invitations/{id}/quotation/submit', [ProviderQuotationController::class, 'submit']);
        Route::get('invitations/{id}/contact', [ProviderQuotationController::class, 'contact']);
        Route::get('invitations/{id}/assessment', [ProviderQuotationController::class, 'assessment']);
        Route::put('invitations/{id}/assessment', [ProviderQuotationController::class, 'saveAssessment']);
    });

    Route::middleware('account:STAFF,PROVIDER')->group(function () {
        Route::get('quotations/{id}/document', [QuotationDocumentController::class, 'show']);
    });
});
