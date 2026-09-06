<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RepaymentSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'principal_amount',
        'interest_amount',
        'penalty_amount',
        'total_due',
        'amount_paid',
        'balance_due',
        'status',
        'paid_at',
        'retry_count',
        'last_attempt_at',
        'next_attempt_at',
        'failure_reason',
    ];

    protected $casts = [
        'due_date' => 'date',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
        'total_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'paid_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }
}
