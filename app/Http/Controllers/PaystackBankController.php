<?php

namespace App\Http\Controllers;

use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Request;
use RuntimeException;

class PaystackBankController extends Controller
{
    public function index(PaystackClient $client)
    {
        try {
            return response()->json(['status' => 'success', 'data' => $client->listBanks()]);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 502);
        }
    }

    public function store(Request $request, PaystackClient $client)
    {
        $data = $request->validate([
            'bank_code' => 'required|string|max:32',
            'bank_account_number' => 'required|string|size:10',
        ]);

        try {
            $resolved = $client->resolveAccountNumber($data['bank_account_number'], $data['bank_code']);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        $user = $request->user();
        $user->update([
            'bank_code' => $data['bank_code'],
            'bank_account_number' => $data['bank_account_number'],
            'bank_account_name' => $resolved['account_name'] ?? null,
            'bank_name' => $resolved['bank_name'] ?? $user->bank_name ?? 'Bank '.$data['bank_code'],
            'bank_connected_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Bank account validated successfully.',
            'data' => $this->masked($user->fresh()),
        ]);
    }

    public function show(Request $request)
    {
        return response()->json(['status' => 'success', 'data' => $this->masked($request->user())]);
    }

    private function masked($user): array
    {
        $account = (string) $user->bank_account_number;

        return [
            'bank_name' => $user->bank_name,
            'bank_code' => $user->bank_code,
            'bank_account_name' => $user->bank_account_name,
            'bank_account_number' => $account ? str_repeat('*', max(0, strlen($account) - 4)).substr($account, -4) : null,
            'bank_connected_at' => $user->bank_connected_at,
        ];
    }
}