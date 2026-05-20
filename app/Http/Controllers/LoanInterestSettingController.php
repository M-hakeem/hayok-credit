<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanInterestSettingRequest;
use App\Models\LoanInterestSetting;

class LoanInterestSettingController extends Controller
{
    public function index()
    {
        $settings = LoanInterestSetting::orderBy('updated_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    public function store(StoreLoanInterestSettingRequest $request)
    {
        if ($request->boolean('active')) {
            // Only deactivate settings for the same tenure so multi-tenure rates coexist
            LoanInterestSetting::where('active', true)
                ->where('tenure_months', $request->input('tenure_months'))
                ->update(['active' => false]);
        }

        $setting = LoanInterestSetting::create([
            'interest_rate' => $request->interest_rate,
            'tenure_months' => $request->input('tenure_months'),
            'active' => $request->boolean('active', true),
            'notes' => $request->notes,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Loan interest rate saved successfully.',
            'data' => $setting,
        ], 201);
    }
}
