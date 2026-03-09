<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
    public function register(RegisterUserRequest $request)
    {
        // Create user with hashed password
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
            'password' => bcrypt($request->password), // hash password
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
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
