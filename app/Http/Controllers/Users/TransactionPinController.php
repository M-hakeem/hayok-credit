<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Services\TermiiOtpService;
use App\Services\TransactionPinService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;

class TransactionPinController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'has_pin' => $request->user()->hasTransactionPin(),
            ],
        ]);
    }

    #[BodyParameter('pin', type: 'string', required: true, description: 'Four-digit transaction PIN')]
    #[BodyParameter('pin_confirmation', type: 'string', required: true, description: 'Must match pin')]
    #[BodyParameter('current_pin', type: 'string', required: false, description: 'Required when changing an existing PIN')]
    public function store(Request $request, TransactionPinService $pinService)
    {
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
            'pin_confirmation' => ['required', 'same:pin'],
            'current_pin' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
        ]);

        $user = $request->user();
        if ($user->hasTransactionPin()) {
            if (blank($data['current_pin'] ?? null)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Enter your current transaction PIN to change it.',
                ], 422);
            }

            $pinService->verify($user, $data['current_pin']);
        }

        $user->setTransactionPin($data['pin']);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction PIN saved successfully.',
            'data' => ['has_pin' => true],
        ]);
    }

    public function sendResetOtp(Request $request, TransactionPinService $pinService, TermiiOtpService $otpService)
    {
        $pinService->sendResetOtp($request->user(), $otpService);

        return response()->json([
            'status' => 'success',
            'message' => 'If your verified number is eligible, a verification code will be sent shortly.',
        ], 202);
    }

    #[BodyParameter('otp', type: 'string', required: true, description: 'Six-digit code sent to the registered phone number')]
    #[BodyParameter('pin', type: 'string', required: true, description: 'New four-digit transaction PIN')]
    #[BodyParameter('pin_confirmation', type: 'string', required: true, description: 'Must match pin')]
    public function reset(Request $request, TransactionPinService $pinService, TermiiOtpService $otpService)
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
            'pin_confirmation' => ['required', 'same:pin'],
        ]);

        $pinService->resetWithOtp(
            $request->user(),
            $data['otp'],
            $data['pin'],
            $otpService,
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction PIN reset successfully.',
            'data' => ['has_pin' => true],
        ]);
    }
}
