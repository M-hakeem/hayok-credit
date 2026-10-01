<?php

namespace App\Http\Controllers;

use App\Services\TermiiOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermiiDeliveryWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, TermiiOtpService $termiiOtpService): JsonResponse
    {
        $accepted = $termiiOtpService->handleDeliveryStatus($token, $request->all());

        return response()->json(['status' => $accepted ? 'success' : 'error'], $accepted ? 200 : 401);
    }
}
