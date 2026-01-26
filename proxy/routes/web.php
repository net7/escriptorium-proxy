<?php

use App\Http\Controllers\DebugController;
use Illuminate\Support\Facades\Route;

// Scalar API Reference (Default Documentation)
Route::view('/', 'scalar');

// Debug Console (local environment only)
Route::middleware(['local'])->group(function () {
    Route::get('/debug', [DebugController::class, 'index']);
});
