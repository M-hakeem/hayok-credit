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

    // 3️⃣ Register user (only after OTP verified)
    public function register(RegisterUserRequest  $request)
    {


        // Make sure the column matches your PhoneVerification table
        $verification = PhoneVerification::where('phone_number', $request->phone_number)->first();

        if (!$verification || !$verification->verified) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number must be verified before registration'
            ], 422);
        }

        $user = User::create([
            'fullname' => $request->fullname,
            'dob' => $request->dob,
            'gender' => $request->gender,
            'email' => $request->email,
            'residential_address' => $request->residential_address,
            'state' => $request->state,
            'lga' => $request->lga,
            'bnv' => $request->bnv,
            'phone_number' => $request->phone_number,
            'password' => bcrypt($request->password),
            'phone_verified_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => $user
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ], 401);
        }

        // Create token with expiration
        $tokenResult = $user->createToken('auth_token');
        $token = $tokenResult->plainTextToken;
        $expiresAt = Carbon::now()->addHours(72)->toIso8601String();

        return response()->json([
            'status' => 'success',
            'message' => 'Login Successful',
            'data' => [
                'token' => $token,
                'expired_at' => $expiresAt,
                'staff' => $user
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
