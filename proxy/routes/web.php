<?php

use App\Http\Controllers\DebugController;
use Illuminate\Support\Facades\Route;

// Scalar API Reference (Default Documentation)
Route::view('/', 'scalar');

// Debug Console (local and staging environment only)
Route::middleware(['local', 'staging'])->group(function () {
    Route::get('/debug', [DebugController::class, 'index']);
});
