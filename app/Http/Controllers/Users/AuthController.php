<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Models\PhoneVerification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
    }

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

    public function sendPhoneOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        // Check if phone is already registered
        if (User::where('phone_number', $request->phone)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number already registered'
            ], 422);
        }

        // Call Termii API
        $response = Http::post(env('TERMII_BASE_URL').'/api/sms/otp/send', [
            "api_key" => env('TERMII_API_KEY'),
            "message_type" => "NUMERIC",
            "to" => $request->phone,
            "from" => env('TERMII_SENDER_ID'),
            "channel" => "generic",
            "pin_attempts" => 3,
            "pin_time_to_live" => 5,
            "pin_length" => 6,
            "pin_placeholder" => "< 1234 >",
            "message_text" => "Your verification code is < 1234 >",
            "pin_type" => "NUMERIC"
        ]);

        $result = $response->json();

        if (!isset($result['pinId'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send OTP',
                'data' => $result
            ], 500);
        }

        // Save OTP info in phone_verifications table
        PhoneVerification::updateOrCreate(
            ['phone_number' => $request->phone],
            [
                'pin_id' => $result['pinId'],
                'otp' => null,
                'verified' => false,
                'expires_at' => now()->addMinutes(5)
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'OTP sent successfully',
            'data' => $result
        ]);
    }

    // 2️⃣ Verify OTP
    public function verifyPhoneOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'pin_id' => 'required|string',
            'pin' => 'required|string',
        ]);

        $verification = PhoneVerification::where('phone_number', $request->phone)->first();
        if (!$verification) {
            return response()->json([
                'status' => 'error',
                'message' => 'No OTP request found for this phone'
            ], 404);
        }

        // Check expiration
        if ($verification->expires_at->isPast()) {
            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired'
            ], 422);
        }

        // Call Termii verify
        $response = Http::post(env('TERMII_BASE_URL').'/api/sms/otp/verify', [
            "api_key" => env('TERMII_API_KEY'),
            "pin_id" => $request->pin_id,
            "pin" => $request->pin
        ]);

        $result = $response->json();

        if (isset($result['verified']) && $result['verified'] === true) {
            $verification->update([
                'verified' => true,
                'otp' => $request->pin,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Phone number verified successfully'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid OTP'
        ], 422);
    }

    public function setPassword(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $verification = PhoneVerification::where('phone_number', $request->phone_number)->first();

        if (!$verification || !$verification->verified) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number not verified'
            ], 422);
        }

        // Check if user already exists
        $user = User::where('phone_number', $request->phone_number)->first();

        if ($user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User already exists, please login'
            ], 422);
        }

        // Create user with ONLY phone + password
        $user = User::create([
            'phone_number' => $request->phone_number,
            'password' => bcrypt($request->password),
            'phone_verified_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password set successfully. You can now login.',
            'data' => $user
        ], 201);
    }

    // 3️⃣ Register user (only after OTP verified)

    public function login(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone_number', $request->phone_number)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid phone number or password'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => $user
            ]
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
