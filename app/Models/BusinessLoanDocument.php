<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessLoanDocument extends Model
{
    use HasFactory,softdeletes;

    protected $fillable = [
        'business_stakeholder_id',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'expires_at',
        'status',
        'rejection_reason',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function application()
    {
        return $this->belongsTo(BusinessLoanApplication::class, 'business_loan_application_id');
    }

    public function stakeholder()
    {
        return $this->belongsTo(BusinessStakeholder::class, 'business_stakeholder_id');
    }
}
