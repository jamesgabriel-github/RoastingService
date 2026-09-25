<?php

use App\Http\Controllers\Api\Admin\AdminAccountController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('admin')->group(function () {
        Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:6,1');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->middleware('auth:sanctum');

        Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
            Route::get('/accounts', [AdminAccountController::class, 'index']);
            Route::post('/accounts', [AdminAccountController::class, 'store']);
            Route::patch('/accounts/{id}', [AdminAccountController::class, 'update']);
        });

        Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'module:services'])->group(function () {
            Route::get('/services', [AdminServiceController::class, 'index']);
            Route::post('/services', [AdminServiceController::class, 'store']);
            Route::put('/services/{id}', [AdminServiceController::class, 'update']);
            Route::patch('/services/{id}/toggle', [AdminServiceController::class, 'toggle']);
        });
    });

    Route::post('/login', [CustomerAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/logout', [CustomerAuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/profile/complete', [ProfileController::class, 'complete'])->middleware('auth:sanctum');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');

    Route::get('/me', MeController::class)->middleware('auth:sanctum');

    Route::get('/services', [ServiceController::class, 'index']);
});
