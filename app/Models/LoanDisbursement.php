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
        'disbursed_at',
        'paystack_recipient_code',
        'provider',
        'provider_reference',
        'transfer_code',
        'failure_reason',
        'gateway_response',
        'metadata',
    ];

    protected $hidden = ['bank_account_number'];

    protected $casts = [
        'amount' => 'decimal:2',
            'bank_account_number' => \App\Casts\Encrypted::class,
        'disbursed_at' => 'datetime',
        'gateway_response' => 'array',
        'metadata' => 'array',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function markDisbursed(): void
    {
        $this->update([
            'status' => 'disbursed',
            'disbursed_at' => now(),
        ]);
    }
}
