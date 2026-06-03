<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\RepaymentSchedule;
use Dedoc\Scramble\Attributes\BodyParameter;
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

        return response()->json([
            'status' => 'success',
            'data' => $disbursements,
        ]);
    }

    #[BodyParameter('transaction_reference', type: 'string', required: false, description: 'Bank transfer reference (max 255 chars)')]
    public function disburse(Request $request, $id)
    {
        $request->validate([
            'transaction_reference' => 'nullable|string|max:255',
        ]);

        $disbursement = LoanDisbursement::with('loan.user.organisation')
            ->findOrFail($id);

        if ($disbursement->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'This disbursement has already been processed.',
            ], 422);
        }

        $loan = $disbursement->loan;

        if ($loan->status !== 'approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan must be approved before it can be disbursed.',
            ], 422);
        }

        $disbursement->update([
            'status' => 'disbursed',
            'transaction_reference' => $request->transaction_reference,
            'disbursed_at' => now(),
        ]);

        $disbursement->refresh();

        // Generate repayment schedule starting from disbursement date if not already created
        if ($loan->repaymentSchedules()->count() === 0) {
            $term = (int) $loan->term_months;
            $principalPerInstallment = round($loan->amount_requested / $term, 2);
            $interestPerInstallment = round($loan->total_interest / $term, 2);

            for ($installment = 1; $installment <= $term; $installment++) {
                $principal = $installment === $term
                    ? round($loan->amount_requested - $principalPerInstallment * ($term - 1), 2)
                    : $principalPerInstallment;
                $interest = $installment === $term
                    ? round($loan->total_interest - $interestPerInstallment * ($term - 1), 2)
                    : $interestPerInstallment;
                $totalDue = round($principal + $interest, 2);

                RepaymentSchedule::create([
                    'loan_id' => $loan->id,
                    'installment_number' => $installment,
                    'due_date' => Carbon::parse($disbursement->disbursed_at)->addMonths($installment)->toDateString(),
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

        $this->notifyInsucare($disbursement, $loan);

        return response()->json([
            'status' => 'success',
            'message' => 'Loan disbursement marked as disbursed.',
            'data' => $disbursement,
        ]);
    }

    private function notifyInsucare(LoanDisbursement $disbursement, Loan $loan): void
    {
        $organisation = $loan->user->organisation;

        if (! $organisation || strtolower($organisation->name) !== 'insucare') {
            return;
        }

        try {
            $user = $loan->user;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.insucare.secret_key'),
                'Accept' => 'application/json',
            ])->post(config('services.insucare.base_url') . '/api/insucare/subscription/update', [
                'phone_number'       => $user->phone_number,
                'email'              => $user->email,
                'bank_account_name'  => $disbursement->bank_account_name,
                'bank_account_number'=> $disbursement->bank_account_number,
                'amount_requested'   => (float) $loan->amount_requested,
                'transfer_reference' => $disbursement->transaction_reference,
            ]);

            if ($response->failed()) {
                Log::error('Insucare subscription update returned error', [
                    'loan_id'        => $loan->id,
                    'disbursement_id'=> $disbursement->id,
                    'status'         => $response->status(),
                    'response'       => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Insucare subscription update failed', [
                'loan_id'        => $loan->id,
                'disbursement_id'=> $disbursement->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
