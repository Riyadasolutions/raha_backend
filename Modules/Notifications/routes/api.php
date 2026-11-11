<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider. All account types.

Route::middleware('raha.auth')->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('read-all', [NotificationController::class, 'readAll']);
    Route::post('{id}/read', [NotificationController::class, 'read']);
});
