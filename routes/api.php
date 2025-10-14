<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\FacilityTypeController;
use App\Http\Controllers\Api\GuestMonitoringController;
use App\Http\Controllers\Api\GuestTypeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RateController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/facilities-test', [FacilityController::class, 'test']);

Route::middleware('auth:sanctum')->group(function () {
    
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    Route::prefix('booking')->group(function () {
        Route::get('/', [BookingController::class, 'index'])
            ->middleware('permission:view-bookings');
        
        Route::post('/', [BookingController::class, 'store'])
            ->middleware('permission:manage-bookings');

        Route::get('/{id}', [BookingController::class, 'show'])
            ->middleware('permission:view-bookings');

        Route::put('/{id}', [BookingController::class, 'update'])
            ->middleware('permission:manage-bookings');

        Route::delete('/{id}', [BookingController::class, 'destroy'])
            ->middleware('permission:manage-bookings');

        Route::get('/archived', [BookingController::class, 'archived'])
            ->middleware('permission:manage-bookings');

        Route::post('/{id}/restore', [BookingController::class, 'restore'])
            ->middleware('permission:manage-bookings');

        Route::post('/{id}/check-in', [BookingController::class, 'checkIn'])
            ->middleware('permission:manage-bookings');

        Route::post('/{id}/check-out', [BookingController::class, 'checkOut'])
            ->middleware('permission:manage-bookings');
    });

    Route::prefix('guest-monitoring')->group(function () {
        Route::get('/', [GuestMonitoringController::class, 'index'])
            ->middleware('permission:view-walk-ins');
        
        Route::post('/', [GuestMonitoringController::class, 'store'])
            ->middleware('permission:process-walk-ins');
        
        Route::get('/{id}', [GuestMonitoringController::class, 'show'])
            ->middleware('permission:view-walk-ins');
        
        Route::put('/{id}', [GuestMonitoringController::class, 'update'])
            ->middleware('permission:process-walk-ins');
        
        Route::post('/{id}/checkout', [GuestMonitoringController::class, 'checkout'])
            ->middleware('permission:checkout-walk-ins');
        
        Route::delete('/{id}', [GuestMonitoringController::class, 'destroy'])
            ->middleware('permission:process-walk-ins');

        Route::get('/archived', [GuestMonitoringController::class, 'archived'])
            ->middleware('permission:manage-walk-ins');

        Route::post('/{id}/restore', [GuestMonitoringController::class, 'restore'])
            ->middleware('permission:manage-walk-ins');
    });
    
    Route::prefix('facilities')->group(function () {
        Route::get('/', [FacilityController::class, 'index'])
            ->middleware('permission:view-facilities');
        
        Route::post('/', [FacilityController::class, 'store'])
            ->middleware('permission:manage-facilities');
        
        Route::get('/archived', [FacilityController::class, 'archived'])
            ->middleware('permission:manage-facilities');
        
        Route::get('/{id}', [FacilityController::class, 'show'])
            ->middleware('permission:view-facilities');
        
        Route::put('/{id}', [FacilityController::class, 'update'])
            ->middleware('permission:manage-facilities');
        
        Route::delete('/{id}', [FacilityController::class, 'destroy'])
            ->middleware('permission:manage-facilities');

        Route::post('/{id}/restore', [FacilityController::class, 'restore'])
            ->middleware('permission:manage-facilities');
    });
    
    Route::prefix('rates')->group(function () {
        Route::get('/', [RateController::class, 'index'])
            ->middleware('permission:view-rates');
        
        Route::post('/', [RateController::class, 'store'])
            ->middleware('permission:manage-rates');
            
        Route::get('/archived', [RateController::class, 'archived'])
            ->middleware('permission:manage-rates');
        
        Route::get('/{id}', [RateController::class, 'show'])
            ->middleware('permission:view-rates');
        
        Route::put('/{id}', [RateController::class, 'update'])
            ->middleware('permission:manage-rates');
        
        Route::delete('/{id}', [RateController::class, 'destroy'])
            ->middleware('permission:manage-rates');

        Route::post('/{id}/restore', [RateController::class, 'restore'])
            ->middleware('permission:manage-rates');
    });
    
    Route::prefix('discounts')->group(function () {
        Route::get('/', [DiscountController::class, 'index'])
            ->middleware('permission:view-discounts');
        
        Route::post('/', [DiscountController::class, 'store'])
            ->middleware('permission:manage-discounts');

        Route::get('/archived', [DiscountController::class, 'archived'])
            ->middleware('permission:manage-discounts');
        
        Route::get('/{id}', [DiscountController::class, 'show'])
            ->middleware('permission:view-discounts');
        
        Route::put('/{id}', [DiscountController::class, 'update'])
            ->middleware('permission:manage-discounts');
        
        Route::delete('/{id}', [DiscountController::class, 'destroy'])
            ->middleware('permission:manage-discounts');

        Route::post('/{id}/restore', [DiscountController::class, 'restore'])
            ->middleware('permission:manage-discounts');
    });
    
    Route::prefix('guest-types')->group(function () {
        Route::get('/', [GuestTypeController::class, 'index']);
        Route::get('/{id}', [GuestTypeController::class, 'show']);
    });
    
    Route::prefix('facility-types')->group(function () {
        Route::get('/', [FacilityTypeController::class, 'index'])
            ->middleware('permission:view-facilities');
        
        Route::post('/', [FacilityTypeController::class, 'store'])
            ->middleware('permission:manage-facilities');
            
        Route::get('/archived', [FacilityTypeController::class, 'archived'])
            ->middleware('permission:manage-facilities');
        
        Route::get('/{id}', [FacilityTypeController::class, 'show'])
            ->middleware('permission:view-facilities');
        
        Route::put('/{id}', [FacilityTypeController::class, 'update'])
            ->middleware('permission:manage-facilities');
        
        Route::delete('/{id}', [FacilityTypeController::class, 'destroy'])
            ->middleware('permission:manage-facilities');

        Route::post('/{id}/restore', [FacilityTypeController::class, 'restore'])
            ->middleware('permission:manage-facilities');
    });
    
    Route::prefix('payments')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])
            ->middleware('permission:view-payments');
        
        Route::post('/', [PaymentController::class, 'store'])
            ->middleware('permission:process-payments');
        
        Route::get('/{id}', [PaymentController::class, 'show'])
            ->middleware('permission:view-payments');
        
        Route::delete('/{id}', [PaymentController::class, 'destroy'])
            ->middleware('permission:process-payments');
    });
    
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])
            ->middleware('permission:view-users');
        
        Route::post('/', [UserController::class, 'store'])
            ->middleware('permission:manage-users');

        // Move archived BEFORE /{id}
        Route::get('/archived', [UserController::class, 'archived'])
            ->middleware('permission:manage-users');
        
        Route::get('/{id}', [UserController::class, 'show'])
            ->middleware('permission:view-users');
        
        Route::put('/{id}', [UserController::class, 'update'])
            ->middleware('permission:manage-users');
        
        Route::delete('/{id}', [UserController::class, 'destroy'])
            ->middleware('permission:manage-users');
        
        Route::post('/{id}/restore', [UserController::class, 'restore'])
            ->middleware('permission:manage-users');
    });

    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])
            ->middleware('permission:manage-users');
    });
});
