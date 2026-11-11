<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\FinanceController;
use Modules\Finance\Http\Controllers\WalletController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware(['raha.auth', 'account:STAFF'])->group(function () {
    Route::get('finance/dashboard', [FinanceController::class, 'dashboard']);
    Route::get('finance/requests', [FinanceController::class, 'requests']);
    Route::get('finance/config', [FinanceController::class, 'config']);
    Route::put('finance/config', [FinanceController::class, 'updateConfig']);
    Route::get('finance/reservations', [FinanceController::class, 'reservations']);
    Route::get('finance/commitments', [FinanceController::class, 'commitments']);
    Route::get('finance/events', [FinanceController::class, 'events']);
    Route::get('finance/reports/wallet-balances', [FinanceController::class, 'walletBalances']);

    Route::get('finance/cases/{caseId}', [FinanceController::class, 'show']);
    Route::post('finance/cases/{caseId}/start', [FinanceController::class, 'start']);
    Route::post('finance/cases/{caseId}/allocations', [FinanceController::class, 'allocations']);
    Route::post('finance/cases/{caseId}/approve-split', [FinanceController::class, 'approveSplit']);
    Route::post('finance/cases/{caseId}/reserve', [FinanceController::class, 'reserve']);
    Route::post('finance/cases/{caseId}/release', [FinanceController::class, 'release']);
    Route::post('finance/cases/{caseId}/return-to-care', [FinanceController::class, 'returnToCare']);
    Route::post('finance/cases/{caseId}/reject', [FinanceController::class, 'reject']);
    Route::post('finance/cases/{caseId}/executive/approve', [FinanceController::class, 'executiveApprove']);
    Route::post('finance/cases/{caseId}/executive/return', [FinanceController::class, 'executiveReturn']);
    Route::post('finance/cases/{caseId}/executive/reject', [FinanceController::class, 'executiveReject']);

    Route::get('wallets', [WalletController::class, 'index']);
    Route::post('wallets', [WalletController::class, 'store']);
    Route::get('wallets/{id}', [WalletController::class, 'show']);
    Route::put('wallets/{id}', [WalletController::class, 'update']);
    Route::delete('wallets/{id}', [WalletController::class, 'destroy']);
    Route::post('wallets/{id}/funds', [WalletController::class, 'funds']);
    Route::post('wallets/{id}/adjust', [WalletController::class, 'adjust']);
    Route::post('wallets/{id}/status', [WalletController::class, 'status']);
    Route::get('wallets/{id}/ledger', [WalletController::class, 'ledger']);
});
