<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->to('/docs/api'));
Route::get('/docs', fn () => redirect()->to('/docs/api'));
