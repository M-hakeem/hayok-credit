<?php

namespace App\Http\Controllers;

use App\Services\ClaimifyWalletService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClaimifyWalletController extends Controller
{
    public function __construct(private readonly ClaimifyWalletService $claimify)
    {
    }

    public function banks()
    {
        return $this->call(fn () => $this->claimify->banks());
    }

    public function nameInquiry(Request $request)
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:32'],
            'account_number' => ['required', 'digits:10'],
        ]);

        return $this->call(fn () => $this->claimify->nameInquiry($data['bank_code'], $data['account_number']));
    }

    public function initiateVerification(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:NIN,BVN'],
            'number' => ['required', 'digits:11'],
        ]);

        return $this->call(fn () => $this->claimify->initiateVerification($data['type'], $data['number']));
    }

    public function validateVerification(Request $request)
    {
        $data = $request->validate([
            'identity_id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:NIN,BVN'],
            'otp' => ['required', 'digits:6'],
        ]);

        try {
            $response = $this->claimify->validateVerification($data['identity_id'], $data['type'], $data['otp']);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }

        if ($this->isFailedVerification($response)) {
            return response()->json([
                'status' => 'error',
                'message' => data_get($response, 'message', 'Identity verification failed.'),
                'data' => $response,
            ], 422);
        }

        Cache::put($this->verifiedIdentityKey($request), [
            'identity_id' => $data['identity_id'],
            'type' => $data['type'],
        ], now()->addMinutes(30));

        return response()->json(['status' => 'success', 'data' => $response]);
    }

    public function createCustomer(Request $request)
    {
        $user = $request->user();
        $identity = Cache::get($this->verifiedIdentityKey($request));

        if (! $identity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Verify your NIN or BVN before creating a wallet.',
            ], 422);
        }

        $number = $identity['type'] === 'NIN' ? $user->nin : $user->bvn;

        if (! $number) {
            return response()->json([
                'status' => 'error',
                'message' => "Your {$identity['type']} is not saved on your profile.",
            ], 422);
        }

        if (! $user->email || ! $user->phone_number) {
            return response()->json([
                'status' => 'error',
                'message' => 'An email address and phone number are required to create a wallet.',
            ], 422);
        }

        $payload = [
            'identityId' => $identity['identity_id'],
            // Claimify's create-customer endpoint identifies the validated record as vID.
            'identityType' => 'vID',
            'identityNumber' => $number,
            'reference' => 'HC_'.Str::upper(Str::random(16)),
            'email' => $user->email,
            'phone_number' => $user->phone_number,
        ];

        try {
            $response = $this->claimify->createCustomer($payload);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }

        $user->wallet()->firstOrCreate([], [
            'balance' => 0,
            'currency' => 'NGN',
            'status' => 'active',
        ]);

        return response()->json(['status' => 'success', 'data' => $response], 201);
    }

    private function call(callable $callback, int $status = 200)
    {
        try {
            return response()->json(['status' => 'success', 'data' => $callback()], $status);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }
    }

    private function upstreamError(RequestException $exception)
    {
        $response = $exception->response;

        return response()->json([
            'status' => 'error',
            'message' => data_get($response->json(), 'message', 'Claimify could not process this request.'),
            'data' => $response->json(),
        ], $response->status() >= 400 && $response->status() < 500 ? $response->status() : 502);
    }

    private function verifiedIdentityKey(Request $request): string
    {
        return 'claimify:verified-identity:'.$request->user()->id;
    }

    private function isFailedVerification(array $response): bool
    {
        return data_get($response, 'success') === false
            || data_get($response, 'status') === false
            || data_get($response, 'requestSuccessful') === false
            || in_array(strtolower((string) data_get($response, 'status')), ['error', 'failed', 'failure'], true);
    }
}
