<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\ProfileController;

// Prefixed with /api/v1 by the module RouteServiceProvider.

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('beneficiary/login', [AuthController::class, 'beneficiaryLogin']);
    Route::post('beneficiary/register', [AuthController::class, 'register']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('beneficiary/forgot-password', [AuthController::class, 'beneficiaryForgotPassword']);
    Route::post('beneficiary/reset-password', [AuthController::class, 'beneficiaryResetPassword']);
    Route::post('dev/quick-login', [AuthController::class, 'quickLogin']);
    Route::get('dev/accounts', [AuthController::class, 'devAccounts']);
});

Route::middleware('raha.auth')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('password', [AuthController::class, 'changePassword']);
        Route::put('mfa', [AuthController::class, 'mfa']);
        Route::get('sessions', [AuthController::class, 'sessions']);
        Route::delete('sessions/others', [AuthController::class, 'endOtherSessions']);
        Route::delete('sessions/{sessionId}', [AuthController::class, 'endOwnSession']);
    });
    Route::middleware('account:BENEFICIARY')->group(function () {
        Route::get('me/profile', [ProfileController::class, 'show']);
        Route::put('me/profile', [ProfileController::class, 'update']);
    });
});
