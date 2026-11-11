<?php

use Illuminate\Support\Facades\Route;

// Core routes live in modules (Modules/*/routes/api.php), all under /api/v1.
Route::get('health', fn () => ['status' => 'ok', 'time' => now_iso()]);
