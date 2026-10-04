<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/categories', [CategoryController::class, 'index'])
        ->name('categories.index');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('categories.store');

    Route::put('/categories/{category}', [CategoryController::class, 'update'])
    ->name('categories.update');   
    
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
    ->name('categories.destroy');
});
