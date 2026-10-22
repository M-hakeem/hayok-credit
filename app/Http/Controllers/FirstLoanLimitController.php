<?php

namespace App\Http\Controllers;

use App\Models\FirstLoanLimit;
use Illuminate\Http\Request;

class FirstLoanLimitController extends Controller
{
    public function show()
    {
        $setting = FirstLoanLimit::query()->find(1);

        return response()->json([
            'status' => 'success',
            'data' => [
                'amount' => $setting?->amount,
                'configured' => $setting?->amount !== null,
                'currency' => 'NGN',
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:100|max:9999999.99',
        ]);

        $setting = FirstLoanLimit::query()->find(1) ?? new FirstLoanLimit(['id' => 1]);
        $setting->amount = $validated['amount'];
        $setting->save();

        return response()->json([
            'status' => 'success',
            'message' => 'First-loan request limit updated successfully.',
            'data' => [
                'amount' => $setting->amount,
                'configured' => true,
                'currency' => 'NGN',
            ],
        ]);
    }

    public function eligibility()
    {
        $user = auth()->user();
        $isFirstTimeUser = ! $user->loans()->withTrashed()->exists();
        $setting = FirstLoanLimit::query()->find(1);

        return response()->json([
            'status' => 'success',
            'data' => [
                'is_first_time_user' => $isFirstTimeUser,
                'maximum_amount' => $isFirstTimeUser ? $setting?->amount : null,
                'configured' => $setting?->amount !== null,
                'currency' => 'NGN',
            ],
        ]);
    }
}
