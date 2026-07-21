<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::where('role', '!=', 'admin')->get();

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ], 200);
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
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User retrieved successfully',
            'data' => $user,
        ], 200);
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
    public function update(UpdateProfileRequest $request)
    {
        $user = auth()->user();

        $user->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['active', 'inactive', 'suspended']),
            ],
            'kyc_status' => [
                'required',
                Rule::in(['pending', 'verified', 'rejected']),
            ],
        ]);

        $user = User::findOrFail($id);

        $user->update([
            'status' => $validated['status'],
            'kyc_status' => $validated['kyc_status'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User status updated successfully.',
            'user' => $user,
        ]);
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'User deleted successfully',
            'user' => $user
        ]);
    }
}
