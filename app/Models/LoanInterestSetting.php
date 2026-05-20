<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanInterestSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'interest_rate',
        'tenure_months',
        'active',
        'notes',
    ];

    protected $casts = [
        'interest_rate' => 'decimal:2',
        'active' => 'boolean',
        'tenure_months' => 'integer',
    ];

    public static function currentRate(): float
    {
        $setting = self::where('active', true)->latest('updated_at')->first();

        return $setting ? (float) $setting->interest_rate : 0.0;
    }

    public static function rateForTenure(int $months): float
    {
        $setting = self::where('active', true)
            ->where('tenure_months', $months)
            ->latest('updated_at')
            ->first();

        if (! $setting) {
            $setting = self::where('active', true)
                ->whereNull('tenure_months')
                ->latest('updated_at')
                ->first();
        }

        return $setting ? (float) $setting->interest_rate : 0.0;
    }
}
