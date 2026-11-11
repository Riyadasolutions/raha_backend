<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\RoleController;
use Modules\Access\Http\Controllers\SecurityController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::middleware(['raha.auth', 'account:STAFF'])->group(function () {
    Route::get('permissions', [RoleController::class, 'permissions']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::get('roles/compare', [RoleController::class, 'compare']);
    Route::get('roles/{id}', [RoleController::class, 'show']);
    Route::put('roles/{id}', [RoleController::class, 'update']);
    Route::patch('roles/{id}/status', [RoleController::class, 'status']);
    Route::delete('roles/{id}', [RoleController::class, 'destroy']);

    Route::get('security-policy', [SecurityController::class, 'policy']);
    Route::put('security-policy', [SecurityController::class, 'savePolicy']);
    Route::get('security-log', [SecurityController::class, 'log']);
    Route::get('sessions', [SecurityController::class, 'sessions']);
    Route::post('sessions/terminate', [SecurityController::class, 'terminate']);
});
