<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAuthorization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'paystack_customer_code', 'authorization_code', 'authorization_code_hash', 'signature', 'email',
        'card_type', 'brand', 'last4', 'exp_month', 'exp_year', 'bank', 'country_code',
        'channel', 'reusable', 'status', 'metadata',
    ];

    protected $hidden = ['authorization_code', 'signature'];

    protected function casts(): array
    {
        return [
            'authorization_code' => Encrypted::class,
            'signature' => Encrypted::class,
            'reusable' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }

    public function getMaskedAccountAttribute(): array
    {
        return [
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'status' => $this->status,
        ];
    }

    public function isUsable(): bool
    {
        return $this->reusable && $this->status === 'active' && filled($this->authorization_code);
    }
}