<?php

use App\Http\Controllers\Api\V1\eScriptoriumController;
use Illuminate\Support\Facades\Route;

Route::group(['as' => 'escriptorium.'], function (): void {
    Route::get('/up', [eScriptoriumController::class, 'up'])->name('up');
    Route::group(['prefix' => '/models', 'as' => 'models.'], function (): void {
        Route::get('/', [eScriptoriumController::class, 'models'])->name('list');
        // Route::post('/', [eScriptoriumController::class, 'newModel'])->name('new');
    });
    Route::group(['prefix' => '/scripts', 'as' => 'scripts.'], function (): void {
        Route::get('/', [eScriptoriumController::class, 'scripts'])->name('list');
    });
    Route::group(['prefix' => '/process', 'as' => 'process.'], function (): void {
        Route::post('/', [eScriptoriumController::class, 'process'])->name('create');
    });
});
