<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PaymentController;

Route::get('/test', function () {
    return response()->json(['message' => 'API works']);
});

Route::post('/register', [RegistrationController::class, 'store']);
Route::post('/payments/fake/confirm/{payment}', [PaymentController::class, 'confirmFakePayment'])->name('payments.fake.confirm');
Route::post('/payments/fake/fail/{payment}', [PaymentController::class, 'failFakePayment'])->name('payments.fake.fail');
Route::post('/payments/stripe/webhook', [PaymentController::class, 'stripeWebhook'])->name('payments.stripe.webhook');
Route::post('/payments/paypal/webhook', [PaymentController::class, 'paypalWebhook'])->name('payments.paypal.webhook');

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{slug}', [CourseController::class, 'show']);
Route::post('/courses', [CourseController::class, 'store']);

Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
Route::get('/admin/registrations', [AdminController::class, 'registrations']);
Route::patch('/admin/registrations/{id}/status', [AdminController::class, 'updateRegistrationStatus']);