<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\SettingsController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

Route::prefix('public')->group(function () {
    Route::get('bootstrap', [SettingsController::class, 'bootstrap']);
    Route::post('contact', [SettingsController::class, 'contact'])->middleware('throttle:contact');
});

Route::get('settings/language', [SettingsController::class, 'language']);

// Association identity is read by every portal (letters/quotations shown to providers); only staff may edit settings.
Route::middleware('raha.auth')->get('settings/organization', [SettingsController::class, 'organization']);

Route::middleware(['raha.auth', 'account:STAFF'])->prefix('settings')->group(function () {
    Route::put('organization', [SettingsController::class, 'saveOrganization']);
    Route::put('language', [SettingsController::class, 'saveLanguage']);
    Route::get('fast-track', [SettingsController::class, 'fastTrack']);
    Route::put('fast-track', [SettingsController::class, 'saveFastTrack']);
});
