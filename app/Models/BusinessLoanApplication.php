<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessLoanApplication extends Model
{
    use HasFactory,softdeletes;

    protected $fillable = [
        'reference',
        'business_profile',
        'account_owner',
        'loan_details',
        'status',
        'risk_rating',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'business_profile' => 'array',
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
}
