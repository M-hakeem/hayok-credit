<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAddressRequest;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AddressController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $addresses = Address::where('user_id', $user->id)->get();

        return response()->json([
            'status' => 'success',
            'data' => $addresses
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
    public function store(StoreAddressRequest $request)
    {
        try {
            $user = auth()->user();

            // Store the utility bill file
            $utilityBillPath = null;
            if ($request->hasFile('utility_bill')) {
                $file = $request->file('utility_bill');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                $utilityBillPath = $file->storeAs('utility_bills', $filename, 'public');
            }

            // Create the address record
            $address = Address::create([
                'user_id' => $user->id,
                'residential_address' => $request->residential_address,
                'state' => $request->state,
                'lga' => $request->lga,
                'utility_bill_path' => $utilityBillPath,
                'verification_status' => 'pending',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Address added successfully. Your utility bill is under review.',
                'data' => [
                    'id' => $address->id,
                    'user_id' => $address->user_id,
                    'residential_address' => $address->residential_address,
                    'state' => $address->state,
                    'lga' => $address->lga,
                    'verification_status' => $address->verification_status,
                    'created_at' => $address->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add address',
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
        $address = Address::where('user_id', $user->id)->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $address
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
    public function update(StoreAddressRequest $request, string $id)
    {
        try {
            $user = auth()->user();
            $address = Address::where('user_id', $user->id)->findOrFail($id);

            // Handle file upload if provided
            if ($request->hasFile('utility_bill')) {
                // Delete old file if exists
                if ($address->utility_bill_path && Storage::disk('public')->exists($address->utility_bill_path)) {
                    Storage::disk('public')->delete($address->utility_bill_path);
                }

                $file = $request->file('utility_bill');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                $utilityBillPath = $file->storeAs('utility_bills', $filename, 'public');
                $address->utility_bill_path = $utilityBillPath;
                $address->verification_status = 'pending';
            }

            $address->update([
                'residential_address' => $request->residential_address,
                'state' => $request->state,
                'lga' => $request->lga,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Address updated successfully',
                'data' => $address
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update address',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = auth()->user();
        $address = Address::where('user_id', $user->id)->findOrFail($id);
        $address->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Address deleted successfully'
        ]);
    }
}
