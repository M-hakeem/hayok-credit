<?php

namespace App\Http\Controllers;

use App\Services\Paystack\PaystackWebhookService;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackWebhookService $service)
    {
        $rawPayload = $request->getContent();
        if (! $service->validSignature($rawPayload, $request->header('x-paystack-signature'))) {
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook signature.'], 401);
        }

        $service->handle($rawPayload, $request->json()->all());

        return response()->json(['status' => 'success']);
    }
}