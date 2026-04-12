<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuarantorRequest;
use App\Models\Guarantor;

class GuarantorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $guarantors = Guarantor::where('user_id', $user->id)
            ->orderBy('guarantor_type')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $guarantors
        ]);
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
    public function store(StoreGuarantorRequest $request)
    {
        try {
            $user = auth()->user();

            // Check if guarantor of this type already exists
            $existingGuarantor = Guarantor::where('user_id', $user->id)
                ->where('guarantor_type', $request->guarantor_type)
                ->first();

            if ($existingGuarantor) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A ' . $request->guarantor_type . ' guarantor already exists. Please update instead of creating a new one.'
                ], 422);
            }

            // Create the guarantor record
            $guarantor = Guarantor::create([
                'user_id' => $user->id,
                'guarantor_type' => $request->guarantor_type,
                'relationship' => $request->relationship,
                'name' => $request->name,
                'phone_number' => $request->phone_number,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $request->guarantor_type . ' guarantor added successfully.',
                'data' => $guarantor
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add guarantor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = auth()->user();
        $guarantor = Guarantor::where('user_id', $user->id)->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $guarantor
        ]);
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
    public function update(StoreGuarantorRequest $request, string $id)
    {
        try {
            $user = auth()->user();
            $guarantor = Guarantor::where('user_id', $user->id)->findOrFail($id);

            // Check if trying to change type to one that already exists
            if ($request->guarantor_type !== $guarantor->guarantor_type) {
                $existingGuarantor = Guarantor::where('user_id', $user->id)
                    ->where('guarantor_type', $request->guarantor_type)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingGuarantor) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'A ' . $request->guarantor_type . ' guarantor already exists.'
                    ], 422);
                }
            }

            $guarantor->update([
                'guarantor_type' => $request->guarantor_type,
                'relationship' => $request->relationship,
                'name' => $request->name,
                'phone_number' => $request->phone_number,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantor information updated successfully',
                'data' => $guarantor
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update guarantor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $user = auth()->user();
            $guarantor = Guarantor::where('user_id', $user->id)->findOrFail($id);
            $guarantor->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantor deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete guarantor',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
