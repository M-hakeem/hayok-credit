<?php

namespace App\Http\Controllers;

use App\Models\PaymentAuthorization;
use App\Services\Paystack\PaymentAuthorizationService;
use Illuminate\Http\Request;
use RuntimeException;

class PaystackPaymentController extends Controller
{
    public function initializeCard(PaymentAuthorizationService $service)
    {
        try {
            $data = $service->initialize(auth()->user());
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function verifyCard(Request $request, PaymentAuthorizationService $service, string $reference)
    {
        try {
            $authorization = $service->verifyAndStore(auth()->user(), $reference);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'data' => $authorization]);
    }

    public function authorizations()
    {
        return response()->json([
            'status' => 'success',
            'data' => auth()->user()->paymentAuthorizations()->where('status', 'active')->get()->makeVisible([]),
        ]);
    }

    public function revoke(PaymentAuthorization $paymentAuthorization, PaymentAuthorizationService $service)
    {
        abort_unless($paymentAuthorization->user_id === auth()->id(), 404);
        $service->deactivate($paymentAuthorization);

        return response()->json(['status' => 'success', 'message' => 'Payment authorization revoked.']);
    }
}