<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuarantorRequest;
use App\Http\Requests\UpdateGuarantorIdDocumentRequest;
use App\Models\Guarantor;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

class GuarantorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        $guarantors = $user->isAdmin()
            ? Guarantor::orderBy('guarantor_type')->get()
            : Guarantor::where('user_id', $user->id)->orderBy('guarantor_type')->get();

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

            $idFilePath = null;
            if ($request->hasFile('id_file')) {
                $file = $request->file('id_file');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                $idFilePath = $file->storeAs('guarantor_ids', $filename, 'public');
            }

            $guarantor = Guarantor::create([
                'user_id' => $user->id,
                'guarantor_type' => $request->guarantor_type,
                'relationship' => $request->relationship,
                'name' => $request->name,
                'phone_number' => $request->phone_number,
                'id_type' => $request->id_type,
                'id_file_path' => $idFilePath,
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
        try {
            $user = auth()->user();
            $guarantor = $user->isAdmin()
                ? Guarantor::findOrFail($id)
                : Guarantor::where('user_id', $user->id)->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $guarantor
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guarantor not found'
            ], 404);
        }
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
            $guarantor = $user->isAdmin()
                ? Guarantor::findOrFail($id)
                : Guarantor::where('user_id', $user->id)->findOrFail($id);

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

            $updateData = [
                'guarantor_type' => $request->guarantor_type,
                'relationship' => $request->relationship,
                'name' => $request->name,
                'phone_number' => $request->phone_number,
                'id_type' => $request->id_type,
            ];

            if ($request->hasFile('id_file')) {
                if ($guarantor->id_file_path && Storage::disk('public')->exists($guarantor->id_file_path)) {
                    Storage::disk('public')->delete($guarantor->id_file_path);
                }
                $file = $request->file('id_file');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                $updateData['id_file_path'] = $file->storeAs('guarantor_ids', $filename, 'public');
            }

            $guarantor->update($updateData);

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
     * @requestMediaType multipart/form-data
     */
    #[BodyParameter('id_type', type: 'string', required: true, description: 'NIN, BVN, Drivers License, International Passport, or Voters Card')]
    #[BodyParameter('id_file', type: 'string', format: 'binary', required: true, description: 'ID document file (PDF, JPG, JPEG, PNG — max 5MB)')]
    public function uploadIdDocument(UpdateGuarantorIdDocumentRequest $request, string $id)
    {
        try {
            $user      = auth()->user();
            $guarantor = $user->isAdmin()
                ? Guarantor::findOrFail($id)
                : Guarantor::where('user_id', $user->id)->findOrFail($id);

            if ($guarantor->id_file_path && Storage::disk('public')->exists($guarantor->id_file_path)) {
                Storage::disk('public')->delete($guarantor->id_file_path);
            }

            $file     = $request->file('id_file');
            $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
            $path     = $file->storeAs('guarantor_ids', $filename, 'public');

            $guarantor->update([
                'id_type'      => $request->id_type,
                'id_file_path' => $path,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'ID document uploaded successfully.',
                'data'    => $guarantor,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to upload ID document.',
                'error'   => $e->getMessage(),
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
            $guarantor = $user->isAdmin()
                ? Guarantor::findOrFail($id)
                : Guarantor::where('user_id', $user->id)->findOrFail($id);
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
