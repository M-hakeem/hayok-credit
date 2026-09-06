<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show()
    {
        $user = auth()->user();
        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found. Verify your NIN or BVN and create a wallet first.',
            ], 404);
        }

        $wallet->load('transactions');

        return response()->json([
            'status' => 'success',
            'data' => [
                'wallet' => $wallet,
                'bank_details' => [
                    'bank_name' => $user->bank_name,
                    'bank_account_number' => $user->bank_account_number ? '********'.substr($user->bank_account_number, -4) : null,
                    'bank_account_name' => $user->bank_account_name,
                    'bank_code' => $user->bank_code,
                    'bank_connected_at' => $user->bank_connected_at,
                ],
            ],
        ]);
    }

    #[BodyParameter('bank_name', type: 'string', required: true, description: 'Bank name (max 255 chars)')]
    #[BodyParameter('bank_account_number', type: 'string', required: true, description: 'Bank account number (max 64 chars)')]
    #[BodyParameter('bank_account_name', type: 'string', required: true, description: 'Account holder name as on the bank record (max 255 chars)')]
    #[BodyParameter('bank_code', type: 'string', required: false, description: 'Bank sort/NIBSS code (max 32 chars)')]
    public function updateBankDetails(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'bank_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:64',
            'bank_account_name' => 'required|string|max:255',
            'bank_code' => 'nullable|string|max:32',
        ]);

        $data['bank_connected_at'] = now();
        $user->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Bank details updated successfully.',
            'data' => $user->only([
                'bank_name',
                'bank_account_number',
                'bank_account_name',
                'bank_code',
                'bank_connected_at',
            ]),
        ]);
    }

    #[BodyParameter('amount', type: 'number', required: true, description: 'Amount to deposit (min 0.01)')]
    #[BodyParameter('reference', type: 'string', required: false, description: 'Payment reference (max 255 chars)')]
    #[BodyParameter('description', type: 'string', required: false, description: 'Deposit description (max 255 chars)')]
    public function deposit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $user = auth()->user();
        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found. Verify your NIN or BVN and create a wallet first.',
            ], 404);
        }

        $transaction = $wallet->credit(
            (float) $request->amount,
            $request->description ?? 'Wallet deposit',
            $request->reference,
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet funded successfully.',
            'data' => [
                'wallet' => $wallet->refresh(),
                'transaction' => $transaction,
            ],
        ]);
    }

    #[BodyParameter('amount', type: 'number', required: true, description: 'Amount to withdraw (min 1)')]
    #[BodyParameter('reference', type: 'string', required: false, description: 'Withdrawal reference (max 255 chars)')]
    #[BodyParameter('description', type: 'string', required: false, description: 'Withdrawal description (max 255 chars)')]
    public function withdraw(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $user = auth()->user();

        if (! $user->bank_name || ! $user->bank_account_number || ! $user->bank_account_name) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please connect your bank account before withdrawing funds.',
            ], 422);
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found. Verify your NIN or BVN and create a wallet first.',
            ], 404);
        }

        try {
            $transaction = $wallet->debit(
                (float) $request->amount,
                $request->description ?? 'Wallet withdrawal',
                $request->reference,
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => $request->amount,
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->bank_account_number,
            'bank_account_name' => $user->bank_account_name,
            'bank_code' => $user->bank_code,
            'status' => 'pending',
            'transaction_reference' => $request->reference,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Withdrawal request created successfully. The amount has been reserved.',
            'data' => [
                'wallet' => $wallet,
                'withdrawal' => $withdrawal,
                'transaction' => $transaction,
            ],
        ]);
    }
}
