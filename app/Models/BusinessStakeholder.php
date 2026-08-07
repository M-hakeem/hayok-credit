<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessStakeholder extends Model
{
    use HasFactory,softdeletes;

    protected $fillable = [
        'full_name',
        'date_of_birth',
        'gender',
        'nationality',
        'identification_type',
        'identification_number',
        'identification_expires_at',
        'residential_address',
        'shareholding_percentage',
        'role',
        'is_pep',
    ];

    protected function casts(): array
    {
        return [
            'identification_number' => 'encrypted',
            'identification_expires_at' => 'date',
            'shareholding_percentage' => 'decimal:2',
            'is_pep' => 'boolean',
        ];
    }

    public function application()
    {
        return $this->belongsTo(BusinessLoanApplication::class, 'business_loan_application_id');
    }

    public function documents()
    {
        return $this->hasMany(BusinessLoanDocument::class);
    }
}
