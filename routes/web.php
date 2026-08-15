<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('web')->group(function () {
    if (app()->environment('local')) {
        Route::get('/docs', fn () => view('docs'));
    }
});
