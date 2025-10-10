<?php

use App\Http\Controllers\Api\AuditTrailController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingServiceController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\FacilityTypeController;
use App\Http\Controllers\Api\GuestEntryController;
use App\Http\Controllers\Api\GuestTypeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RateController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->name('login');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    //Audit Trail routes
    Route::apiResource('audit-trails', AuditTrailController::class);

    // Auth routes
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Booking routes
    Route::get('bookings/archived', [BookingController::class, 'archived']);
    Route::apiResource('bookings', BookingController::class);

    // Booking Service routes
    Route::get('booking-services/archived', [BookingServiceController::class, 'archived']);
    Route::apiResource('booking-services', BookingServiceController::class);

    // Discount routes
    Route::get('discounts/archived', [DiscountController::class, 'archived']);
    Route::post('discounts/{id}/restore', [DiscountController::class, 'restore']);  
    Route::apiResource('discounts', DiscountController::class);

    // Facility routes
    Route::get('facilities/archived', [FacilityController::class, 'archived']);
    Route::post('facilities/{facility}/restore', [FacilityController::class, 'restore']);
    Route::put('facilities/{facility}/maintenance', [FacilityController::class, 'toggleMaintenance']);
    Route::put('facilities/{facility}/availability', [FacilityController::class, 'toggleBookingAvailability']);
    Route::apiResource('facilities', FacilityController::class);

    // Facility types
    Route::get('facility-types/archived', [FacilityTypeController::class, 'archived']);
    Route::post('facility-types/{id}/restore', [FacilityTypeController::class, 'restore']);
    Route::apiResource('facility-types', FacilityTypeController::class);

    // Guest Entry routes
    Route::get('guest-entries/archived', [GuestEntryController::class, 'archived']);
    Route::apiResource('guest-entries', GuestEntryController::class);

    // Guest Type routes
    Route::get('guest-types/archived', [GuestTypeController::class, 'archived']);
    Route::apiResource('guest-types', GuestTypeController::class);

    // Payment routes
    Route::get('payments/archived', [PaymentController::class, 'archived']);
    Route::apiResource('payments', PaymentController::class);

    // Rate routes
    Route::get('rates/archived', [RateController::class, 'archived']);
    Route::post('rates/{id}/restore', [RateController::class, 'restore']);
    Route::apiResource('rates', RateController::class);

    // User routes
    Route::get('users/archived', [UserController::class, 'archived']);
    Route::post('users/{id}/restore', [UserController::class, 'restore']);
    Route::apiResource('users', UserController::class);
});
