<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisbursementSetting extends Model
{
    protected $fillable = ['id', 'debit_account_number'];

    protected function casts(): array
    {
        return [
            'debit_account_number' => \App\Casts\Encrypted::class,
        ];
    }
}
