<?php

use App\Http\Controllers\API\v1\eScriptoriumController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes require API key authentication via X-API-Key header.
| Permissions are checked based on the route group.
|
*/

Route::group(['prefix' => '/v1', 'as' => 'escriptorium.', 'middleware' => 'api.key'], function (): void {
    Route::get('/up', [eScriptoriumController::class, 'up'])->name('up')->middleware('api.key:up');

    Route::group(['prefix' => '/models', 'as' => 'models.', 'middleware' => 'api.key:models'], function (): void {
        Route::get('/', [eScriptoriumController::class, 'models'])->name('list');
        // Route::post('/', [eScriptoriumController::class, 'newModel'])->name('new'); // DISABLED
    });

    Route::group(['prefix' => '/scripts', 'as' => 'scripts.', 'middleware' => 'api.key:scripts'], function (): void {
        Route::get('/', [eScriptoriumController::class, 'scripts'])->name('list');
    });

    Route::group(['prefix' => '/process', 'as' => 'process.', 'middleware' => 'api.key:process'], function (): void {
        Route::post('/manifest', [eScriptoriumController::class, 'processManifest'])->name('manifest');
        Route::post('/images', [eScriptoriumController::class, 'processImages'])->name('images');
        Route::get('/{id}', [eScriptoriumController::class, 'show'])->name('show');
    });
});
