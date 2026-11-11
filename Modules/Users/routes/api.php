<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\UserController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware(['raha.auth', 'account:STAFF'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('{id}', [UserController::class, 'show']);
    Route::put('{id}', [UserController::class, 'update']);
    Route::get('{id}/sessions', [UserController::class, 'sessions']);
    Route::delete('{id}/roles/{roleId}', [UserController::class, 'removeRole']);
    Route::post('{id}/suspend', [UserController::class, 'suspend']);
    Route::post('{id}/activate', [UserController::class, 'activate']);
    Route::post('{id}/lock', [UserController::class, 'lock']);
    Route::post('{id}/unlock', [UserController::class, 'unlock']);
    Route::post('{id}/force-password-change', [UserController::class, 'forcePasswordChange']);
    Route::post('{id}/reset-mfa', [UserController::class, 'resetMfa']);
    Route::post('{id}/end-sessions', [UserController::class, 'endSessions']);
});
