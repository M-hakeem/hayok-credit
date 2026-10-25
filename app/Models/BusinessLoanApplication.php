<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessLoanApplication extends Model
{
    use HasFactory,softdeletes;

    protected $fillable = [
        'reference',
        'business_profile',
        'payout_account',
        'account_owner',
        'loan_details',
        'status',
        'risk_rating',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'business_user_id',
        'loan_id',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'business_profile' => 'array',
            'payout_account' => 'encrypted:array',
            'account_owner' => 'encrypted:array',
            'loan_details' => 'array',
            'reviewed_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    public function stakeholders()
    {
        return $this->hasMany(BusinessStakeholder::class);
    }

    public function documents()
    {
        return $this->hasMany(BusinessLoanDocument::class);
    }

    public function businessUser()
    {
        return $this->belongsTo(User::class, 'business_user_id');
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
