<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\EmploymentController;
use App\Http\Controllers\GuarantorController;
use App\Http\Controllers\Users\AuthController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('send-otp', [AuthController::class, 'sendPhoneOtp']);
    Route::post('verify-otp', [AuthController::class, 'verifyPhoneOtp']);
    Route::post('set-password', [AuthController::class, 'setPassword']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::get('{id}', [UserController::class, 'show']);
    Route::put('update-profile', [UserController::class, 'update']);

    Route::delete('{id}', [UserController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->apiResource('address', AddressController::class);
Route::middleware('auth:sanctum')->apiResource('employment', EmploymentController::class);
Route::middleware('auth:sanctum')->apiResource('guarantor', GuarantorController::class);
