<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Services\Paystack\WalletDepositService;
use App\Services\Paystack\WalletWithdrawalService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\Request;
use RuntimeException;

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

    #[BodyParameter('amount', type: 'number', required: true, description: 'Amount to deposit in NGN (min 0.01)')]
    #[BodyParameter('description', type: 'string', required: false, description: 'Deposit description (max 255 chars)')]
    public function deposit(Request $request, WalletDepositService $service)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|decimal:0,2|min:0.01|max:9999999999.99',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $deposit = $service->initialize(auth()->user(), $data['amount'], $data['description'] ?? null);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Complete payment using the Paystack checkout link.',
            'data' => $deposit,
        ]);
    }

    public function verifyDeposit(WalletDepositService $service, string $reference)
    {
        try {
            $deposit = $service->verifyAndCredit(auth()->user(), $reference);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet funded successfully.',
            'data' => $deposit,
        ]);
    }

    #[BodyParameter('amount', type: 'number', required: true, description: 'Amount to withdraw in NGN (min 1)')]
    #[BodyParameter('description', type: 'string', required: false, description: 'Withdrawal description (max 255 chars)')]
    public function withdraw(Request $request, WalletWithdrawalService $service)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|decimal:0,2|min:1|max:9999999999.99',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $withdrawal = $service->requestWithdrawal(auth()->user(), $data['amount'], $data['description'] ?? null);
        } catch (\RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $withdrawal['message'],
            'data' => $withdrawal['data'],
        ], $withdrawal['http_status']);
    }

    public function verifyWithdrawal(WalletWithdrawalService $service, string $reference)
    {
        try {
            $withdrawal = $service->verifyWithdrawal(auth()->user(), $reference);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $withdrawal['message'],
            'data' => $withdrawal['data'],
        ], $withdrawal['http_status']);
    }

    public function transactions(Request $request)
    {
        $request->validate([
            'type' => 'nullable|in:credit,debit',
            'limit' => 'nullable|integer|min:1|max:100',
            'offset' => 'nullable|integer|min:0',
        ]);

        $user = auth()->user();
        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found. Verify your NIN or BVN and create a wallet first.',
            ], 404);
        }

        $query = $wallet->transactions();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $limit = (int) ($request->limit ?? 20);
        $offset = (int) ($request->offset ?? 0);

        $transactions = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $total = $wallet->transactions()->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'transactions' => $transactions,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
            ],
        ]);
    }

    public function withdrawals(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:pending,processing,completed,failed,cancelled',
            'limit' => 'nullable|integer|min:1|max:100',
            'offset' => 'nullable|integer|min:0',
        ]);

        $user = auth()->user();

        $query = Withdrawal::where('user_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $limit = (int) ($request->limit ?? 20);
        $offset = (int) ($request->offset ?? 0);

        $withdrawals = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $total = Withdrawal::where('user_id', $user->id)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'withdrawals' => $withdrawals,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
            ],
        ]);
    }

    public function paymentMethods()
    {
        $user = auth()->user();
        $wallet = $user->wallet;

        $methods = [];

        // Add wallet if it exists
        if ($wallet) {
            $methods[] = [
                'type' => 'wallet',
                'balance' => $wallet->balance,
                'status' => $wallet->status,
                'currency' => $wallet->currency ?? 'NGN',
                'available' => (float) $wallet->balance > 0,
            ];
        }

        // Add saved card authorizations
        $cards = $user->paymentAuthorizations()
            ->where('status', 'active')
            ->where('reusable', true)
            ->get()
            ->map(function ($auth) {
                return [
                    'type' => 'card',
                    'id' => $auth->id,
                    'brand' => $auth->brand,
                    'last4' => $auth->last4,
                    'exp_month' => $auth->exp_month,
                    'exp_year' => $auth->exp_year,
                    'status' => $auth->status,
                    'available' => $auth->isUsable(),
                ];
            });

        $methods = array_merge($methods, $cards->all());

        return response()->json([
            'status' => 'success',
            'data' => [
                'payment_methods' => $methods,
                'has_wallet' => (bool) $wallet,
                'has_card' => $cards->count() > 0,
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
}
