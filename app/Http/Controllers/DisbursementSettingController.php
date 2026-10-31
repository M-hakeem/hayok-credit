<?php

namespace App\Http\Controllers;

use App\Models\DisbursementSetting;
use Illuminate\Http\Request;

class DisbursementSettingController extends Controller
{
    public function show()
    {
        $accountNumber = DisbursementSetting::query()->find(1)?->debit_account_number;

        return response()->json([
            'status' => 'success',
            'data' => [
                'configured' => $accountNumber !== null,
                'masked_debit_account_number' => $accountNumber === null
                    ? null
                    : str_repeat('*', max(0, strlen($accountNumber) - 4)).substr($accountNumber, -4),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'debit_account_number' => ['required', 'digits:10'],
        ]);

        $setting = DisbursementSetting::query()->find(1) ?? new DisbursementSetting(['id' => 1]);
        $setting->debit_account_number = $validated['debit_account_number'];
        $setting->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Disbursement debit account updated successfully.',
            'data' => [
                'configured' => true,
                'masked_debit_account_number' => str_repeat('*', 6).substr($validated['debit_account_number'], -4),
            ],
        ]);
    }
}
