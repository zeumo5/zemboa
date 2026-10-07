<?php


use App\Http\Controllers\StockController;
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

Route::get(
    '/product-variants/{productVariant}',
    [ProductVariantController::class, 'show']
)->name('product-variants.show');

Route::put(
    '/product-variants/{productVariant}',
    [ProductVariantController::class, 'update']
)->name('product-variants.update');

Route::delete(
    '/product-variants/{productVariant}',
    [ProductVariantController::class, 'destroy']
)->name('product-variants.destroy');

Route::get('/stock', [StockController::class, 'index'])
    ->name('stock.index');

Route::get('/stock/{stockLevel}', [StockController::class, 'show'])
    ->name('stock.show');

Route::post('/stock/{stockLevel}/receive', [StockController::class, 'receive'])
    ->name('stock.receive');

Route::post('/stock/{stockLevel}/adjust-in', [StockController::class, 'adjustIn'])
    ->name('stock.adjust-in');

Route::post('/stock/{stockLevel}/adjust-out', [StockController::class, 'adjustOut'])
    ->name('stock.adjust-out');

});
