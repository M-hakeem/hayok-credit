<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletDeposit extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'reference',
        'amount',
        'description',
        'status',
        'wallet_transaction_id',
        'paystack_transaction_id',
        'channel',
        'gateway_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
