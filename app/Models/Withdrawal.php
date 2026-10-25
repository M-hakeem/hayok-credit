<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Withdrawal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'wallet_id',
        'amount',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'bank_code',
        'status',
        'transaction_reference',
        'paystack_reference',
        'paystack_recipient_code',
        'transfer_code',
        'failure_reason',
        'gateway_response',
        'processed_at',
    ];

    protected $hidden = ['bank_account_number'];

    protected $casts = [
        'amount' => 'decimal:2',
        'bank_account_number' => \App\Casts\Encrypted::class,
        'gateway_response' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function markProcessed(?string $transactionReference = null): void
    {
        $this->update([
            'status' => 'processed',
            'transaction_reference' => $transactionReference,
            'processed_at' => now(),
        ]);
    }
}
