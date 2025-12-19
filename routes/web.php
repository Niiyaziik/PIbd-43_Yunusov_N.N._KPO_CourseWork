<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

// Маршруты аутентификации
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Защищенные маршруты (требуют авторизации)
Route::middleware('auth')->group(function () {
    Route::get('/', [SecurityController::class, 'index'])->name('securities.index');

    Route::get('/securities/{ticker}', [SecurityController::class, 'show'])
        ->where('ticker', '[A-Z]+')
        ->name('securities.show');

    Route::get('/api/securities/{ticker}', [SecurityController::class, 'history'])
        ->where('ticker', '[A-Z]+')
        ->name('api.securities.history');

    Route::get('/api/securities/{ticker}/csv', [SecurityController::class, 'csvData'])
        ->where('ticker', '[A-Z]+')
        ->name('api.securities.csv');

    Route::get('/securities/{ticker}/export/{format}', [SecurityController::class, 'export'])
        ->where('ticker', '[A-Z]+')
        ->where('format', 'excel|pdf')
        ->name('securities.export');

    // Админ-панель (только для админа)
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/stocks', [\App\Http\Controllers\AdminController::class, 'index'])->name('stocks');
        Route::patch('/stocks/{stock}', [\App\Http\Controllers\AdminController::class, 'updateAvailability'])->name('stocks.update');
        Route::post('/stocks/add', [\App\Http\Controllers\AdminController::class, 'addTicker'])->name('stocks.add');
        Route::delete('/stocks/{stock}', [\App\Http\Controllers\AdminController::class, 'delete'])->name('stocks.delete');
        Route::get('/stocks/{ticker}/model-status', [\App\Http\Controllers\AdminController::class, 'checkModelStatus'])->name('stocks.model-status');
    });
});
