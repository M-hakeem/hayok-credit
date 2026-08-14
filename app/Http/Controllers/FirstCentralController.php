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

    public function consumerMatch(Request $request)
    {
        $data = $request->validate([
            'EnquiryReason' => ['required', 'string', 'max:255'],
            'ConsumerName' => ['required', 'string', 'max:255'],
            'DateOfBirth' => ['required', 'date'],
            'Identification' => ['required', 'string', 'max:255'],
            'Accountno' => ['required', 'string', 'max:255'],
            'ProductID' => ['required', 'numeric'],
        ]);

        return $this->call(fn () => $this->firstCentral->consumerMatch($data));
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
