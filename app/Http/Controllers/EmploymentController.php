<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmploymentRequest;
use App\Models\Employment;
use Illuminate\Support\Facades\Storage;

class EmploymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $employments = Employment::where('user_id', $user->id)->get();

        return response()->json([
            'status' => 'success',
            'data' => $employments
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
    public function store(StoreEmploymentRequest $request)
    {
        try {
            $user = auth()->user();

            // Store the bank statement file
            $bankStatementPath = null;
            if ($request->hasFile('bank_statement')) {
                $file = $request->file('bank_statement');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                $bankStatementPath = $file->storeAs('bank_statements', $filename, 'public');
            }

            // Create the employment record
            $employment = Employment::create([
                'user_id' => $user->id,
                'employment_information' => $request->employment_information,
                'occupation' => $request->occupation,
                'educational_details' => $request->educational_details,
                'income' => $request->income,
                'bank_statement_path' => $bankStatementPath,
                'verification_status' => 'pending',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employment information submitted successfully. Your bank statement is under review.',
                'data' => [
                    'id' => $employment->id,
                    'user_id' => $employment->user_id,
                    'occupation' => $employment->occupation,
                    'income' => $employment->income,
                    'verification_status' => $employment->verification_status,
                    'created_at' => $employment->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit employment information',
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
        $employment = Employment::where('user_id', $user->id)->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $employment
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
    public function update(StoreEmploymentRequest $request, string $id)
    {
        try {
            $user = auth()->user();
            $employment = Employment::where('user_id', $user->id)->findOrFail($id);

            // Handle file upload if provided
            if ($request->hasFile('bank_statement')) {
                // Delete old file if exists
                if ($employment->bank_statement_path && Storage::disk('public')->exists($employment->bank_statement_path)) {
                    Storage::disk('public')->delete($employment->bank_statement_path);
                }

                $file = $request->file('bank_statement');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                $bankStatementPath = $file->storeAs('bank_statements', $filename, 'public');
                $employment->bank_statement_path = $bankStatementPath;
                $employment->verification_status = 'pending';
            }

            $employment->update([
                'employment_information' => $request->employment_information,
                'occupation' => $request->occupation,
                'educational_details' => $request->educational_details,
                'income' => $request->income,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employment information updated successfully',
                'data' => $employment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update employment information',
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
        $employment = Employment::where('user_id', $user->id)->findOrFail($id);
        $employment->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Employment information deleted successfully'
        ]);
    }
}
