<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\CatalogOptionsController;
use Modules\Catalog\Http\Controllers\ProviderController;
use Modules\Catalog\Http\Controllers\ServiceController;

// Routes are prefixed with /api/v1 by the module RouteServiceProvider.

// Public homepage: active, publicly visible services only (front publicServices).
Route::get('public/services', [ServiceController::class, 'publicIndex']);

Route::middleware('raha.auth')->group(function () {
    // Any authenticated account (incl. beneficiaries): request wizard + option lists.
    Route::get('services/requestable', [ServiceController::class, 'requestable']);
    Route::get('catalog/options', CatalogOptionsController::class);

    Route::middleware('account:STAFF')->group(function () {
        Route::get('services', [ServiceController::class, 'index']);
        Route::post('services', [ServiceController::class, 'store']);
        Route::get('services/{id}', [ServiceController::class, 'show']);
        Route::put('services/{id}', [ServiceController::class, 'update']);
        Route::patch('services/{id}/toggle', [ServiceController::class, 'toggle']);

        Route::post('providers', [ProviderController::class, 'store']);
        Route::put('providers/{id}', [ProviderController::class, 'update']);
        Route::post('providers/{id}/services', [ProviderController::class, 'addService']);
        Route::delete('providers/{id}/services/{serviceId}', [ProviderController::class, 'removeService']);
        Route::post('providers/{id}/notes', [ProviderController::class, 'addNote']);
        Route::get('providers/{id}/cases', [ProviderController::class, 'cases']);
    });

    Route::middleware('account:STAFF,PROVIDER')->group(function () {
        Route::get('providers', [ProviderController::class, 'index']);
        Route::get('providers/{id}', [ProviderController::class, 'show']);
    });
});
