<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Organisation;
use App\Models\User;

class DashboardController extends Controller
{
    public function stats()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_users' => User::count(),
                'active_partners' => Organisation::where('status', 'active')->count(),
                'total_loans' => Loan::count(),
                'active_loans' => Loan::where('status', 'active')->count(),
                'loans_disbursed' => LoanDisbursement::where('status', 'disbursed')->count(),
            ],
        ]);
    }
}
