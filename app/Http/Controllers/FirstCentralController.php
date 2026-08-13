<?php

namespace App\Http\Controllers;

use App\Services\FirstCentralService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

class FirstCentralController extends Controller
{
    public function __construct(private readonly FirstCentralService $firstCentral)
    {
    }

    public function banks()
    {
        return $this->call(fn () => $this->firstCentral->banks());
    }

    public function resolveAccount(Request $request)
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:32'],
            'account_number' => ['required', 'digits:10'],
        ]);

        return $this->call(fn () => $this->firstCentral->resolveAccount($data['bank_code'], $data['account_number']));
    }

    public function transfer(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'bank_code' => ['required', 'string', 'max:32'],
            'account_number' => ['required', 'digits:10'],
            'narration' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->call(fn () => $this->firstCentral->transfer($data));
    }

    private function call(callable $callback, int $status = 200)
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => $callback(),
            ], $status);
        } catch (RequestException $exception) {
            return $this->upstreamError($exception);
        }
    }

    private function upstreamError(RequestException $exception)
    {
        $response = $exception->response;

        return response()->json([
            'status' => 'error',
            'message' => data_get($response->json(), 'message', 'First Central could not process this request.'),
            'data' => $response->json(),
        ], $response->status() >= 400 && $response->status() < 500 ? $response->status() : 502);
    }
}
