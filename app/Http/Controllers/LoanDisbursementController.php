<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\RepaymentSchedule;
use App\Services\ClaimifyLoanDisbursementService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LoanDisbursementController extends Controller
{
    public function index()
    {
        $disbursements = LoanDisbursement::with(['loan.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        $disbursements->makeVisible('bank_account_number');

        return response()->json([
            'status' => 'success',
            'data' => $disbursements,
        ]);
    }

    public function disbursedLoanHistory(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $disbursements = LoanDisbursement::with(['loan', 'user'])
            ->where('status', 'disbursed')
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->user_id))
            ->orderBy('disbursed_at', 'desc')
            ->get();

        $disbursements->makeVisible('bank_account_number');

        $history = $disbursements->groupBy('user_id')->map(function ($userDisbursements) {
            $disbursement = $userDisbursements->first();

            return [
                'user' => $disbursement->user,
                'disbursement' => $disbursement,
                'loans' => $userDisbursements->pluck('loan')->values(),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $history,
        ]);
    }

    public function disburse(Request $request, $id, ClaimifyLoanDisbursementService $transferService)
    {
        $disbursement = LoanDisbursement::with('loan.user.organisation')
            ->where('loan_id', $id)
            ->first();

        if (! $disbursement) {
            $loan = \App\Models\Loan::with('user.organisation')->find($id);

            if (! $loan || $loan->status !== 'approved') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No disbursement record found. Ensure the loan is approved first.',
                ], 404);
            }

            $payoutAccount = $loan->payout_account ?? [];
            $disbursement = \App\Models\LoanDisbursement::firstOrCreate(
                ['loan_id' => $loan->id],
                [
                    'user_id'             => $loan->user_id,
                    'amount'              => $loan->amount_requested,
                    'bank_name'           => $payoutAccount['bank_name'] ?? $loan->user->bank_name ?? '',
                    'bank_account_number' => $payoutAccount['bank_account_number'] ?? $loan->user->bank_account_number ?? '',
                    'bank_account_name'   => $payoutAccount['bank_account_name'] ?? $loan->user->bank_account_name ?? '',
                    'bank_code'           => array_key_exists('bank_code', $payoutAccount)
                        ? $payoutAccount['bank_code']
                        : $loan->user->bank_code,
                    'status'              => 'pending',
                ]
            );

            $disbursement->load('loan.user.organisation');
        }

        $loan = $disbursement->loan;
        $canRetryInsufficientFunds = $loan->disbursement_method === 'bank'
            && $disbursement->status === 'failed'
            && $this->isInsufficientFundsFailure($disbursement->failure_reason);

        if ($disbursement->status !== 'pending' && ! $canRetryInsufficientFunds) {
            return response()->json([
                'status' => 'error',
                'message' => 'This disbursement has already been processed.',
            ], 422);
        }

        if ($loan->status !== 'approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan must be approved before it can be disbursed.',
            ], 422);
        }

        if ($canRetryInsufficientFunds) {
            $disbursement->update([
                'status' => 'pending',
                'failure_reason' => null,
                'provider_reference' => null,
                'transfer_code' => null,
            ]);
            $disbursement->refresh();
        }

        if ($loan->disbursement_method === 'bank') {
            try {
                $transfer = $transferService->disburse($disbursement);
            } catch (\RuntimeException $exception) {
                $response = [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];
                if ($disbursement->fresh()->status === 'failed') {
                    $response['data'] = ['disbursement' => $disbursement->fresh()];
                }

                return response()->json($response, 422);
            } catch (RequestException $exception) {
                $response = $exception->response;
                $message = data_get($response->json(), 'message', 'Claimify could not process the bank transfer.');
                if ($response->clientError()) {
                    $disbursement->update([
                        'status' => 'failed',
                        'failure_reason' => $message,
                        'gateway_response' => $response->json(),
                    ]);
                }

                $errorResponse = [
                    'status' => 'error',
                    'message' => $message,
                ];
                if ($response->clientError()) {
                    $errorResponse['data'] = ['disbursement' => $disbursement->fresh()];
                }

                return response()->json($errorResponse, $response->clientError() ? $response->status() : 502);
            } catch (ConnectionException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Could not confirm the Claimify bank transfer. Check the disbursement status before retrying.',
                ], 502);
            }

            $this->completeDisbursement($disbursement, $loan, false);
            $this->notifyInsucare($disbursement, $loan);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank transfer completed successfully.',
                'data' => [
                    'disbursement' => $disbursement->fresh(),
                    'transfer' => $transfer,
                ],
            ]);
        }

        $this->completeDisbursement($disbursement, $loan, true);

        $this->notifyInsucare($disbursement, $loan);

        return response()->json([
            'status' => 'success',
            'message' => 'Loan disbursed to the user wallet.',
            'data' => $disbursement,
        ]);
    }

    private function completeDisbursement(LoanDisbursement $disbursement, Loan $loan, bool $creditWallet): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($disbursement, $loan, $creditWallet) {
            $disbursedAt = now();
            $disbursement->update(['status' => 'disbursed', 'disbursed_at' => $disbursedAt]);

            if ($creditWallet) {
                $disbursement->user->wallet()->firstOrCreate([], ['balance' => 0, 'currency' => 'NGN'])
                    ->credit((float) $disbursement->amount, 'Loan disbursement', 'loan-disbursement-'.$disbursement->id);
            }

            if ($loan->repaymentSchedules()->count() === 0) {
                $term = (int) $loan->term_months;
                $principalPerInstallment = round($loan->amount_requested / $term, 2);
                $interestPerInstallment = round($loan->total_interest / $term, 2);

                for ($installment = 1; $installment <= $term; $installment++) {
                    $principal = $installment === $term ? round($loan->amount_requested - $principalPerInstallment * ($term - 1), 2) : $principalPerInstallment;
                    $interest = $installment === $term ? round($loan->total_interest - $interestPerInstallment * ($term - 1), 2) : $interestPerInstallment;
                    $totalDue = round($principal + $interest, 2);

                    RepaymentSchedule::create([
                        'loan_id' => $loan->id,
                        'installment_number' => $installment,
                        'due_date' => Carbon::parse($disbursedAt)->addMonths($installment)->toDateString(),
                        'principal_amount' => $principal,
                        'interest_amount' => $interest,
                        'penalty_amount' => 0,
                        'total_due' => $totalDue,
                        'amount_paid' => 0,
                        'balance_due' => $totalDue,
                        'status' => 'pending',
                    ]);
                }
            }

            $loan->update(['status' => 'active']);
        });
    }

    private function isInsufficientFundsFailure(?string $failureReason): bool
    {
        return is_string($failureReason)
            && preg_match('/(?:no\s+sufficient\s+funds|insufficient\s+funds)/i', $failureReason) === 1;
    }

    private function notifyInsucare(LoanDisbursement $disbursement, Loan $loan): void
    {
        $user         = $loan->user;
        $organisation = $user?->organisation;

        if (! $organisation) {
            Log::info('Insucare: skipped — loan user has no organisation', ['loan_id' => $loan->id]);
            return;
        }

        if (! str_contains(strtolower($organisation->name), 'insucare')) {
            Log::info('Insucare: skipped — organisation is not insucare', [
                'loan_id'   => $loan->id,
                'org_name'  => $organisation->name,
            ]);
            return;
        }

        $payload = [
            'phone_number'        => $user->phone_number,
            'email'               => $user->email,
            'bank_account_name'   => $disbursement->bank_account_name,
            'bank_account_number' => $disbursement->bank_account_number,
            'amount_requested'    => (float) $loan->amount_requested,
            'transfer_reference'  => $disbursement->provider_reference,
        ];

        Log::info('Insucare: sending subscription update', [
            'loan_id'         => $loan->id,
            'disbursement_id' => $disbursement->id,
            'payload'         => $payload,
        ]);

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => config('services.insucare.secret_key'),
                'Accept'    => 'application/json',
            ])->post(config('services.insucare.base_url') . '/api/insucare/subscription/update', $payload);

            if ($response->successful()) {
                Log::info('Insucare: subscription update successful', [
                    'loan_id'  => $loan->id,
                    'status'   => $response->status(),
                    'response' => $response->body(),
                ]);
            } else {
                Log::error('Insucare: subscription update returned error', [
                    'loan_id'         => $loan->id,
                    'disbursement_id' => $disbursement->id,
                    'status'          => $response->status(),
                    'response'        => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Insucare: subscription update exception', [
                'loan_id'         => $loan->id,
                'disbursement_id' => $disbursement->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
