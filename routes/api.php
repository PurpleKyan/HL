<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\GuideController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\TrailController;
use Illuminate\Support\Facades\Route;

// ---------- Laboratory 1: connection test ----------
Route::get('/hello', fn () => response()->json(['message' => 'Hello from Laravel!']));

// ---------- Public ----------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/register/check-email', [AuthController::class, 'checkRegistrationEmail'])->middleware('throttle:30,1');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [AuthController::class, 'sendPasswordResetCode'])->middleware('throttle:5,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

Route::get('/trails', [TrailController::class, 'index']);
Route::get('/trails/locations', [TrailController::class, 'locations']);
Route::get('/trails/{id}', [TrailController::class, 'show'])->whereNumber('id');
Route::get('/trails/{id}/reviews', [TrailController::class, 'reviews'])->whereNumber('id');
Route::get('/guides', [TrailController::class, 'guides']);

// ---------- Logged-in users ----------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/recommendations', [RecommendationController::class, 'recommend'])->middleware('role:hiker,admin');

    // Hiker
    Route::middleware('role:hiker')->group(function () {
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::patch('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->whereNumber('id');
        Route::delete('/bookings/{id}', [BookingController::class, 'deleteCompleted'])->whereNumber('id');
        Route::post('/bookings/{id}/review', [BookingController::class, 'review'])->whereNumber('id');
    });

    // Guide / Tourism Officer
    Route::prefix('guide')->middleware('role:guide')->group(function () {
        Route::get('/trails', [GuideController::class, 'trails']);
        Route::post('/trails', [GuideController::class, 'storeTrail']);
        Route::get('/visibility', [GuideController::class, 'visibility']);
        Route::patch('/visibility/toggle', [GuideController::class, 'toggleVisibility']);
        Route::get('/bookings', [GuideController::class, 'bookings']);
        Route::delete('/bookings/{id}', [GuideController::class, 'deleteCompletedBooking'])->whereNumber('id');
        Route::patch('/bookings/{id}/status', [GuideController::class, 'updateBookingStatus'])->whereNumber('id');
        Route::get('/reports', [GuideController::class, 'reports']);
    });

    // Administrator
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}/guide-qualification', [AdminController::class, 'guideQualificationPhoto'])->whereNumber('id');
        Route::get('/reviews', [AdminController::class, 'reviews']);
        Route::delete('/reviews/{id}', [AdminController::class, 'deleteReview'])->whereNumber('id');
        Route::get('/flagged-users', [AdminController::class, 'flaggedUsers']);
        Route::patch('/users/{id}/toggle', [AdminController::class, 'toggleUser'])->whereNumber('id');
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser'])->whereNumber('id');
        Route::patch('/users/{id}/validation', [AdminController::class, 'reviewValidation'])->whereNumber('id');
        Route::patch('/users/{id}/guide-review', [AdminController::class, 'reviewGuideApplication'])->whereNumber('id');
        Route::get('/trails', [AdminController::class, 'trails']);
        Route::post('/trails', [AdminController::class, 'storeTrail']);
        Route::patch('/trails/{id}', [AdminController::class, 'updateTrail'])->whereNumber('id');
        Route::post('/trails/{id}/picture', [AdminController::class, 'updateTrailPicture'])->whereNumber('id');
        Route::post('/trails/{id}/photos', [AdminController::class, 'uploadTrailPhotos'])->whereNumber('id');
        Route::delete('/trails/{trailId}/photos/{photoId}', [AdminController::class, 'deleteTrailPhoto'])
            ->whereNumber('trailId');
        Route::patch('/trails/{id}/verify', [AdminController::class, 'verifyTrail'])->whereNumber('id');
        Route::patch('/trails/{id}/visibility', [AdminController::class, 'toggleTrailVisibility'])->whereNumber('id');
        Route::get('/bookings', [AdminController::class, 'bookings']);
        Route::get('/reports', [AdminController::class, 'reports']);
    });
});
