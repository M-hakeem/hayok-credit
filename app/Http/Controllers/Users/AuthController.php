<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\TermiiOtpService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() {}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    #[BodyParameter('phone', type: 'string', required: true, description: 'Phone number to send OTP to (e.g. +2347061234567)')]
    public function sendPhoneOtp(Request $request, TermiiOtpService $termiiOtpService)
    {
        $request->validate([
            'phone' => 'required|string|min:8|max:24',
        ]);

        $phone = $termiiOtpService->normalizePhone($request->phone);
        $existingUser = User::where('phone_number', $request->phone)->orWhere('phone_number', $phone)->first();
        if (! $existingUser) {
            $termiiOtpService->requestOtp($phone);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'If this number is eligible, a verification code will be sent shortly.',
        ], 202);
    }

    public function resendPhoneOtp(Request $request, TermiiOtpService $termiiOtpService)
    {
        $request->validate([
            'phone' => 'required_without:phone_number|string|min:8|max:24',
            'phone_number' => 'required_without:phone|string|min:8|max:24',
        ]);

        $rawPhone = $request->input('phone', $request->input('phone_number'));
        $phone = $termiiOtpService->normalizePhone($rawPhone);
        $existingUser = User::where('phone_number', $rawPhone)->orWhere('phone_number', $phone)->first();
        $verification = PhoneVerification::where('phone_number', $phone)->first();

        if (! $existingUser) {
            $termiiOtpService->requestOtp($phone);
        } elseif ($existingUser->password && $verification?->purpose === 'password_reset') {
            $termiiOtpService->requestOtp($phone, 'password_reset');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'If this number is eligible, a verification code will be sent shortly.',
        ], 202);
    }

    // 2️⃣ Verify OTP
    #[BodyParameter('phone', type: 'string', required: true, description: 'Phone number the OTP was sent to')]
    #[BodyParameter('pin', type: 'string', required: true, description: 'The 6-digit OTP code sent to the phone')]
    public function verifyPhoneOtp(Request $request, TermiiOtpService $termiiOtpService)
    {
        $request->validate([
            'phone' => 'required|string|min:8|max:24',
            'pin' => 'required|string|size:6',
        ]);

        $result = $termiiOtpService->verifyOtp($request->phone, $request->pin);
        if ($result === 'verified') {
            return response()->json([
                'status' => 'success',
                'message' => 'Phone number verified successfully',
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $result === 'too_many_attempts'
                ? 'Too many verification attempts. Please request a new code.'
                : 'Unable to verify this code. Request a new code and try again.',
        ], $result === 'too_many_attempts' ? 429 : 422);
    }

    #[BodyParameter('phone_number', type: 'string', required: true, description: 'Verified phone number')]
    #[BodyParameter('password', type: 'string', required: true, description: 'Password (min 6 characters)')]
    #[BodyParameter('password_confirmation', type: 'string', required: true, description: 'Must match the password field')]
    public function setPassword(Request $request, TermiiOtpService $termiiOtpService)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $phone = $termiiOtpService->normalizePhone($request->phone_number);
        $existingUser = User::where('phone_number', $request->phone_number)->orWhere('phone_number', $phone)->first();

        // Partner-created user — phone already verified, just set the password
        if ($existingUser && $existingUser->phone_verified_at) {
            if ($existingUser->password) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Password already set. Please login.',
                ], 422);
            }

            $existingUser->update(['password' => $request->password]);

            return response()->json([
                'status' => 'success',
                'message' => 'Password set successfully. You can now login.',
                'data' => $existingUser,
            ], 200);
        }

        // Normal OTP flow
        $verification = PhoneVerification::where('phone_number', $phone)->first();

        if (! $verification || ! $verification->verified || $verification->purpose !== 'registration') {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number not verified.',
            ], 422);
        }

        if (! $verification->expires_at || $verification->expires_at->isPast()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number not verified.',
            ], 422);
        }

        if ($existingUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'User already exists. Please login.',
            ], 422);
        }

        $user = User::create([
            'phone_number' => $phone,
            'password' => $request->password,
            'phone_verified_at' => now(),
        ]);
        $termiiOtpService->consume($verification);

        return response()->json([
            'status' => 'success',
            'message' => 'Password set successfully. You can now login.',
            'data' => $user,
        ], 201);
    }

    // 3️⃣ Register user (only after OTP verified)

    #[BodyParameter('phone_number', type: 'string', required: true, description: 'Registered phone number')]
    #[BodyParameter('password', type: 'string', required: true, description: 'Account password')]
    public function login(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone_number', $request->phone_number)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid phone number or password',
            ], 401);
        }

        if ($user->is_blacklisted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
        ]);
    }

    /**
     * Revoke the access token used for the current request.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful',
        ]);
    }

    // 4️⃣ Forgot password — send OTP to a registered phone
    #[BodyParameter('phone_number', type: 'string', required: true, description: 'Registered phone number to reset the password for')]
    public function forgotPassword(Request $request, TermiiOtpService $termiiOtpService)
    {
        $request->validate([
            'phone_number' => 'required|string',
        ]);

        $phone = $termiiOtpService->normalizePhone($request->phone_number);
        $user = User::where('phone_number', $request->phone_number)->orWhere('phone_number', $phone)->first();
        if ($user?->password) {
            $termiiOtpService->requestOtp($phone, 'password_reset');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'If this number is eligible, a verification code will be sent shortly.',
        ], 202);
    }

    // 5️⃣ Reset password — after OTP verified via verify-otp
    #[BodyParameter('phone_number', type: 'string', required: true, description: 'Verified phone number')]
    #[BodyParameter('password', type: 'string', required: true, description: 'New password (min 6 characters)')]
    #[BodyParameter('password_confirmation', type: 'string', required: true, description: 'Must match the password field')]
    public function resetPassword(Request $request, TermiiOtpService $termiiOtpService)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $phone = $termiiOtpService->normalizePhone($request->phone_number);
        $verification = PhoneVerification::where('phone_number', $phone)->first();

        if (! $verification || ! $verification->verified || $verification->purpose !== 'password_reset') {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number not verified.',
            ], 422);
        }

        if (! $verification->expires_at || $verification->expires_at->isPast()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Verification has expired. Please request a new OTP.',
            ], 422);
        }

        $user = User::where('phone_number', $request->phone_number)->orWhere('phone_number', $phone)->first();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No account found with this phone number.',
            ], 404);
        }

        $user->update(['password' => $request->password]);

        // Invalidate the verification so it can't be replayed, and log out other sessions
        $termiiOtpService->invalidate($verification);
        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully. You can now login.',
        ]);
    }

    #[BodyParameter('phone_number', type: 'string', required: true, description: 'Admin phone number')]
    #[BodyParameter('password', type: 'string', required: true, description: 'Admin account password')]
    public function adminLogin(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone_number', $request->phone_number)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid phone number or password',
            ], 401);
        }

        if ($user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        if ($user->is_blacklisted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        $token = $user->createToken('admin_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Admin login successful',
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
