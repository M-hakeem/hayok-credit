<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'balance',
        'status',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function credit(float $amount, ?string $description = null, ?string $reference = null): WalletTransaction
    {
        return $this->applyTransaction('credit', $amount, $description, $reference);
    }

    public function debit(float $amount, ?string $description = null, ?string $reference = null): WalletTransaction
    {
        return $this->applyTransaction('debit', $amount, $description, $reference);
    }

    protected function applyTransaction(string $type, float $amount, ?string $description, ?string $reference): WalletTransaction
    {
        return DB::transaction(function () use ($type, $amount, $description, $reference) {
            $this->refresh();

            if ($type === 'debit' && $amount > $this->balance) {
                throw new \RuntimeException('Insufficient wallet balance.');
            }

            $balanceBefore = $this->balance;
            $balanceAfter = $type === 'credit'
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            $this->update(['balance' => $balanceAfter]);

            $transaction = $this->transactions()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference' => $reference,
                'status' => 'success',
            ]);

            return $transaction;
        });
    }
}
