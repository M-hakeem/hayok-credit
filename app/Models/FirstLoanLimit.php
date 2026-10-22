<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirstLoanLimit extends Model
{
    protected $fillable = ['id', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
