<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'amount_requested',
        'interest_rate',
        'total_interest',
        'total_repayable',
        'monthly_installment',
        'term_months',
        'status',
        'application_reason',
        'approved_at',
        'rejected_at',
        'approved_by',
        'rejected_by',
        'review_notes',
        'risk_grade',
    ];

    protected $casts = [
        'amount_requested' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'total_interest' => 'decimal:2',
        'total_repayable' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }

    public function repaymentSchedules()
    {
        return $this->hasMany(RepaymentSchedule::class);
    }

    public function disbursement()
    {
        return $this->hasOne(LoanDisbursement::class);
    }
}
