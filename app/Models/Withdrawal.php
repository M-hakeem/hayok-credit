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
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
