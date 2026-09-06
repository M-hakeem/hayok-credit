<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'user_id',
        'repayment_schedule_id',
        'due_date',
        'amount_due',
        'amount_paid',
        'paid_at',
        'status',
        'payment_reference',
        'payment_authorization_id',
        'provider',
        'provider_reference',
        'provider_transaction_id',
        'amount_minor',
        'failure_reason',
        'last_attempt_at',
        'next_retry_at',
        'attempt_count',
        'gateway_response',
        'metadata',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'gateway_response' => 'array',
        'metadata' => 'array',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function repaymentSchedule()
    {
        return $this->belongsTo(RepaymentSchedule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentAuthorization()
    {
        return $this->belongsTo(PaymentAuthorization::class);
    }
}
