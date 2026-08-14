<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\BusinessLoanApplicationController;
use App\Http\Controllers\ClaimifyWalletController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmploymentController;
use App\Http\Controllers\GuarantorController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanDisbursementController;
use App\Http\Controllers\LoanInterestSettingController;
use App\Http\Controllers\LoanPaymentController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\FirstCentralController;
use App\Http\Controllers\PartnerLoanApplicationController;
use App\Http\Controllers\Users\AuthController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('send-otp', [AuthController::class, 'sendPhoneOtp'])->middleware('throttle:send-otp');
    Route::post('verify-otp', [AuthController::class, 'verifyPhoneOtp'])->middleware('throttle:verify-otp');
    Route::post('set-password', [AuthController::class, 'setPassword']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('admin-login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:verify-otp');
});

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::put('update-profile', [UserController::class, 'update']);

    // Wallet routes must come before the {id} wildcard
    Route::get('wallet', [WalletController::class, 'show']);
    Route::post('wallet/banks', [ClaimifyWalletController::class, 'banks']);
    Route::post('wallet/name-inquiry', [ClaimifyWalletController::class, 'nameInquiry'])->middleware('throttle:30,1');
    Route::post('wallet/verification/initiate', [ClaimifyWalletController::class, 'initiateVerification'])->middleware('throttle:5,1');
    Route::post('wallet/verification/validate', [ClaimifyWalletController::class, 'validateVerification'])->middleware('throttle:10,1');
    Route::post('wallet/create-customer', [ClaimifyWalletController::class, 'createCustomer']);
    Route::post('wallet/first-central/consumer-match', [FirstCentralController::class, 'consumerMatch'])->middleware('throttle:30,1');
    Route::post('wallet/bank', [WalletController::class, 'updateBankDetails']);
    Route::post('wallet/deposit', [WalletController::class, 'deposit']);
    Route::post('wallet/withdraw', [WalletController::class, 'withdraw']);

    // Loan routes must come before the {id} wildcard
    Route::prefix('loans')->group(function () {
        Route::get('/', [LoanController::class, 'index']);
        Route::post('/', [LoanController::class, 'store']);
        Route::get('{id}', [LoanController::class, 'show']);
        Route::get('{id}/payments', [LoanPaymentController::class, 'index']);
        Route::post('{id}/payments', [LoanPaymentController::class, 'store']);
    });

    // Wildcard routes last so they don't shadow named routes above
    Route::get('{id}', [UserController::class, 'show']);
    Route::delete('{id}', [UserController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->get('loan-interest', [LoanInterestSettingController::class, 'index']);

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('business-loan-applications', [BusinessLoanApplicationController::class, 'index']);
    Route::get('business-loan-applications/{businessLoanApplication}', [BusinessLoanApplicationController::class, 'show']);
    Route::post('loan-interest', [LoanInterestSettingController::class, 'store']);
    Route::put('/loan-interest/{loanInterestSetting}', [LoanInterestSettingController::class, 'update']);
    Route::delete('/loan-interest/{loanInterestSetting}', [LoanInterestSettingController::class, 'destroy']);
    Route::get('loans', [LoanController::class, 'adminIndex']);
    Route::get('loans/{id}', [LoanController::class, 'adminShow']);
    Route::post('loans/{id}/approve', [LoanController::class, 'approve']);
    Route::post('loans/{id}/reject', [LoanController::class, 'reject']);
    Route::post('loans/{id}/disburse', [LoanDisbursementController::class, 'disburse']);
    Route::get('loan-disbursements', [LoanDisbursementController::class, 'index']);
    Route::get('loan-disbursements/history', [LoanDisbursementController::class, 'disbursedLoanHistory']);
    Route::get('loan-payments', [LoanPaymentController::class, 'adminIndex']);
    Route::get('loans/{id}/payments', [LoanPaymentController::class, 'adminLoanPayments']);
    Route::put('user/status/{id}', [UserController::class, 'updateStatus']);

    // Organisation management
    Route::get('organisations', [OrganisationController::class, 'index']);
    Route::post('organisations', [OrganisationController::class, 'store']);
    Route::get('organisations/{id}', [OrganisationController::class, 'show']);
    Route::put('organisations/{id}', [OrganisationController::class, 'update']);
    Route::delete('organisations/{id}', [OrganisationController::class, 'destroy']);
    Route::post('organisations/{id}/regenerate-key', [OrganisationController::class, 'regenerateKey']);
    Route::get('organisations/{id}/users', [OrganisationController::class, 'users']);
});

// Partner integration endpoint — secured by X-Partner-Key header
Route::middleware('partner')->prefix('partner')->group(function () {
    Route::post('loan-application', [PartnerLoanApplicationController::class, 'store']);
    Route::post('business-loan-applications', [BusinessLoanApplicationController::class, 'store']);
    Route::get('loan-application/{phone}', [PartnerLoanApplicationController::class, 'show']);
    Route::get('loan-interest', [PartnerLoanApplicationController::class, 'loanInterestRates']);
});

Route::middleware('auth:sanctum')->apiResource('address', AddressController::class);
Route::middleware('auth:sanctum')->apiResource('employment', EmploymentController::class);
Route::middleware('auth:sanctum')->apiResource('guarantor', GuarantorController::class);
Route::middleware('auth:sanctum')->post('guarantor/{id}/id-document', [GuarantorController::class, 'uploadIdDocument']);
