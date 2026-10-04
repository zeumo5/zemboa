<?php

use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\ProductController;
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

    Route::post('/products', [ProductController::class, 'store'])
    ->name('products.store');

Route::get('/products/{product}', [ProductController::class, 'show'])
    ->name('products.show');

    Route::put('/products/{product}', [ProductController::class, 'update'])
    ->name('products.update');

    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
    ->name('products.destroy');

    Route::post(
    '/products/{product}/variants',
    [ProductVariantController::class, 'store']
)->name('product-variants.store');
});
