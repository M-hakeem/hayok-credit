<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::where('role', '!=', 'admin')->orderBy('created_at', 'DESC')->get();
        $users = $users->map(function (User $user) {
            $data = $user->toArray();
            $data['bank_account_number'] = $user->bank_account_number;

            return $data;
        });

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
     * Display a user's wallet for administrators.
     */
    public function wallet(string $id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found for this user.',
            ], 404);
        }

        $wallet->load('transactions');

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user->only(['id', 'fullname', 'email', 'phone_number']),
                'wallet' => $wallet,
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    #[Response(type: 'array{status: string, message: string, data: User, user: User, hint: array{address_management: string, employment_info: string, guarantor_info: string, nin_verification: string}}')]
    public function update(UpdateProfileRequest $request)
    {
        $user = auth()->user();
        $data = $request->validated();

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $file = $request->file('profile_image');
            $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
            $data['profile_image'] = $file->storeAs('profile_images', $filename, 'public');
        }

        $user->update($data);
        $user = $user->fresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            // Keep `data` for the established API envelope and expose `user`
            // for clients that consume profile-specific responses.
            'data' => $user,
            'user' => $user,
            'hint' => [
                'address_management' => 'Use POST/PUT /api/address to manage residential addresses',
                'employment_info' => 'Use POST/PUT /api/employment to manage employment details',
                'guarantor_info' => 'Use POST/PUT /api/guarantor to add/manage guarantors',
                'nin_verification' => 'NIN/BVN verification is handled through wallet KYC endpoints',
            ]
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
            'account_level' => [
                'required',
                Rule::in(['tier_1', 'tier_2', 'tier_3']),
            ],
        ]);

        $user = User::findOrFail($id);

        $user->update([
            'status' => $validated['status'],
            'kyc_status' => $validated['kyc_status'],
            'account_level' => $validated['account_level'],
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

    /**
     * Get profile management endpoints guide
     *
     * This endpoint returns information about all available profile management endpoints
     * to help clients understand which endpoint to use for different profile updates.
     */
    public function profileManagementGuide()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Profile management endpoints guide',
            'data' => [
                'basic_profile' => [
                    'endpoint' => 'POST /api/user/update-profile',
                    'description' => 'Update basic profile information. Use multipart/form-data and POST when uploading profile_image; PUT is supported for JSON-only edits.',
                    'fields' => [
                        'fullname' => 'Full name (string, max 255)',
                        'dob' => 'Date of birth (date)',
                        'gender' => 'Gender (male, female, other)',
                        'email' => 'Email address (email, unique)',
                        'phone_number' => 'Phone number (string, 10-20 chars)',
                        'profile_image' => 'Profile picture (image, jpg/jpeg/png/webp, max 2MB)',
                    ],
                    'example' => [
                        'fullname' => 'John Doe',
                        'email' => 'john@example.com',
                        'phone_number' => '08012345678',
                    ]
                ],
                'address_management' => [
                    'endpoints' => [
                        'GET /api/address' => 'Get all addresses',
                        'POST /api/address' => 'Add new address',
                        'PUT /api/address/{id}' => 'Update address',
                        'DELETE /api/address/{id}' => 'Delete address',
                    ],
                    'description' => 'Manage residential addresses with verification status',
                    'fields' => [
                        'residential_address' => 'Full address (string)',
                        'state' => 'State of residence (string)',
                        'lga' => 'Local Government Area (string)',
                        'utility_bill' => 'Utility bill document (file)',
                    ],
                    'verification_status' => 'pending|verified|rejected'
                ],
                'employment_management' => [
                    'endpoints' => [
                        'GET /api/employment' => 'Get all employment records',
                        'POST /api/employment' => 'Add employment information',
                        'PUT /api/employment/{id}' => 'Update employment',
                        'DELETE /api/employment/{id}' => 'Delete employment',
                    ],
                    'description' => 'Manage employment and income details',
                    'fields' => [
                        'employment_information' => 'Employer details',
                        'occupation' => 'Job title/occupation',
                        'educational_details' => 'Education background',
                        'income' => 'Monthly/annual income',
                        'bank_statement' => 'Bank statement document (file)',
                    ],
                    'verification_status' => 'pending|verified|rejected'
                ],
                'guarantor_management' => [
                    'endpoints' => [
                        'GET /api/guarantor' => 'Get all guarantors',
                        'POST /api/guarantor' => 'Add guarantor',
                        'PUT /api/guarantor/{id}' => 'Update guarantor',
                        'DELETE /api/guarantor/{id}' => 'Delete guarantor',
                        'POST /api/guarantor/{id}/id-document' => 'Upload guarantor ID document',
                    ],
                    'description' => 'Manage personal and professional guarantors',
                    'fields' => [
                        'guarantor_type' => 'personal|professional',
                        'relationship' => 'Relationship to applicant',
                        'name' => 'Guarantor name',
                        'phone_number' => 'Guarantor phone',
                        'id_type' => 'Type of ID',
                        'id_file' => 'ID document (file)',
                    ]
                ],
                'nin_bvn_verification' => [
                    'endpoints' => [
                        'POST /api/wallet/first-central/consumer-match' => 'Verify NIN/BVN via First Central',
                        'POST /api/wallet/verification/initiate' => 'Initiate wallet verification',
                        'POST /api/wallet/verification/validate' => 'Validate wallet verification',
                    ],
                    'description' => 'Verify NIN and BVN for KYC compliance',
                    'note' => 'NIN and BVN are verified through wallet KYC endpoints, not through profile update'
                ],
            ]
        ]);
    }
}
