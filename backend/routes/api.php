<?php

use App\Http\Controllers\Api\Admin\AdminAccountController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\BookingPaymentController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Api\Admin\WalkInController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\BookingController;
use App\Http\Controllers\Api\Customer\OrderController;
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

        Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'module:inventory'])->group(function () {
            Route::get('/inventory', [AdminInventoryController::class, 'index']);
            Route::get('/inventory/logs', [AdminInventoryController::class, 'logs']);
            Route::post('/inventory/{service}/restock', [AdminInventoryController::class, 'restock']);
            Route::post('/inventory/{service}/adjust', [AdminInventoryController::class, 'adjust']);
        });

        Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'module:bookings'])->group(function () {
            Route::get('/bookings/counts', [AdminBookingController::class, 'counts']);
            Route::get('/bookings', [AdminBookingController::class, 'index']);
            Route::get('/bookings/customers', [WalkInController::class, 'searchCustomers']);
            Route::post('/bookings/walk-in-roasting', [WalkInController::class, 'storeRoasting']);
            Route::post('/bookings/walk-in-shop', [WalkInController::class, 'storeShop']);
            Route::get('/bookings/{id}', [AdminBookingController::class, 'show'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/approve', [AdminBookingController::class, 'approve'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/reject', [AdminBookingController::class, 'reject'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/weigh-in', [AdminBookingController::class, 'weighIn'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/confirm-order', [AdminBookingController::class, 'confirmOrder'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/reject-order', [AdminBookingController::class, 'rejectOrder'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/start-cooking', [AdminBookingController::class, 'startCooking'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/ready', [AdminBookingController::class, 'ready'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/out-for-delivery', [AdminBookingController::class, 'outForDelivery'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/complete', [AdminBookingController::class, 'complete'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/no-show', [AdminBookingController::class, 'noShow'])->where('id', '[0-9]{1,18}');
            Route::post('/bookings/{id}/cancel', [AdminBookingController::class, 'cancel'])->where('id', '[0-9]{1,18}');
        });

        Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'module:payments'])->group(function () {
            Route::get('/payments', [AdminPaymentController::class, 'index']);
            Route::post('/bookings/{id}/payments', [BookingPaymentController::class, 'store'])->where('id', '[0-9]{1,18}');
        });

        Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'module:dashboard'])->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        });
    });

    Route::post('/login', [CustomerAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/logout', [CustomerAuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/profile/complete', [ProfileController::class, 'complete'])->middleware('auth:sanctum');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'role:customer'])->group(function () {
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{id}', [BookingController::class, 'show'])->where('id', '[0-9]{1,18}');
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->where('id', '[0-9]{1,18}');
        Route::post('/orders', [OrderController::class, 'store']);
    });

    Route::get('/me', MeController::class)->middleware('auth:sanctum');

    Route::get('/services', [ServiceController::class, 'index']);
});
