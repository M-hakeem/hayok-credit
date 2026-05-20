<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanDisbursement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'user_id',
        'amount',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'bank_code',
        'status',
        'transaction_reference',
        'disbursed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function markDisbursed(?string $transactionReference = null): void
    {
        $this->update([
            'status' => 'disbursed',
            'transaction_reference' => $transactionReference,
            'disbursed_at' => now(),
        ]);
    }
}
