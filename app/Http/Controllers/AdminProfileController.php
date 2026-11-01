<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->only(['id', 'fullname', 'email', 'phone_number']),
        ]);
    }

    public function update(Request $request)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'fullname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'phone_number' => ['required', 'string', 'max:20', Rule::unique('users', 'phone_number')->ignore($admin->id)],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
        ]);

        $admin->fullname = $validated['fullname'];
        $admin->email = $validated['email'];
        $admin->phone_number = $validated['phone_number'];

        if (isset($validated['password'])) {
            $admin->password = $validated['password'];
        }

        $admin->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'data' => $admin->fresh()->only(['id', 'fullname', 'email', 'phone_number']),
        ]);
    }
}
