<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'employment_information',
        'occupation',
        'educational_details',
        'income',
        'bank_statement_path',
        'verification_status',
        'rejection_reason',
    ];

    protected $casts = [
        'income' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
